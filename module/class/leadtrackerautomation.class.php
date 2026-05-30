<?php
/* Copyright (C) 2026 Lead Tracker contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       class/leadtrackerautomation.class.php
 *  \ingroup    leadtracker
 *  \brief      Evaluates automation conditions and keeps pipeline data in sync.
 */

/**
 *  LeadtrackerAutomation
 *
 *  Called from the trigger handler on every relevant native event. Evaluates
 *  configured conditions against linked data and advances fk_opp_status when a
 *  later stage is satisfied (OR logic). Also recalculates opp_amount and
 *  opp_percent on every call according to the module configuration, regardless
 *  of whether the stage advances.
 *
 *  Amount (LEADTRACKER_AMOUNT_MODE = 'auto') follows an LTV hierarchy:
 *    1. Sum of validated invoices    — closest to real money
 *    2. Sum of validated orders      — committed spend
 *    3. Sum of accepted proposals    — confirmed intent
 *    4. Average of open proposals    — estimated pipeline value
 *
 *  Percent (LEADTRACKER_PERCENT_MODE = 'stage_default') is read from the
 *  c_lead_status.percent column for the current stage.
 */
class LeadtrackerAutomation
{
	/** @var DoliDB */
	public $db;

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Evaluate stages after the current one and advance if any condition passes.
	 *  Always recalculates project values (amount, percent) after the stage check.
	 *
	 *  @param  int    $projectId
	 *  @param  array  $forcedEvidence  Condition types pre-satisfied by the trigger
	 *                                  event itself (e.g. has_outbound_contact for
	 *                                  PROPAL_SENTBYMAIL). Merged with live checks.
	 *  @return bool  True if the stage was advanced
	 */
	public function maybeAdvance($projectId, $forcedEvidence = array())
	{
		$projectId = (int) $projectId;

		// Apply category flag filter.
		if (!function_exists('leadtrackerProjectPassesFilter')) {
			dol_include_once('/leadtracker/lib/leadtracker.lib.php');
		}
		if (function_exists('leadtrackerProjectPassesFilter')
			&& !leadtrackerProjectPassesFilter($projectId, $this->db)) {
			return false;
		}

		// Load project.
		$sql = "SELECT rowid, fk_opp_status, usage_opportunity FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".$projectId
			." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if (!$res || !($proj = $this->db->fetch_object($res))) {
			return false;
		}

		// Only process projects with "Follow opportunity" enabled.
		if (!(int) $proj->usage_opportunity) {
			return false;
		}

		$currentFkStatus = (int) $proj->fk_opp_status;

		$stages = $this->loadStages();
		if (empty($stages)) {
			return false;
		}

		// Find current stage position (needed for advancement, not for value updates).
		$currentPos = null;
		foreach ($stages as $stage) {
			if ((int) $stage->rowid === $currentFkStatus) {
				$currentPos = (int) $stage->position;
				break;
			}
		}

		// Evaluate stages ahead of current and advance to the first one that passes.
		// Skipped entirely when position is unknown (no stage set yet) — value
		// updates still run below so amount is always kept fresh.
		$newStatusId = null;
		if ($currentPos !== null) {
			foreach ($stages as $stage) {
				if ((int) $stage->position <= $currentPos) {
					continue;
				}

				$conditions = $this->loadConditions((int) $stage->rowid);
				if (empty($conditions)) {
					continue;
				}

				$anyPassed = false;
				foreach ($conditions as $cond) {
					if ($this->evalCondition($cond->condition_type, $projectId, $forcedEvidence)) {
						$anyPassed = true;
						break;
					}
				}

				if ($anyPassed) {
					$this->writeStage($projectId, (int) $stage->rowid);
					$newStatusId = (int) $stage->rowid;
					break;
				}
			}
		}

		// Always recalculate project values regardless of whether the stage advanced
		// or whether a current stage was found — a new document always changes the
		// LTV picture and should be reflected immediately.
		$effectiveStatusId = ($newStatusId !== null) ? $newStatusId : $currentFkStatus;
		$this->updateProjectValues($projectId, $effectiveStatusId);

