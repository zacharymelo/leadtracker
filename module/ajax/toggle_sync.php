<?php
/* Copyright (C) 2026 Lead Tracker contributors
 * GPL-3.0-or-later
 */

/**
 *  \file       ajax/toggle_sync.php
 *  \ingroup    leadtracker
 *  \brief      Toggle per-project auto-sync flag. POST-only, redirects back.
 */

// Load Dolibarr environment — walk up the directory tree until main.inc.php is found.
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = realpath(__FILE__);
$i   = strlen($tmp) - 1;
while ($i > 0 && !$res) {
	if (file_exists(substr($tmp, 0, $i)."/main.inc.php")) {
		$res = @include substr($tmp, 0, $i)."/main.inc.php";
		break;
	}
	$i--;
}
if (!$res && file_exists("../../main.inc.php"))    { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

// POST only.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit;
}

// CSRF token check.
$token = GETPOST('token', 'alpha');
if (empty($token) || $token !== $_SESSION['newtoken']) {
	accessforbidden('Invalid token');
}

// Module guard.
if (!isModEnabled('leadtracker')) {
	accessforbidden('Module not enabled');
}

// Permission.
if (!$user->hasRight('leadtracker', 'read')) {
	accessforbidden();
}

$projectId = (int) GETPOST('project_id', 'int');
$autoSync  = (int) GETPOST('auto_sync',  'int');
$backurl   = GETPOST('backurl', 'alpha');

if ($projectId <= 0) {
	accessforbidden('Invalid project');
}

// Confirm project exists in this entity.
$sqlChk = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet"
	." WHERE rowid = ".$projectId
	." AND entity IN (".getEntity('projet').")";
$resChk = $db->query($sqlChk);
if (!$resChk || !$db->fetch_object($resChk)) {
	accessforbidden('Project not found');
}

// Upsert auto_sync flag.
$autoSync  = ($autoSync ? 1 : 0);
$sqlUpsert = "INSERT INTO ".MAIN_DB_PREFIX."leadtracker_project (fk_project, auto_sync)"
	." VALUES (".$projectId.", ".$autoSync.")"
	." ON DUPLICATE KEY UPDATE auto_sync = ".$autoSync;
$db->query($sqlUpsert);

// When turning auto-sync back ON, immediately recalculate so the values are fresh.
if ($autoSync) {
	if (!class_exists('LeadtrackerAutomation')) {
		dol_include_once('/leadtracker/class/leadtrackerautomation.class.php');
	}
	if (class_exists('LeadtrackerAutomation')) {
		$automation = new LeadtrackerAutomation($db);
		$automation->recalculateValues($projectId);
	}
}

// Redirect back to the project card.
$url = urldecode($backurl);
if (empty($url) || !preg_match('/^\//', $url)) {
	$url = dol_buildpath('/projet/card.php', 1).'?id='.$projectId;
}
header('Location: '.$url);
exit;
