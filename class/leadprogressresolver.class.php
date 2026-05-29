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
 *  Converts a Project object (with fk_opp_status) into a normalized array of
 *  step descriptors consumed by LeadProgressRenderer. Reads only native data;
 *  never writes.
 */
class LeadProgressResolver
{
	const STATE_COMPLETE = 'complete';
	const STATE_CURRENT  = 'current';
	const STATE_PENDING  = 'pending';
	const STATE_WON      = 'won';
	const STATE_LOST     = 'lost';

	/** @var DoliDB */
	public $db;

	/** @var array Raw stage rows from llx_c_lead_status */
	public $stages = array();

	/** @var string Current stage code (derived, never from opp_status_code) */
	public $currentCode = '';

	/**
	 *  @param  DoliDB  $db  Database handler
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

		$this->currentCode = $this->currentStageCode($project, $this->stages);

		$currentPos = $this->currentStagePosition($project, $this->stages);

		$steps = array();
		foreach ($this->stages as $stage) {
			$code = $stage->code;
			$pos  = (int) $stage->position;

			if ($this->currentCode === 'LOST' && (int) $stage->rowid === (int) $project->fk_opp_status) {
				$state = self::STATE_LOST;
			} elseif ($this->currentCode === 'WON' && (int) $stage->rowid === (int) $project->fk_opp_status) {
				$state = self::STATE_WON;
			} elseif ((int) $stage->rowid === (int) $project->fk_opp_status) {
				$state = self::STATE_CURRENT;
			} elseif ($currentPos !== null && $pos < $currentPos) {
				$state = self::STATE_COMPLETE;
			} else {
				$state = self::STATE_PENDING;
			}

			// After WON or LOST, remaining stages are pending (grayed, not active)
			if (in_array($this->currentCode, array('WON', 'LOST'))
				&& $currentPos !== null && $pos > $currentPos) {
				$state = self::STATE_PENDING;
			}

			$steps[] = array(
				'key'        => $code,
				'label'      => $langs->trans($stage->label) !== $stage->label
					? $langs->trans($stage->label)
					: $stage->label,
				'state'      => $state,
				'rowid'      => (int) $stage->rowid,
				'action_url' => $this->actionUrl($code, (int) $project->id),
			);
		}

		return $steps;
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
	 *  Derive the current stage code from fk_opp_status (never opp_status_code).
	 *
	 *  @param  object  $project  Project object
	 *  @param  array   $stages   Loaded stages
	 *  @return string             Stage code or empty string
	 */
	private function currentStageCode($project, $stages)
	{
		if (empty($project->fk_opp_status)) {
			return '';
		}
		foreach ($stages as $stage) {
			if ((int) $stage->rowid === (int) $project->fk_opp_status) {
				return $stage->code;
			}
		}
		return '';
	}

	/**
	 *  Return the position value of the current stage, or null if not set.
	 *
	 *  @param  object  $project  Project object
	 *  @param  array   $stages   Loaded stages
	 *  @return int|null
	 */
	private function currentStagePosition($project, $stages)
	{
		if (empty($project->fk_opp_status)) {
			return null;
		}
		foreach ($stages as $stage) {
			if ((int) $stage->rowid === (int) $project->fk_opp_status) {
				return (int) $stage->position;
			}
		}
		return null;
	}

	/**
	 *  Default action URL for a stage code.
	 *
	 *  @param  string  $code  Stage code
	 *  @param  int     $id    Project rowid
	 *  @return string          URL (may be empty)
	 */
	private function actionUrl($code, $id)
	{
		$map = array(
			'PROSP'  => '/comm/action/card.php?action=create&actioncode=AC_TEL&fk_project=',
			'QUAL'   => '/comm/action/card.php?action=create&actioncode=AC_RDV&fk_project=',
			'PROPO'  => '/comm/propal/card.php?action=create&fk_project=',
			'NEGO'   => '',
			'WON'    => '/commande/card.php?action=create&fk_project=',
			'LOST'   => '',
		);
		if (isset($map[$code])) {
			return $map[$code] !== '' ? DOL_URL_ROOT.$map[$code].$id : '';
		}
		return '';
	}

	/**
	 *  Helper: prefer ->status, fall back to deprecated ->statut.
	 *
	 *  @param  object  $obj  Native Dolibarr object
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
