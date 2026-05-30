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
 *  \file       ajax/debug.php
 *  \ingroup    leadtracker
 *  \brief      Diagnostic endpoint for Lead Tracker. Admin-only, gated by LEADTRACKER_DEBUG.
 *
 *  Returns JSON with:
 *    - module constants
 *    - active pipeline stages
 *    - stage condition config
 *    - resolved steps for an optional project ID (GET param: project_id)
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = realpath(__FILE__);
$i = strlen($tmp) - 1;
while ($i > 0 && !$res) {
	if (file_exists(substr($tmp, 0, $i)."/main.inc.php")) {
		$res = @include substr($tmp, 0, $i)."/main.inc.php";
		break;
	}
	$i--;
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

header('Content-Type: application/json; charset=utf-8');

// Gate: must be admin AND debug mode enabled
if (!$user->admin || !getDolGlobalString('LEADTRACKER_DEBUG')) {
	http_response_code(403);
	print json_encode(array('error' => 'Forbidden'));
	exit;
}

if (!isModEnabled('leadtracker')) {
	http_response_code(503);
	print json_encode(array('error' => 'Module not enabled'));
	exit;
}

dol_include_once('/leadtracker/lib/leadtracker.lib.php');
dol_include_once('/leadtracker/class/leadprogressresolver.class.php');

$out = array();

// Module constants
$constKeys = array(
	'LEADTRACKER_FILTER_MODE',
	'LEADTRACKER_FLAG_CATEGORY_ID',
	'LEADTRACKER_DISPLAY_MODE',
	'LEADTRACKER_SKIPPED_BEHAVIOR',
	'LEADTRACKER_CLICKABLE',
	'LEADTRACKER_ACTION_LINKS',
	'LEADTRACKER_DEBUG',
	'LEADTRACKER_COLOR_COMPLETE',
	'LEADTRACKER_COLOR_CURRENT',
	'LEADTRACKER_COLOR_PENDING',
	'LEADTRACKER_COLOR_LOST',
);
$out['constants'] = array();
foreach ($constKeys as $k) {
	$out['constants'][$k] = getDolGlobalString($k);
}

// Active pipeline stages
$sqlS = "SELECT rowid, code, label, position FROM ".MAIN_DB_PREFIX."c_lead_status"
	." WHERE active = 1 ORDER BY position ASC";
$resS = $db->query($sqlS);
$out['stages'] = array();
if ($resS) {
	while ($row = $db->fetch_object($resS)) {
		$out['stages'][] = array(
			'rowid'    => (int) $row->rowid,
			'code'     => $row->code,
			'label'    => $row->label,
			'position' => (int) $row->position,
		);
	}
}

// Stage condition config
$sqlC = "SELECT fk_lead_status, condition_type, active FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
	." WHERE entity = ".(int) $conf->entity
	." ORDER BY fk_lead_status ASC, condition_type ASC";
$resC = $db->query($sqlC);
$out['stage_config'] = array();
if ($resC) {
	while ($row = $db->fetch_object($resC)) {
		$out['stage_config'][] = array(
			'fk_lead_status' => (int) $row->fk_lead_status,
			'condition_type' => $row->condition_type,
			'active'         => (int) $row->active,
		);
	}
}

// Optional: raw actioncomm rows for a project (shows fk_project vs fk_element linkage)
$projectId = GETPOSTINT('project_id');
if ($projectId > 0) {
	$pid = (int) $projectId;
	// Method 1 + 2: fk_project / fk_element direct columns
	$sqlAC = "SELECT a.rowid, a.ref, a.type, a.code, a.fk_project, a.fk_element, a.elementtype, a.label"
		." FROM ".MAIN_DB_PREFIX."actioncomm a"
		." WHERE (a.fk_project = ".$pid
		."  OR (a.fk_element = ".$pid." AND a.elementtype = 'project'))"
		." AND a.entity IN (".getEntity('actioncomm').")"
		." ORDER BY a.rowid DESC LIMIT 30";
	$resAC = $db->query($sqlAC);
	$out['actioncomms_direct'] = array();
	if ($resAC) {
		while ($row = $db->fetch_object($resAC)) {
			$out['actioncomms_direct'][] = array(
				'rowid'       => (int) $row->rowid,
				'type'        => $row->type,
				'code'        => $row->code,
				'fk_project'  => $row->fk_project,
				'fk_element'  => $row->fk_element,
				'elementtype' => $row->elementtype,
				'label'       => $row->label,
			);
		}
	}

	// Method 3: actioncomm_resources (many-to-many resource table)
	$sqlAR = "SELECT a.rowid, a.ref, a.type, a.code, a.fk_project, a.fk_element, a.elementtype, a.label,"
		." ar.element_type as res_element_type, ar.fk_element as res_fk_element"
		." FROM ".MAIN_DB_PREFIX."actioncomm a"
		." INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources ar ON ar.fk_actioncomm = a.rowid"
		." WHERE ar.element_type = 'project' AND ar.fk_element = ".$pid
		." AND a.entity IN (".getEntity('actioncomm').")"
		." ORDER BY a.rowid DESC LIMIT 30";
	$resAR = $db->query($sqlAR);
	$out['actioncomms_resources'] = array();
	if ($resAR) {
		while ($row = $db->fetch_object($resAR)) {
			$out['actioncomms_resources'][] = array(
				'rowid'            => (int) $row->rowid,
				'type'             => $row->type,
				'code'             => $row->code,
				'fk_project'       => $row->fk_project,
				'fk_element'       => $row->fk_element,
				'elementtype'      => $row->elementtype,
				'label'            => $row->label,
				'res_element_type' => $row->res_element_type,
				'res_fk_element'   => $row->res_fk_element,
			);
		}
	}

	// Method 4: element_element bidirectional link
	$sqlEE = "SELECT a.rowid, a.type, a.code, a.fk_project, a.fk_element, a.elementtype, a.label"
		." FROM ".MAIN_DB_PREFIX."actioncomm a"
		." INNER JOIN ".MAIN_DB_PREFIX."element_element ee ON ("
		."  (ee.fk_source = a.rowid AND ee.sourcetype = 'actioncomm' AND ee.fk_target = ".$pid." AND ee.targettype = 'project')"
		."  OR (ee.fk_target = a.rowid AND ee.targettype = 'actioncomm' AND ee.fk_source = ".$pid." AND ee.sourcetype = 'project')"
		." )"
		." AND a.entity IN (".getEntity('actioncomm').")"
		." ORDER BY a.rowid DESC LIMIT 30";
	$resEE = $db->query($sqlEE);
	$out['actioncomms_element_element'] = array();
	if ($resEE) {
		while ($row = $db->fetch_object($resEE)) {
			$out['actioncomms_element_element'][] = array(
				'rowid'       => (int) $row->rowid,
				'type'        => $row->type,
				'code'        => $row->code,
				'fk_project'  => $row->fk_project,
				'fk_element'  => $row->fk_element,
				'elementtype' => $row->elementtype,
				'label'       => $row->label,
			);
		}
	}

	// Raw dump: key columns of a specific actioncomm (pass &actioncomm_id=N to inspect)
	$actioncommId = GETPOSTINT('actioncomm_id');
	if ($actioncommId > 0) {
		$sqlRaw = "SELECT rowid, ref, type, code, fk_soc, fk_contact, fk_project,"
			." fk_element, elementtype, label"
			." FROM ".MAIN_DB_PREFIX."actioncomm"
			." WHERE rowid = ".(int) $actioncommId;
		$resRaw = $db->query($sqlRaw);
		$out['actioncomm_raw'] = $resRaw ? $db->fetch_object($resRaw) : null;

		// Also show all its resources rows
		$sqlRes2 = "SELECT rowid, fk_actioncomm, element_type, fk_element"
			." FROM ".MAIN_DB_PREFIX."actioncomm_resources"
			." WHERE fk_actioncomm = ".(int) $actioncommId;
		$resRes2 = $db->query($sqlRes2);
		$out['actioncomm_resources_raw'] = array();
		if ($resRes2) {
			while ($row = $db->fetch_object($resRes2)) {
				$out['actioncomm_resources_raw'][] = $row;
			}
		}
	}
}

// Project fk_soc (to compare with actioncomm fk_soc)
if ($projectId > 0) {
	$sqlSoc = "SELECT fk_soc FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".(int) $projectId;
	$resSoc = $db->query($sqlSoc);
	$out['project_fk_soc'] = ($resSoc && ($rowSoc = $db->fetch_object($resSoc))) ? $rowSoc->fk_soc : null;
}

// Resolve steps for a specific project
if ($projectId > 0) {
	$sqlP = "SELECT rowid, fk_opp_status FROM ".MAIN_DB_PREFIX."projet"
		." WHERE rowid = ".(int) $projectId
		." AND entity IN (".getEntity('projet').")";
	$resP = $db->query($sqlP);
	if ($resP && ($proj = $db->fetch_object($resP))) {
		$resolver = new LeadProgressResolver($db);
		$steps = $resolver->resolve($proj);
		$out['project'] = array(
			'rowid'          => (int) $proj->rowid,
			'fk_opp_status'  => (int) $proj->fk_opp_status,
			'passes_filter'  => leadtrackerProjectPassesFilter((int) $projectId, $db),
			'current_code'   => $resolver->currentCode,
			'steps'          => $steps,
		);
	} else {
		$out['project'] = array('error' => 'Project '.$projectId.' not found');
	}
}

print json_encode($out, JSON_PRETTY_PRINT);
exit;
