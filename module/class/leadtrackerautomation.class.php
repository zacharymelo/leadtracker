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
 *  \brief      Evaluates automation conditions and advances fk_opp_status.
 */

/**
 *  LeadtrackerAutomation
 *
 *  Called from the trigger handler. Evaluates configured conditions against
 *  native linked data and writes llx_projet.fk_opp_status when a later stage
 *  is satisfied. Uses OR logic: the first satisfied condition wins and stops.
 *
 *  Respects the category flag filter configured in the module settings —
 *  projects that don't pass the filter are never auto-advanced.
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
	 *
	 *  @param  int  $projectId
	 *  @return bool  True if the stage was advanced
	 */
	public function maybeAdvance($projectId)
	{
		$projectId = (int) $projectId;

		// Apply the category flag filter before doing anything.
		if (!function_exists('leadtrackerProjectPassesFilter')) {
			dol_include_once('/leadtracker/lib/leadtracker.lib.php');
		}
		if (function_exists('leadtrackerProjectPassesFilter')
			&& !leadtrackerProjectPassesFilter($projectId, $this->db)) {
			return false;
		}

		// Load project
		$sql = "SELECT rowid, fk_opp_status, usage_opportunity FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".$projectId
			." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if (!$res || !($proj = $this->db->fetch_object($res))) {
			return false;
		}

		// Only auto-advance projects with the native "Follow opportunity" flag enabled.
		if (!(int) $proj->usage_opportunity) {
			return false;
		}

		$currentFkStatus = (int) $proj->fk_opp_status;

		// Load active pipeline stages
		$stages = $this->loadStages();
		if (empty($stages)) {
			return false;
		}

		// Find the current stage position
		$currentPos = null;
		foreach ($stages as $stage) {
			if ((int) $stage->rowid === $currentFkStatus) {
				$currentPos = (int) $stage->position;
				break;
			}
		}
		if ($currentPos === null) {
			return false;
		}

		// Evaluate each later stage in position order
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
				if ($this->evalCondition($cond->condition_type, $projectId)) {
					$anyPassed = true;
					break;
				}
			}

			if ($anyPassed) {
				return $this->writeStage($projectId, (int) $stage->rowid);
			}
		}

		return false;
	}

	/**
	 *  Evaluate a single condition type against the project's linked data.
	 *
	 *  @param  string  $conditionType
	 *  @param  int     $projectId
	 *  @return bool
	 */
	private function evalCondition($conditionType, $projectId)
	{
		switch ($conditionType) {
			case 'has_outbound_contact':
				return $this->hasOutboundContact($projectId);
			case 'has_proposal':
				return $this->hasProposal($projectId, 1);
			case 'has_signed_proposal':
				return $this->hasProposal($projectId, 2);
			case 'has_order':
				return $this->hasOrder($projectId);
			case 'has_invoice':
				return $this->hasInvoice($projectId);
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
	 *  @param  int  $stageId  llx_c_lead_status.rowid
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
	 *  At least one outbound call/email/meeting logged for the project.
	 *  Note: actioncomm uses fk_project (not fk_projet).
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function hasOutboundContact($projectId)
	{
		$codes = array("'AC_TEL'", "'AC_EMAIL'", "'AC_RDV'");
		$sql = "SELECT COUNT(rowid) as cnt FROM ".MAIN_DB_PREFIX."actioncomm"
			." WHERE fk_project = ".(int) $projectId
			." AND code IN (".implode(',', $codes).")"
			." AND type != 'systemauto'"
			." AND entity IN (".getEntity('actioncomm').")";
		$res = $this->db->query($sql);
		if (!$res) {
			return false;
		}
		$obj = $this->db->fetch_object($res);
		return $obj && (int) $obj->cnt > 0;
	}

	/**
	 *  At least one proposal linked to the project with status >= $minStatus.
	 *
	 *  @param  int  $projectId
	 *  @param  int  $minStatus  1=validated, 2=signed
	 *  @return bool
	 */
	private function hasProposal($projectId, $minStatus)
	{
		return $this->hasLinkedDoc('propal', $projectId, $minStatus);
	}

	/**
	 *  At least one validated customer order linked to the project.
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function hasOrder($projectId)
	{
		return $this->hasLinkedDoc('commande', $projectId, 1);
	}

	/**
	 *  At least one validated customer invoice linked to the project.
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function hasInvoice($projectId)
	{
		return $this->hasLinkedDoc('facture', $projectId, 1);
	}

	/**
	 *  Check BOTH fk_projet and llx_element_element for a linked document.
	 *
	 *  @param  string  $table      Table base name (without prefix)
	 *  @param  int     $projectId
	 *  @param  int     $minStatus  Minimum fk_statut value required
	 *  @return bool
	 */
	private function hasLinkedDoc($table, $projectId, $minStatus)
	{
		$dbTable   = MAIN_DB_PREFIX.$table;
		$eeTable   = MAIN_DB_PREFIX."element_element";
		$minStatus = (int) $minStatus;
		$projectId = (int) $projectId;
		$tEsc      = $this->db->escape($table);

		// Method 1: direct fk_projet column
		$sql1 = "SELECT COUNT(rowid) as cnt FROM ".$dbTable
			." WHERE fk_projet = ".$projectId
			." AND fk_statut >= ".$minStatus
			." AND entity IN (".getEntity($table).")";
		$res = $this->db->query($sql1);
		if ($res) {
			$obj = $this->db->fetch_object($res);
			if ($obj && (int) $obj->cnt > 0) {
				return true;
			}
		}

		// Method 2: element_element join (links added after document creation)
		$sql2 = "SELECT COUNT(d.rowid) as cnt"
			." FROM ".$dbTable." d"
			." INNER JOIN ".$eeTable." ee ON ("
			."  (ee.fk_source = ".$projectId." AND ee.sourcetype = 'project'"
			."   AND ee.fk_target = d.rowid AND ee.targettype = '".$tEsc."')"
			."  OR"
			."  (ee.fk_target = ".$projectId." AND ee.targettype = 'project'"
			."   AND ee.fk_source = d.rowid AND ee.sourcetype = '".$tEsc."')"
			." )"
			." WHERE d.fk_statut >= ".$minStatus
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
	 *  Write the new stage to llx_projet.
	 *
	 *  @param  int  $projectId
	 *  @param  int  $newStatusId  llx_c_lead_status.rowid
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
