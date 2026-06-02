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
 *  \file       class/leadprogressresolver.class.php
 *  \ingroup    leadtracker
 *  \brief      Reads native pipeline data and returns a normalized steps array.
 */

/**
 *  LeadProgressResolver
 *
 *  Converts a Project object into a normalized steps array consumed by
 *  LeadProgressRenderer. Stage states are determined evidence-first: for each
 *  stage with conditions configured, the resolver checks live linked data
 *  (contacts, proposals, orders, invoices) and marks the highest qualifying
 *  stage as CURRENT. fk_opp_status acts as a floor — if the stored status is
 *  further along than what evidence supports (e.g. manually set to WON), the
 *  stored status wins. Reads only; never writes.
 */
class LeadProgressResolver
{
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_WON      = 'won';
	const STATE_LOST     = 'lost';

	// Native project lifecycle (projet.fk_statut) — orthogonal to the funnel.
	const PROJECT_DRAFT     = 0;
	const PROJECT_VALIDATED = 1;
	const PROJECT_CLOSED    = 2;

	/** @var DoliDB */
	public $db;

	/** @var array  Raw stage rows from c_lead_status */
	public $stages = array();

	/** @var string  Current stage code resolved by this instance */
	public $currentCode = '';

	/** @var int|null  Native project lifecycle status (fk_statut): 0 draft, 1 validated, 2 closed */
	public $projectStatus = null;

	/** @var array  Evidence flags keyed by condition_type — exposed for debug panel */
	public $evidence = array();

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Build the steps array for the given project.
	 *
	 *  @param  object  $project  A fetched Projet object
	 *  @return array              Normalized steps (empty on error/no stages)
	 */
	public function resolve($project)
	{
		global $langs;

		$this->stages = $this->loadStages();
		if (empty($this->stages)) {
			return array();
		}

		$stageConfigs = $this->loadStageConfigs();

		// The hook sometimes delivers a partially-loaded project object. Read both
		// fk_opp_status (funnel) and fk_statut (lifecycle) straight from the DB —
		// fk_statut is fetched from the column rather than $project->statut/->status
		// because that property name drifted across Dolibarr versions.
		if (!empty($project->id)) {
			$sql = "SELECT fk_opp_status, fk_statut FROM ".MAIN_DB_PREFIX."projet"
				." WHERE rowid = ".((int) $project->id)
				." AND entity IN (".getEntity('projet').")";
			$res = $this->db->query($sql);
			if ($res && $row = $this->db->fetch_object($res)) {
				if (empty($project->fk_opp_status)) {
					$project->fk_opp_status = (int) $row->fk_opp_status;
				}
				$this->projectStatus = (int) $row->fk_statut;
			}
		}

		// Gather live evidence from linked documents (always — the debug panel shows it).
		$this->evidence = $this->gatherEvidence((int) $project->id);

		// Lifecycle freeze: while the project is Draft or Closed, the funnel does not
		// advance on evidence. Display reflects the stored fk_opp_status only, so the
		// muted tracker matches reality. Evidence-driven progression resumes once the
		// project is Validated.
		$frozen = ($this->projectStatus === self::PROJECT_DRAFT
			|| $this->projectStatus === self::PROJECT_CLOSED);
		$evidenceForResolve = $frozen ? array() : $this->evidence;

		// Determine which stage is current (evidence wins; fk_opp_status is the floor).
		$currentRowid = $this->resolveCurrentRowid($evidenceForResolve, $stageConfigs, (int) $project->fk_opp_status);

		$currentPos = null;
		$this->currentCode = '';
		foreach ($this->stages as $stage) {
			if ((int) $stage->rowid === $currentRowid) {
				$currentPos = (int) $stage->position;
				$this->currentCode = $stage->code;
				break;
			}
		}

		// Build steps array.
		$steps = array();
		foreach ($this->stages as $stage) {
			$pos   = (int) $stage->position;
			$rowid = (int) $stage->rowid;

			if ($rowid === $currentRowid) {
				if ($stage->code === 'WON') {
					$state = self::STATE_WON;
				} elseif ($stage->code === 'LOST') {
					$state = self::STATE_LOST;
				} else {
					$state = self::STATE_CURRENT;
				}
			} elseif ($currentPos !== null && $pos < $currentPos) {
				$state = self::STATE_COMPLETE;
			} else {
				$state = self::STATE_PENDING;
			}

			$label = $langs->trans($stage->label);
			if ($label === $stage->label) {
				$label = $stage->label;
			}

			$steps[] = array(
				'key'        => $stage->code,
				'label'      => $label,
				'state'      => $state,
				'rowid'      => $rowid,
				'action_url' => $this->actionUrl($stage->code, (int) $project->id),
			);
		}

		return $steps;
	}

