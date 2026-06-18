<?php
/* Copyright (C) 2026 Lead Tracker contributors
 * GPL-3.0-or-later
 */

/**
 *  \file       ajax/deal_graph.php
 *  \ingroup    leadtracker
 *  \brief      Returns the rendered branching deal-graph HTML for a project.
 *              GET-only, read-only. Lazily fetched when the user expands the
 *              "Deal map" panel on a project card.
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

// Module guard.
if (!isModEnabled('leadtracker')) {
	accessforbidden('Module not enabled');
}

// Permission.
if (!$user->hasRight('leadtracker', 'read')) {
	accessforbidden();
}

$projectId = (int) GETPOST('projectid', 'int');
if ($projectId <= 0) {
	accessforbidden('Invalid project');
}

// Confirm the project exists and is visible in this entity before reading it.
$sqlChk = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet"
	." WHERE rowid = ".$projectId
	." AND entity IN (".getEntity('projet').")";
$resChk = $db->query($sqlChk);
if (!$resChk || !$db->fetch_object($resChk)) {
	accessforbidden('Project not found');
}

// Respect Dolibarr's per-project read permission (private projects).
if (!$user->hasRight('projet', 'lire')) {
	accessforbidden();
}

$langs->loadLangs(array('leadtracker@leadtracker'));

dol_include_once('/leadtracker/class/dealgraphresolver.class.php');
dol_include_once('/leadtracker/class/dealgraphrenderer.class.php');

if (!class_exists('DealGraphResolver') || !class_exists('DealGraphRenderer')) {
	http_response_code(500);
	print '<div class="dealgraph-empty">'.dol_escape_htmltag($langs->trans('LeadtrackerDealError')).'</div>';
	exit;
}

$resolver = new DealGraphResolver($db);
// Events visibility: the deal map carries a per-view "Events" switch. When the
// caller passes ?events=0/1 that wins; otherwise fall back to the configured
// default ("Show events in the deal map by default").
if (GETPOSTISSET('events')) {
	$resolver->includeEvents = ((int) GETPOST('events', 'int') === 1);
} else {
	$resolver->includeEvents = (getDolGlobalString('LEADTRACKER_DEAL_EVENTS', '0') == '1');
}
$resolver->includeWarranty = (getDolGlobalString('LEADTRACKER_DEAL_WARRANTY', '1') == '1');
$resolver->includeCalls    = (getDolGlobalString('LEADTRACKER_DEAL_CALLS', '1') == '1');
$resolver->broad           = (getDolGlobalString('LEADTRACKER_DEAL_BROAD', '0') == '1');

// Admin-only linkage diagnostic: ?debug=1 dumps how the deal's documents are
// (or are not) linked, instead of rendering the graph.
if ((int) GETPOST('debug', 'int') === 1 && !empty($user->admin)) {
	top_httphead('text/plain');
	print $resolver->diagnose($projectId);
	exit;
}

$graph = $resolver->build($projectId);

$renderer = new DealGraphRenderer();
$renderer->opEnabled = $resolver->opEnabled;
// Date layout (earlier siblings sit higher) is on unless the admin disabled it.
$renderer->dateLayout = (getDolGlobalString('LEADTRACKER_DEAL_DATE_LAYOUT', '1') == '1');
// Reflect the current events state on the in-graph "Events" switch.
$renderer->showEvents = $resolver->includeEvents;

// Fragment only — no page chrome. The caller injects this into the panel.
top_httphead('text/html');
print $renderer->render($graph);