		return ($newStatusId !== null);
	}

	// -------------------------------------------------------------------------
	// Project value updates (amount + percent)
	// -------------------------------------------------------------------------

	/**
	 *  Recalculate opp_amount and/or opp_percent according to module config and
	 *  write to projet.
	 *
	 *  @param  int  $projectId
	 *  @param  int  $statusId   Current (or new) fk_opp_status rowid
	 *  @return bool
	 */
	private function updateProjectValues($projectId, $statusId)
	{
		// Respect per-project auto-sync override — when OFF the user owns these values.
		if (!$this->isAutoSyncEnabled($projectId)) {
			return true;
		}

		$updates = array();

		// Amount.
		$amountMode = getDolGlobalString('LEADTRACKER_AMOUNT_MODE', 'manual');
		if ($amountMode === 'auto') {
			$amount = $this->calculateLtvAmount($projectId);
			if ($amount !== null && $amount >= 0) {
				$updates[] = "opp_amount = ".price2num($amount);
			}
		}

		// Percent.
		$percentMode = getDolGlobalString('LEADTRACKER_PERCENT_MODE', 'manual');
		if ($percentMode === 'stage_default' && $statusId > 0) {
			$percent = $this->getStagePercent($statusId);
			if ($percent !== null) {
				$updates[] = "opp_percent = ".(float) $percent;
			}
		}

		if (empty($updates)) {
			return true;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."projet"
			." SET ".implode(', ', $updates)
			." WHERE rowid = ".(int) $projectId
			." AND entity IN (".getEntity('projet').")";
		return (bool) $this->db->query($sql);
	}

	/**
	 *  Calculate the project's LTV-based opportunity amount.
	 *
	 *  Priority order (highest certainty wins):
	 *    1. Sum of validated/paid invoices  — real money billed
	 *    2. Sum of validated orders         — committed spend
	 *    3. Sum of accepted proposals       — confirmed intent (fk_statut = 2 only)
	 *    4. Average of open proposals       — estimated pipeline value
	 *    5. Numeric project extrafield   — intake estimate (configurable)
	 *
	 *  New documents at any level cause the relevant tier to recalculate.
	 *  As invoices accumulate over time they sum for LTV tracking.
	 *
	 *  @param  int  $projectId
	 *  @return float  Amount (0 if no linked documents)
	 */
	public function calculateLtvAmount($projectId)
	{
		// 1. Invoices: validated (1) or paid (2). Cancelled = -1, excluded by >= 1.
		$invoiced = $this->sumLinkedDocTotals('facture', $projectId, 'fk_statut >= 1');
		if ($invoiced > 0) {
			return $invoiced;
		}

		// 2. Orders: validated (1), in progress (2), closed (3). Cancelled = -1.
		$ordered = $this->sumLinkedDocTotals('commande', $projectId, 'fk_statut >= 1');
		if ($ordered > 0) {
			return $ordered;
		}

		// 3. Accepted proposals: exactly fk_statut = 2. Refused = 3, excluded deliberately.
		$signed = $this->sumLinkedDocTotals('propal', $projectId, 'fk_statut = 2');
		if ($signed > 0) {
			return $signed;
		}

		// 4. Average of open proposals: draft (0) or validated/sent (1).
		$openAvg = $this->avgLinkedDocTotals('propal', $projectId, 'fk_statut IN (0, 1)');
		if ($openAvg > 0) {
			return $openAvg;
		}

		// 5. Extrafield fallback — initial estimate captured at lead intake
		//    (e.g. from an email collector). Only used when no documents exist yet.
		$field = trim(getDolGlobalString('LEADTRACKER_AMOUNT_EXTRAFIELD', ''));
		if ($field !== '') {
			$estimate = $this->getProjectExtrafield($projectId, $field);
			if ($estimate > 0) {
				return $estimate;
			}
		}

		return 0;
	}

	/**
	 *  Read a single numeric extrafield value from projet_extrafields.
	 *
	 *  @param  int     $projectId
	 *  @param  string  $fieldName  Attribute code (alphanumeric + underscore only)
	 *  @return float
	 */
	private function getProjectExtrafield($projectId, $fieldName)
	{
		// Sanitise to prevent injection — only allow safe column name characters.
		$fieldName = preg_replace('/[^a-zA-Z0-9_]/', '', $fieldName);
		if ($fieldName === '') {
			return 0;
		}
		$sql = "SELECT ".$fieldName." as val FROM ".MAIN_DB_PREFIX."projet_extrafields"
			." WHERE fk_object = ".(int) $projectId;
		$res = $this->db->query($sql);
		if ($res && ($obj = $this->db->fetch_object($res)) && $obj->val !== null && $obj->val !== '') {
			// Strip currency symbols and thousand-separators (e.g. "$15,698 CAD" → "15698")
			// then use price2num() for locale-aware decimal handling before casting to float.
			$clean = preg_replace('/[^\d.,]/', '', (string) $obj->val);
			return (float) price2num($clean);
		}
		return 0;
	}

	/**
	 *  Sum total_ttc of linked documents matching a status clause.
	 *
	 *  Checks both the direct fk_projet column and element_element links.
	 *  $statusWhere is a pre-constructed SQL fragment — only called internally
	 *  with hardcoded safe values.
	 *
	 *  @param  string  $table        Table base name (without prefix)
	 *  @param  int     $projectId
	 *  @param  string  $statusWhere  Safe SQL WHERE fragment for fk_statut
	 *  @return float
	 */
	private function sumLinkedDocTotals($table, $projectId, $statusWhere)
	{
		$ids = $this->collectLinkedDocIds($table, $projectId, $statusWhere);
		if (empty($ids)) {
			return 0;
		}
		$sql = "SELECT COALESCE(SUM(total_ttc), 0) as total"
			." FROM ".MAIN_DB_PREFIX.$table
			." WHERE rowid IN (".implode(',', $ids).")";
		$res = $this->db->query($sql);
		if ($res && $obj = $this->db->fetch_object($res)) {
			return (float) $obj->total;
		}
		return 0;
	}

	/**
	 *  Average total_ttc of linked documents matching a status clause.
	 *
	 *  @param  string  $table
	 *  @param  int     $projectId
	 *  @param  string  $statusWhere
	 *  @return float
	 */
	private function avgLinkedDocTotals($table, $projectId, $statusWhere)
	{
		$ids = $this->collectLinkedDocIds($table, $projectId, $statusWhere);
		if (empty($ids)) {
			return 0;
		}
		$sql = "SELECT COALESCE(AVG(total_ttc), 0) as avg_total"
			." FROM ".MAIN_DB_PREFIX.$table
			." WHERE rowid IN (".implode(',', $ids).")";
		$res = $this->db->query($sql);
		if ($res && $obj = $this->db->fetch_object($res)) {
			return (float) $obj->avg_total;
		}
		return 0;
	}

	/**
	 *  Collect unique document rowids linked to the project via both fk_projet
	 *  and element_element, filtered by a status clause.
	 *
	 *  @param  string  $table        Table base name
	 *  @param  int     $projectId
	 *  @param  string  $statusWhere  Safe SQL WHERE fragment for fk_statut
	 *  @return int[]                  Unique rowids
	 */
	private function collectLinkedDocIds($table, $projectId, $statusWhere)
	{
		$dbTable = MAIN_DB_PREFIX.$table;
		$eeTable = MAIN_DB_PREFIX."element_element";
		$pid     = (int) $projectId;
		$tEsc    = $this->db->escape($table);
		$ids     = array();

		// Method 1: direct fk_projet column.
		$sql1 = "SELECT rowid FROM ".$dbTable
			." WHERE fk_projet = ".$pid
			." AND ".$statusWhere
			." AND entity IN (".getEntity($table).")";
		$res = $this->db->query($sql1);
		if ($res) {
			while ($r = $this->db->fetch_object($res)) {
				$ids[] = (int) $r->rowid;
			}
		}

		// Method 2: element_element links.
		$sql2 = "SELECT d.rowid FROM ".$dbTable." d"
			." INNER JOIN ".$eeTable." ee ON ("
			."  (ee.fk_source = ".$pid." AND ee.sourcetype = 'project'"
			."   AND ee.fk_target = d.rowid AND ee.targettype = '".$tEsc."')"
			."  OR"
			."  (ee.fk_target = ".$pid." AND ee.targettype = 'project'"
			."   AND ee.fk_source = d.rowid AND ee.sourcetype = '".$tEsc."')"
			." )"
			." WHERE d.".$statusWhere
			." AND d.entity IN (".getEntity($table).")";
		$res2 = $this->db->query($sql2);
		if ($res2) {
			while ($r = $this->db->fetch_object($res2)) {
				$ids[] = (int) $r->rowid;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 *  Read the default percentage for a stage from c_lead_status.percent.
	 *
	 *  @param  int  $stageId  c_lead_status.rowid
	 *  @return float|null      Null if not found
	 */
	private function getStagePercent($stageId)
	{
		$sql = "SELECT percent FROM ".MAIN_DB_PREFIX."c_lead_status"
			." WHERE rowid = ".(int) $stageId;
		$res = $this->db->query($sql);
		if ($res && $obj = $this->db->fetch_object($res)) {
			return (float) $obj->percent;
		}
		return null;
	}

	/**
	 *  Check whether automatic value sync is enabled for this project.
	 *  Returns true (enabled) if no override row exists — auto-sync is ON by default.
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function isAutoSyncEnabled($projectId)
	{
		$sql = "SELECT auto_sync FROM ".MAIN_DB_PREFIX."leadtracker_project"
			." WHERE fk_project = ".(int) $projectId;
		$res = $this->db->query($sql);
		if ($res && ($obj = $this->db->fetch_object($res))) {
			return (bool) $obj->auto_sync;
		}
		return true; // no override row → auto-sync on
	}

	/**
	 *  Recalculate project values (amount + percent) without attempting stage
	 *  advancement. Called when the project record itself is saved.
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	public function recalculateValues($projectId)
	{
		$projectId = (int) $projectId;

		// Apply category flag filter.
		if (!function_exists('leadtrackerProjectPassesFilter')) {
			dol_include_once('/leadtracker/lib/leadtracker.lib.php');
		}
		if (function_exists('leadtrackerProjectPassesFilter')
			&& !leadtrackerProjectPassesFilter($projectId, $this->db)) {
			return false;
		}

		// Load project — check usage_opportunity and current stage.
		$sql = "SELECT fk_opp_status, usage_opportunity FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".$projectId
			." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if (!$res || !($obj = $this->db->fetch_object($res))) {
			return false;
		}
		if (!(int) $obj->usage_opportunity) {
			return false;
		}

		return $this->updateProjectValues($projectId, (int) $obj->fk_opp_status);
	}

	// -------------------------------------------------------------------------
	// Stage advancement helpers
	// -------------------------------------------------------------------------

	/**
	 *  Evaluate a single condition type against the project's linked data.
	 *  forcedEvidence values (passed from the trigger) short-circuit the live DB
	 *  check — used when the trigger event itself proves the condition is met.
	 *
	 *  @param  string  $conditionType
	 *  @param  int     $projectId
	 *  @param  array   $forcedEvidence  Map of condition_type => bool
	 *  @return bool
	 */
	private function evalCondition($conditionType, $projectId, $forcedEvidence = array())
	{
		if (!empty($forcedEvidence[$conditionType])) {
			return true;
		}

		switch ($conditionType) {
			case 'has_outbound_contact':
				return $this->hasOutboundContact($projectId);
			case 'has_proposal':
				return $this->hasLinkedDoc('propal', $projectId, 1);
			case 'has_signed_proposal':
				return $this->hasLinkedDoc('propal', $projectId, 2, true);
			case 'has_order':
				return $this->hasLinkedDoc('commande', $projectId, 1);
			case 'has_invoice':
				return $this->hasLinkedDoc('facture', $projectId, 1);
			case 'manual_only':
				return false;
			default:
				return false;
		}
	}

	/**
	 *  Load active pipeline stages ordered by position.
	 *
	 *  @return array
	 */
	private function loadStages()
	{
		$sql = "SELECT rowid, code, position FROM ".MAIN_DB_PREFIX."c_lead_status"
			." WHERE active = 1 ORDER BY position ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($res)) {
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 *  Load active automation conditions for a stage.
	 *
	 *  @param  int  $stageId
	 *  @return array
	 */
	private function loadConditions($stageId)
	{
		$sql = "SELECT condition_type FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
			." WHERE fk_lead_status = ".(int) $stageId
			." AND active = 1"
			." AND entity IN (".getEntity('leadtracker_stage_config').")";
		$res = $this->db->query($sql);
		if (!$res) {
			return array();
		}
		$rows = array();
		while ($obj = $this->db->fetch_object($res)) {
			$rows[] = $obj;
		}
		return $rows;
	}

	/**
	 *  At least one outbound contact action linked to the project.
	 *
	 *  Checks both fk_project (direct FK) and fk_element/elementtype = 'project'
	 *  (the generic link Dolibarr uses for auto-created email events when the
	 *  "create event on send" agenda option is enabled from a project card).
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function hasOutboundContact($projectId)
	{
		// Do not filter by action code — Dolibarr uses different codes depending
		// on where the email/call is triggered (AC_EMAIL, AC_OTH_AUTO, etc.).
		// Exclude only systemauto events (project created, validated, etc.) which
		// have a gear icon and are never user-initiated contact.
		$pid = (int) $projectId;

		// Four ways Dolibarr can link an actioncomm to a project:
		//   1. fk_project direct FK
		//   2. fk_element / elementtype = 'project' (generic element link)
		//   3. actioncomm_resources row with element_type = 'project'
		//   4. Fallback: actioncomm.fk_soc matches the project's fk_soc — Dolibarr's
		//      "send email from project card" auto-event is stored against the company,
		//      not the project directly, and the project agenda page shows it via
		//      the shared fk_soc. This makes it detectable as contact evidence.
		$sql = "SELECT COUNT(a.rowid) as cnt"
			." FROM ".MAIN_DB_PREFIX."actioncomm a"
			." LEFT JOIN ".MAIN_DB_PREFIX."actioncomm_resources ar"
			."  ON ar.fk_actioncomm = a.rowid AND ar.element_type = 'project' AND ar.fk_element = ".$pid
			." LEFT JOIN ".MAIN_DB_PREFIX."projet p"
			."  ON p.rowid = ".$pid." AND p.fk_soc IS NOT NULL AND p.fk_soc > 0 AND a.fk_soc = p.fk_soc"
			." WHERE (a.fk_project = ".$pid
			."  OR (a.fk_element = ".$pid." AND a.elementtype = 'project')"
			."  OR ar.rowid IS NOT NULL"
			."  OR p.rowid IS NOT NULL)"
			." AND a.type != 'systemauto'"
			." AND a.entity IN (".getEntity('actioncomm').")";
		$res = $this->db->query($sql);
		if (!$res) {
			return false;
		}
		$obj = $this->db->fetch_object($res);
		return $obj && (int) $obj->cnt > 0;
	}

	/**
	 *  Check for at least one linked document meeting the status threshold.
	 *  Checks both fk_projet column and element_element.
	 *
	 *  @param  string  $table     Table base name
	 *  @param  int     $projectId
	 *  @param  int     $status    Status value
	 *  @param  bool    $exact     True = exact match; false = >= status
	 *  @return bool
	 */
	private function hasLinkedDoc($table, $projectId, $status, $exact = false)
	{
		$dbTable  = MAIN_DB_PREFIX.$table;
		$eeTable  = MAIN_DB_PREFIX."element_element";
		$status   = (int) $status;
		$pid      = (int) $projectId;
		$tEsc     = $this->db->escape($table);
		$statusOp = $exact ? " = ".$status : " >= ".$status;

		$sql1 = "SELECT COUNT(rowid) as cnt FROM ".$dbTable
			." WHERE fk_projet = ".$pid
			." AND fk_statut".$statusOp
			." AND entity IN (".getEntity($table).")";
		$res = $this->db->query($sql1);
		if ($res) {
			$obj = $this->db->fetch_object($res);
			if ($obj && (int) $obj->cnt > 0) {
				return true;
			}
		}

		$sql2 = "SELECT COUNT(d.rowid) as cnt"
			." FROM ".$dbTable." d"
			." INNER JOIN ".$eeTable." ee ON ("
			."  (ee.fk_source = ".$pid." AND ee.sourcetype = 'project'"
			."   AND ee.fk_target = d.rowid AND ee.targettype = '".$tEsc."')"
			."  OR"
			."  (ee.fk_target = ".$pid." AND ee.targettype = 'project'"
			."   AND ee.fk_source = d.rowid AND ee.sourcetype = '".$tEsc."')"
			." )"
			." WHERE d.fk_statut".$statusOp
			." AND d.entity IN (".getEntity($table).")";
		$res2 = $this->db->query($sql2);
		if ($res2) {
			$obj2 = $this->db->fetch_object($res2);
			if ($obj2 && (int) $obj2->cnt > 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 *  Write a new stage rowid to projet.
	 *
	 *  @param  int  $projectId
	 *  @param  int  $newStatusId
	 *  @return bool
	 */
	private function writeStage($projectId, $newStatusId)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."projet"
			." SET fk_opp_status = ".(int) $newStatusId
			." WHERE rowid = ".(int) $projectId
			." AND entity IN (".getEntity('projet').")";
		return (bool) $this->db->query($sql);
	}
}