	/**
	 *  Determine the rowid of the current stage.
	 *
	 *  Evidence pass: find the highest-position stage whose conditions are met.
	 *  Status floor: if fk_opp_status is at an even higher position (manual
	 *  decision or terminal WON/LOST), it wins.
	 *
	 *  @param  array  $evidence      Evidence flags to evaluate (empty = frozen)
	 *  @param  array  $stageConfigs  Map of rowid => [condition_type, ...]
	 *  @param  int    $fkOppStatus   Stored fk_opp_status from projet
	 *  @return int|null               Stage rowid, or null if nothing resolved
	 */
	private function resolveCurrentRowid($evidence, $stageConfigs, $fkOppStatus)
	{
		// Evidence pass.
		$evidenceRowid = null;
		$evidencePos   = -1;

		foreach ($this->stages as $stage) {
			$rowid      = (int) $stage->rowid;
			$pos        = (int) $stage->position;
			$conditions = isset($stageConfigs[$rowid]) ? $stageConfigs[$rowid] : array();

			if (empty($conditions)) {
				continue;
			}

			foreach ($conditions as $ctype) {
				if ($ctype !== 'manual_only' && !empty($evidence[$ctype])) {
					if ($pos > $evidencePos) {
						$evidencePos   = $pos;
						$evidenceRowid = $rowid;
					}
					break;
				}
			}
		}

		// Status floor: find fk_opp_status position.
		$fkPos = -1;
		if ($fkOppStatus > 0) {
			foreach ($this->stages as $stage) {
				if ((int) $stage->rowid === $fkOppStatus) {
					$fkPos = (int) $stage->position;
					break;
				}
			}
		}

		// Take whichever is further along.
		if ($fkOppStatus > 0 && $fkPos > $evidencePos) {
			return $fkOppStatus;
		}
		if ($evidenceRowid !== null) {
			return $evidenceRowid;
		}
		return ($fkOppStatus > 0) ? $fkOppStatus : null;
	}

	/**
	 *  Gather live evidence flags for all condition types.
	 *
	 *  @param  int  $projectId
	 *  @return array  Map of condition_type => bool
	 */
	private function gatherEvidence($projectId)
	{
		return array(
			'has_outbound_contact' => $this->checkContact($projectId),
			'has_proposal'         => $this->checkLinkedDoc('propal', $projectId, 1),
			'has_signed_proposal'  => $this->checkLinkedDoc('propal', $projectId, 2, true),
			'has_order'            => $this->checkLinkedDoc('commande', $projectId, 1),
			'has_invoice'          => $this->checkLinkedDoc('facture', $projectId, 1),
		);
	}

	/**
	 *  Load all active stage configs in one query.
	 *
	 *  @return array  Map of rowid => [condition_type, ...]
	 */
	private function loadStageConfigs()
	{
		$sql = "SELECT fk_lead_status, condition_type"
			." FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
			." WHERE active = 1"
			." AND entity IN (".getEntity('leadtracker_stage_config').")";
		$res = $this->db->query($sql);
		$map = array();
		if (!$res) {
			return $map;
		}
		while ($obj = $this->db->fetch_object($res)) {
			$map[(int) $obj->fk_lead_status][] = $obj->condition_type;
		}
		return $map;
	}

