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
	$sqlAC = "SELECT rowid, ref, type, code, fk_project, fk_element, elementtype, label"
		." FROM ".MAIN_DB_PREFIX."actioncomm"
		." WHERE (fk_project = ".$pid
		."  OR (fk_element = ".$pid." AND elementtype = 'project'))"
		." AND entity IN (".getEntity('actioncomm').")"
		." ORDER BY rowid DESC LIMIT 30";
	$resAC = $db->query($sqlAC);
	$out['actioncomms'] = array();
	if ($resAC) {
		while ($row = $db->fetch_object($resAC)) {
			$out['actioncomms'][] = array(
				'rowid'       => (int) $row->rowid,
				'ref'         => $row->ref,
				'type'        => $row->type,
				'code'        => $row->code,
				'fk_project'  => $row->fk_project,
				'fk_element'  => $row->fk_element,
				'elementtype' => $row->elementtype,
				'label'       => $row->label,
			);
		}
	}
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