	/**
	 *  Check for any outbound contact (call, email, meeting) linked to the project.
	 *
	 *  Checks both fk_project (direct FK) and fk_element/elementtype = 'project'
	 *  (the generic link Dolibarr uses for auto-created email events when the
	 *  "create event on send" agenda option is enabled from a project card).
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function checkContact($projectId)
	{
		// IMPORTANT: the actioncomm table's primary key is `id`, NOT `rowid`
		// (a Dolibarr legacy quirk). Querying a.rowid throws "Unknown column"
		// and the whole query silently returns false. Always use a.id here.
		$pid = (int) $projectId;

		// Four ways Dolibarr can link an actioncomm to a project:
		//   1. fk_project direct FK
		//   2. fk_element / elementtype = 'project' (generic element link)
		//   3. actioncomm_resources row with element_type = 'project'
		//   4. Fallback: actioncomm.fk_soc matches the project's fk_soc — covers
		//      "email sent from the company card" (AC_COMPANY_SENTBYMAIL), which is
		//      stored against the company, not the project.
		//
		// Every project also accumulates system gear-icon events (AC_PROJECT_CREATE,
		// _MODIFY, _VALIDATE, AC_ORDER_DELETE, ...) all carrying fk_project. Those are
		// NOT contact. There is no `type` column to filter them out, so we use a
		// positive code allowlist instead: real outbound contact is an email-send
		// event (code LIKE '%SENTBYMAIL') or a manually logged call/meeting/email/fax.
		$sql = "SELECT COUNT(a.id) as cnt"
			." FROM ".MAIN_DB_PREFIX."actioncomm a"
			." LEFT JOIN ".MAIN_DB_PREFIX."actioncomm_resources ar"
			."  ON ar.fk_actioncomm = a.id AND ar.element_type = 'project' AND ar.fk_element = ".$pid
			." LEFT JOIN ".MAIN_DB_PREFIX."projet p"
			."  ON p.rowid = ".$pid." AND p.fk_soc IS NOT NULL AND p.fk_soc > 0 AND a.fk_soc = p.fk_soc"
			." WHERE (a.fk_project = ".$pid
			."  OR (a.fk_element = ".$pid." AND a.elementtype = 'project')"
			."  OR ar.rowid IS NOT NULL"
			."  OR p.rowid IS NOT NULL)"
			." AND (a.code LIKE '%SENTBYMAIL' OR a.code IN ('AC_TEL', 'AC_RDV', 'AC_EMAIL', 'AC_FAX'))"
			." AND a.entity IN (".getEntity('actioncomm').")";
		$res = $this->db->query($sql);
		if (!$res) {
			return false;
		}
		$obj = $this->db->fetch_object($res);
		return $obj && (int) $obj->cnt > 0;
	}

	/**
	 *  Check for a linked document meeting the status threshold.
	 *  Checks both fk_projet column and element_element.
	 *
	 *  @param  string  $table        Table base name (without prefix)
	 *  @param  int     $projectId
	 *  @param  int     $status       Status value
	 *  @param  bool    $exact        True = match exactly; false = match >= status
	 *  @return bool
	 */
	private function checkLinkedDoc($table, $projectId, $status, $exact = false)
	{
		$dbTable  = MAIN_DB_PREFIX.$table;
		$eeTable  = MAIN_DB_PREFIX."element_element";
		$status   = (int) $status;
		$pid      = (int) $projectId;
		$tEsc     = $this->db->escape($table);
		$statusOp = $exact ? " = ".$status : " >= ".$status;

		// Method 1: direct fk_projet column.
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

		// Method 2: element_element links added after document creation.
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
	 *  Load active pipeline stages ordered by position.
	 *
	 *  @return array  Array of stdClass rows
	 */
	private function loadStages()
	{
		$sql = "SELECT rowid, code, label, position"
			." FROM ".MAIN_DB_PREFIX."c_lead_status"
			." WHERE active = 1"
			." ORDER BY position ASC";
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
	 *  Default action URL for a stage code.
	 *
	 *  @param  string  $code  Stage code
	 *  @param  int     $id    Project rowid
	 *  @return string          URL or empty string
	 */
	private function actionUrl($code, $id)
	{
		$map = array(
			'PROSP' => '/comm/action/card.php?action=create&actioncode=AC_TEL&fk_project=',
			'QUAL'  => '/comm/action/card.php?action=create&actioncode=AC_RDV&fk_project=',
			'PROPO' => '/comm/propal/card.php?action=create&fk_project=',
			'NEGO'  => '',
			'WON'   => '/commande/card.php?action=create&fk_project=',
			'LOST'  => '',
		);
		if (isset($map[$code])) {
			return $map[$code] !== '' ? DOL_URL_ROOT.$map[$code].$id : '';
		}
		return '';
	}

	/**
	 *  Helper: prefer ->status, fall back to deprecated ->statut.
	 *
	 *  @param  object  $obj
	 *  @return int
	 */
	public static function statusOf($obj)
	{
		if (property_exists($obj, 'status') && $obj->status !== null && $obj->status !== '') {
			return (int) $obj->status;
		}
		return property_exists($obj, 'statut') ? (int) $obj->statut : 0;
	}
}
