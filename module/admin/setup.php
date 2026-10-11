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
 *  \file       admin/setup.php
 *  \ingroup    leadtracker
 *  \brief      Admin configuration page for the Lead Tracker module.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
if (!class_exists('Categorie')) {
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
}
dol_include_once('/leadtracker/lib/leadtracker.lib.php');
dol_include_once('/leadtracker/class/leadtrackerevents.class.php');
dol_include_once('/leadtracker/class/leadtrackergooglefeed.class.php');
dol_include_once('/leadtracker/class/leadtrackergoogleapi.class.php');

$langs->loadLangs(array("admin", "leadtracker@leadtracker"));

if (!$user->admin) {
	accessforbidden();
}

$action     = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

$allConditions = array(
	'has_outbound_contact'  => 'LeadtrackerConditionHasContact',
	'has_proposal'          => 'LeadtrackerConditionHasProposal',
	'has_signed_proposal'   => 'LeadtrackerConditionHasSignedProposal',
	'has_order'             => 'LeadtrackerConditionHasOrder',
	'has_invoice'           => 'LeadtrackerConditionHasInvoice',
	'manual_only'           => 'LeadtrackerConditionManualOnly',
);

$condShortHeaders = array(
	'has_outbound_contact'  => $langs->trans('LeadtrackerConditionContactShort'),
	'has_proposal'          => $langs->trans('LeadtrackerConditionProposalShort'),
	'has_signed_proposal'   => $langs->trans('LeadtrackerConditionSignedShort'),
	'has_order'             => $langs->trans('LeadtrackerConditionOrderShort'),
	'has_invoice'           => $langs->trans('LeadtrackerConditionInvoiceShort'),
	'manual_only'           => $langs->trans('LeadtrackerConditionManualShort'),
);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;

	// --- Opportunity filter ---
	$filterMode = GETPOST('LEADTRACKER_FILTER_MODE', 'alpha');
	if (!in_array($filterMode, array('all', 'has_category', 'no_category'))) {
		$filterMode = 'all';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_FILTER_MODE', $filterMode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$catId = (int) GETPOST('LEADTRACKER_FLAG_CATEGORY_ID', 'int');
	if (dolibarr_set_const($db, 'LEADTRACKER_FLAG_CATEGORY_ID', (string) $catId, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// --- Auto-advance conditions ---
	$sqlStages = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_lead_status WHERE active = 1";
	$resStages = $db->query($sqlStages);
	$stageRowids = array();
	if ($resStages) {
		while ($obj = $db->fetch_object($resStages)) {
			$stageRowids[] = (int) $obj->rowid;
		}
	}

	foreach ($stageRowids as $sid) {
		$db->query("DELETE FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
			." WHERE fk_lead_status = ".$sid." AND entity = ".(int) $conf->entity);

		foreach (array_keys($allConditions) as $ctype) {
			if (GETPOST('cond_'.$sid.'_'.$ctype, 'alpha')) {
				$sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."leadtracker_stage_config"
					." (fk_lead_status, condition_type, active, entity)"
					." VALUES (".$sid.", '".$db->escape($ctype)."', 1, ".(int) $conf->entity.")";
				if (!$db->query($sqlIns)) {
					$error++;
				}
			}
		}
	}

	// --- What each stage is reported as ---
	$stageReport = array();
	foreach ($stageRowids as $sid) {
		$category = GETPOST('report_'.$sid, 'aZ09');
		if (in_array($category, LeadtrackerEvents::CATEGORIES, true)) {
			$stageReport[$sid] = $category;
		}
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_STAGE_REPORT', json_encode($stageReport), 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// --- Data sync ---
	$amountMode = GETPOST('LEADTRACKER_AMOUNT_MODE', 'alpha');
	if (!in_array($amountMode, array('manual', 'auto'))) {
		$amountMode = 'manual';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_AMOUNT_MODE', $amountMode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$extraField = preg_replace('/[^a-zA-Z0-9_]/', '', trim(GETPOST('LEADTRACKER_AMOUNT_EXTRAFIELD', 'alpha')));
	if (dolibarr_set_const($db, 'LEADTRACKER_AMOUNT_EXTRAFIELD', $extraField, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$percentMode = GETPOST('LEADTRACKER_PERCENT_MODE', 'alpha');
	if (!in_array($percentMode, array('manual', 'stage_default'))) {
		$percentMode = 'manual';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_PERCENT_MODE', $percentMode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// --- Outbound stage events ---
	$webhookUrl = trim(GETPOST('LEADTRACKER_WEBHOOK_URL', 'alphanohtml'));
	$webhookSchemes = getDolGlobalString('LEADTRACKER_WEBHOOK_ALLOW_LOCAL') === '1' ? '(https?)' : '(https)';
	if ($webhookUrl !== '' && (!filter_var($webhookUrl, FILTER_VALIDATE_URL) || !preg_match('/^'.$webhookSchemes.':\/\//i', $webhookUrl))) {
		setEventMessages($langs->trans('LeadtrackerWebhookUrlInvalid'), null, 'errors');
		$error++;
	} elseif (dolibarr_set_const($db, 'LEADTRACKER_WEBHOOK_URL', $webhookUrl, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	foreach (array('LEADTRACKER_WEBHOOK_ENABLED', 'LEADTRACKER_WEBHOOK_CONTACT') as $key) {
		if (dolibarr_set_const($db, $key, GETPOST($key, 'alpha') ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	// The signing secret is created the first time it is needed and only replaced on request.
	if (getDolGlobalString('LEADTRACKER_WEBHOOK_SECRET') === '' || GETPOST('LEADTRACKER_WEBHOOK_REGEN', 'alpha')) {
		if (dolibarr_set_const($db, 'LEADTRACKER_WEBHOOK_SECRET', bin2hex(random_bytes(24)), 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// --- Google Ads conversion file ---
	foreach (array('LEADTRACKER_GOOGLE_FEED_ENABLED', 'LEADTRACKER_GOOGLE_HASHED_CONTACT') as $key) {
		if (dolibarr_set_const($db, $key, GETPOST($key, 'alpha') ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	foreach (LeadtrackerGoogleFeed::CATEGORIES as $category) {
		$convName = dol_trunc(trim(GETPOST('LEADTRACKER_GOOGLE_CONV_'.strtoupper($category), 'alphanohtml')), 100, 'right', 'UTF-8', 1);
		if (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_CONV_'.strtoupper($category), $convName, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	$googleConsent = GETPOST('LEADTRACKER_GOOGLE_CONSENT', 'alpha');
	if (!in_array($googleConsent, array('', 'Granted', 'Denied'), true)) {
		$googleConsent = '';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_CONSENT', $googleConsent, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$googleUser = preg_replace('/[^A-Za-z0-9_.\-]/', '', GETPOST('LEADTRACKER_GOOGLE_FEED_USER', 'alpha'));
	if ($googleUser === '') {
		$googleUser = 'leadtracker';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_FEED_USER', $googleUser, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	if (getDolGlobalString('LEADTRACKER_GOOGLE_FEED_PASSWORD') === '' || GETPOST('LEADTRACKER_GOOGLE_FEED_REGEN', 'alpha')) {
		if (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_FEED_PASSWORD', bin2hex(random_bytes(16)), 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// --- Google Ads through the API ---
	if (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_API_ENABLED', GETPOST('LEADTRACKER_GOOGLE_API_ENABLED', 'alpha') ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$googleIdKeys = array('LEADTRACKER_GOOGLE_CUSTOMER_ID', 'LEADTRACKER_GOOGLE_LOGIN_CUSTOMER_ID');
	foreach (LeadtrackerGoogleFeed::CATEGORIES as $category) {
		$googleIdKeys[] = 'LEADTRACKER_GOOGLE_ACTION_'.strtoupper($category);
	}
	foreach ($googleIdKeys as $key) {
		// Customer IDs are usually written with dashes; only the digits matter.
		$digits = substr(preg_replace('/\D/', '', GETPOST($key, 'alpha')), 0, 20);
		if (dolibarr_set_const($db, $key, $digits, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}
	// The key is write-only: an empty field keeps the stored one.
	$googleKeyInput = trim(GETPOST('LEADTRACKER_GOOGLE_SA_KEY', 'none'));
	if ($googleKeyInput !== '') {
		$googleKeyClean = LeadtrackerGoogleApi::normalizeServiceAccountKey($googleKeyInput);
		if ($googleKeyClean === null) {
			setEventMessages($langs->trans('LeadtrackerGoogleKeyInvalid'), null, 'errors');
			$error++;
		} elseif (dolibarr_set_const($db, 'LEADTRACKER_GOOGLE_SA_KEY', $googleKeyClean, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	} elseif (GETPOST('LEADTRACKER_GOOGLE_SA_KEY_REMOVE', 'alpha')) {
		dolibarr_del_const($db, 'LEADTRACKER_GOOGLE_SA_KEY', $conf->entity);
	}

	// --- Display ---
	$boolKeys = array('LEADTRACKER_ACTION_LINKS', 'LEADTRACKER_SHOW_DETAILS', 'LEADTRACKER_DEAL_MAP', 'LEADTRACKER_DEAL_DATE_LAYOUT', 'LEADTRACKER_DEAL_EVENTS', 'LEADTRACKER_DEAL_WARRANTY', 'LEADTRACKER_DEAL_CALLS', 'LEADTRACKER_DEAL_BROAD', 'LEADTRACKER_DEBUG');
	foreach ($boolKeys as $key) {
		$val = GETPOST($key, 'alpha') ? '1' : '0';
		if (dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	$mode = GETPOST('LEADTRACKER_DISPLAY_MODE', 'alpha');
	if (!in_array($mode, array('full', 'compact'))) {
		$mode = 'full';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_DISPLAY_MODE', $mode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	$colorKeys = array('LEADTRACKER_COLOR_COMPLETE', 'LEADTRACKER_COLOR_CURRENT', 'LEADTRACKER_COLOR_PENDING', 'LEADTRACKER_COLOR_LOST');
	foreach ($colorKeys as $key) {
		$val = trim(GETPOST($key, 'alpha'));
		if ($val !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,\s\.%]+\)$/', $val)) {
			$val = '';
		}
		if (dolibarr_set_const($db, $key, $val, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
}

if ($action == 'googletest') {
	$googleApi = new LeadtrackerGoogleApi($db);
	$result = $googleApi->sendTest();
	if ($result['ok']) {
		setEventMessages($langs->trans('LeadtrackerGoogleApiTestOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('LeadtrackerGoogleApiTestKo', $result['error']), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if (in_array($action, array('webhooktest', 'webhookdeliver', 'webhookrequeue'))) {
	$events = new LeadtrackerEvents($db);

	if ($action == 'webhooktest') {
		$result = $events->sendTest();
		if ($result['ok']) {
			setEventMessages($langs->trans('LeadtrackerWebhookTestOk', $result['code']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('LeadtrackerWebhookTestKo', $result['error']), null, 'errors');
		}
	} elseif ($action == 'webhookdeliver') {
		if ($events->deliverPending() < 0) {
			setEventMessages($events->error, null, 'errors');
		} else {
			setEventMessages($langs->trans('LeadtrackerWebhookDelivered', $events->output), null, 'mesgs');
		}
	} else {
		$count = $events->requeueFailed();
		setEventMessages($langs->trans('LeadtrackerWebhookRequeued', max(0, $count)), null, $count < 0 ? 'errors' : 'mesgs');
	}

	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$form = new Form($db);

// Load pipeline stages for the conditions matrix
$sqlStages = "SELECT rowid, code, label, position FROM ".MAIN_DB_PREFIX."c_lead_status"
	." WHERE active = 1 ORDER BY position ASC";
$resStages = $db->query($sqlStages);
$stages = array();
if ($resStages) {
	while ($obj = $db->fetch_object($resStages)) {
		$stages[] = $obj;
	}
}

// Load current conditions for all stages
$condMap = array();
if (!empty($stages)) {
	$sids = array();
	foreach ($stages as $s) {
		$sids[] = (int) $s->rowid;
	}
	$sqlConds = "SELECT fk_lead_status, condition_type FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
		." WHERE fk_lead_status IN (".implode(',', $sids).")"
		." AND active = 1"
		." AND entity = ".(int) $conf->entity;
	$resConds = $db->query($sqlConds);
	if ($resConds) {
		while ($obj = $db->fetch_object($resConds)) {
			$condMap[(int) $obj->fk_lead_status][$obj->condition_type] = true;
		}
	}
}

// Load project categories for the flag selector (type = 6 for projects in categorie)
$projectCatTypeInt = 6;
if (class_exists('Categorie')) {
	$tmpCat = new Categorie($db);
	$projectCatTypeInt = isset($tmpCat->MAP_ID['project']) ? (int) $tmpCat->MAP_ID['project'] : 6;
}
$cats = array(0 => '— '.$langs->trans('LeadtrackerFilterCategoryNone').' —');
$sqlCats = "SELECT rowid, label FROM ".MAIN_DB_PREFIX."categorie"
	." WHERE type = ".$projectCatTypeInt
	." AND entity IN (".getEntity('categorie').")"
	." ORDER BY label ASC";
$resCats = $db->query($sqlCats);
if ($resCats) {
	while ($obj = $db->fetch_object($resCats)) {
		$cats[(int) $obj->rowid] = $obj->label;
	}
}

$currentCatId  = (int) getDolGlobalString('LEADTRACKER_FLAG_CATEGORY_ID', '0');
$currentFilter = getDolGlobalString('LEADTRACKER_FILTER_MODE', 'all');

$title = $langs->trans("LeadtrackerSetup");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-leadtracker page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'projectpub');

$head = leadtrackerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500121Name"), -1, 'projectpub');

print '<span class="opacitymedium">'.$langs->trans("LeadtrackerSetupPage").'</span><br><br>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';


// ============================================================
// SECTION 1 — Auto-advance conditions
// ============================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("LeadtrackerAutoSectionTitle").'</td>';
foreach ($allConditions as $ctype => $langKey) {
	$shortLabel = isset($condShortHeaders[$ctype]) ? $condShortHeaders[$ctype] : $langs->trans($langKey);
	print '<td class="center" style="min-width:60px;" title="'.dol_escape_htmltag($langs->trans($langKey)).'">';
	print dol_escape_htmltag($shortLabel);
	print '</td>';
}
print '<td title="'.dol_escape_htmltag($langs->trans("LeadtrackerReportAsHelp")).'">'.$langs->trans("LeadtrackerReportAs").'</td>';
print '</tr>';

$reportHelper = new LeadtrackerEvents($db);
$reportMap = $reportHelper->getCategoryMap();
$reportOptions = array();
foreach (LeadtrackerEvents::CATEGORIES as $category) {
	$reportOptions[$category] = $langs->trans('LeadtrackerCategory'.ucfirst($category));
}

if (empty($stages)) {
	print '<tr class="oddeven"><td colspan="'.(2 + count($allConditions)).'">';
	print '<span class="opacitymedium">'.$langs->trans("LeadtrackerNoStages").'</span> ';
	print '<a href="'.DOL_URL_ROOT.'/admin/dict.php?id=lead_status">'.$langs->trans("LeadtrackerGoToDictionary").'</a>';
	print '</td></tr>';
} else {
	foreach ($stages as $stage) {
		$sid = (int) $stage->rowid;
		$stageLabel = $langs->trans($stage->label) !== $stage->label ? $langs->trans($stage->label) : $stage->label;
		print '<tr class="oddeven">';
		print '<td><strong>'.dol_escape_htmltag($stageLabel).'</strong>'
			.' <span class="opacitymedium" style="font-size:11px;">['.dol_escape_htmltag($stage->code).']</span></td>';
		foreach (array_keys($allConditions) as $ctype) {
			$checked = !empty($condMap[$sid][$ctype]) ? ' checked' : '';
			print '<td class="center">';
			print '<input type="checkbox" name="cond_'.$sid.'_'.$ctype.'" value="1"'.$checked.'>';
			print '</td>';
		}
		print '<td>'.$form->selectarray('report_'.$sid, $reportOptions, isset($reportMap[$sid]) ? $reportMap[$sid] : 'progress', 0, 0, 0, '', 0, 0, 0, '', 'maxwidth200').'</td>';
		print '</tr>';
	}
}
print '<tr><td colspan="'.(2 + count($allConditions)).'">';
print '<span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerAutoSectionHelp").'<br>'.$langs->trans("LeadtrackerReportAsHelp").'</span>';
print '</td></tr>';
print '</table>';

print '<br>';


// ============================================================
// SECTION 2 — Data sync (amount + percent)
// ============================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerDataSyncTitle").'</td></tr>';

// Amount mode
print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerAmountMode").'</td><td>';
print $form->selectarray('LEADTRACKER_AMOUNT_MODE', array(
	'manual' => $langs->trans('LeadtrackerAmountModeManual'),
	'auto'   => $langs->trans('LeadtrackerAmountModeAuto'),
), getDolGlobalString('LEADTRACKER_AMOUNT_MODE', 'manual'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerAmountModeHelp").'</span></td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerAmountExtrafield").'</td><td>';
print '<input type="text" name="LEADTRACKER_AMOUNT_EXTRAFIELD" value="'.dol_escape_htmltag(getDolGlobalString('LEADTRACKER_AMOUNT_EXTRAFIELD')).'" placeholder="e.g. total" size="20">';
print ' <span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerAmountExtrafieldHelp").'</span>';
print '</td></tr>';

// Percent mode
print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerPercentMode").'</td><td>';
print $form->selectarray('LEADTRACKER_PERCENT_MODE', array(
	'manual'        => $langs->trans('LeadtrackerPercentModeManual'),
	'stage_default' => $langs->trans('LeadtrackerPercentModeStageDefault'),
), getDolGlobalString('LEADTRACKER_PERCENT_MODE', 'manual'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerPercentModeHelp").'</span></td></tr>';

print '</table>';

print '<br>';


// ============================================================
// SECTION 3 — Outbound stage events
// ============================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerWebhookTitle").'</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerWebhookHelp").'</span></td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerWebhookEnabled").'</td><td>';
print '<input type="checkbox" name="LEADTRACKER_WEBHOOK_ENABLED" value="1"'.(getDolGlobalString('LEADTRACKER_WEBHOOK_ENABLED') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerWebhookUrl").'</td><td>';
print '<input type="url" name="LEADTRACKER_WEBHOOK_URL" value="'.dol_escape_htmltag(getDolGlobalString('LEADTRACKER_WEBHOOK_URL')).'" placeholder="https://" class="minwidth400">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerWebhookSecret").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerWebhookSecretHelp").'</span></td><td>';
$webhookSecret = getDolGlobalString('LEADTRACKER_WEBHOOK_SECRET');
if ($webhookSecret !== '') {
	print '<input type="text" readonly value="'.dol_escape_htmltag($webhookSecret).'" class="minwidth400" onclick="this.select()">';
	print '<br><label><input type="checkbox" name="LEADTRACKER_WEBHOOK_REGEN" value="1"> '.$langs->trans("LeadtrackerWebhookRegen").'</label>';
} else {
	print '<span class="opacitymedium">'.$langs->trans("LeadtrackerWebhookSecretPending").'</span>';
}
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerWebhookContact").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerWebhookContactHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_WEBHOOK_CONTACT" value="1"'.(getDolGlobalString('LEADTRACKER_WEBHOOK_CONTACT') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '</table>';

print '<br>';


// ============================================================
// SECTION 3b — Google Ads conversion file
// ============================================================

$googleFeed = new LeadtrackerGoogleFeed($db);
$googleFeed->buildRows();
$googleNames = $googleFeed->getConversionNames();

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerGoogleTitle").'</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleHelp").'</span></td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerGoogleEnabled").'</td><td>';
print '<input type="checkbox" name="LEADTRACKER_GOOGLE_FEED_ENABLED" value="1"'.(getDolGlobalString('LEADTRACKER_GOOGLE_FEED_ENABLED') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleUrl").'</td><td>';
print '<input type="text" readonly value="'.dol_escape_htmltag(dol_buildpath('/leadtracker/public/googleads.php', 2).'/conversions.csv').'" class="minwidth500" onclick="this.select()">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleUser").'</td><td>';
print '<input type="text" name="LEADTRACKER_GOOGLE_FEED_USER" value="'.dol_escape_htmltag(getDolGlobalString('LEADTRACKER_GOOGLE_FEED_USER', 'leadtracker')).'" size="20">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGooglePassword").'</td><td>';
$googlePassword = getDolGlobalString('LEADTRACKER_GOOGLE_FEED_PASSWORD');
if ($googlePassword !== '') {
	print '<input type="text" readonly value="'.dol_escape_htmltag($googlePassword).'" class="minwidth300" onclick="this.select()">';
	print '<br><label><input type="checkbox" name="LEADTRACKER_GOOGLE_FEED_REGEN" value="1"> '.$langs->trans("LeadtrackerGoogleRegen").'</label>';
} else {
	print '<span class="opacitymedium">'.$langs->trans("LeadtrackerWebhookSecretPending").'</span>';
}
print '</td></tr>';

foreach (LeadtrackerGoogleFeed::CATEGORIES as $category) {
	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleConvName", $langs->transnoentitiesnoconv('LeadtrackerCategory'.ucfirst($category))).'</td><td>';
	print '<input type="text" name="LEADTRACKER_GOOGLE_CONV_'.strtoupper($category).'" value="'.dol_escape_htmltag($googleNames[$category]).'" class="minwidth300" maxlength="100">';
	print '</td></tr>';
}
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleConvNameHelp").'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleHashed").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleHashedHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_GOOGLE_HASHED_CONTACT" value="1"'.(getDolGlobalString('LEADTRACKER_GOOGLE_HASHED_CONTACT') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleConsent").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleConsentHelp").'</span></td><td>';
print $form->selectarray('LEADTRACKER_GOOGLE_CONSENT', array(
	''        => $langs->trans('LeadtrackerGoogleConsentUnset'),
	'Granted' => $langs->trans('LeadtrackerGoogleConsentGranted'),
	'Denied'  => $langs->trans('LeadtrackerGoogleConsentDenied'),
), getDolGlobalString('LEADTRACKER_GOOGLE_CONSENT'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleStats", LeadtrackerGoogleFeed::WINDOW_DAYS).'</td><td>';
print $langs->trans("LeadtrackerGoogleStatsDetail", $googleFeed->stats['exported'], $googleFeed->stats['no_click_id'], $googleFeed->stats['unmapped'], $googleFeed->stats['repeat']);
print '</td></tr>';

print '</table>';

print '<br>';

// Same conversions, pushed through the API instead of fetched as a file.
$googleApi = new LeadtrackerGoogleApi($db);
$googleAccount = $googleApi->getServiceAccount();
$googleActions = $googleApi->getActionIds();
$eventsForCounts = new LeadtrackerEvents($db);
$googleCounts = $eventsForCounts->countDeliveries(LeadtrackerGoogleApi::DESTINATION);

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerGoogleApiTitle").'</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleApiHelp").'</span></td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerGoogleApiEnabled").'</td><td>';
print '<input type="checkbox" name="LEADTRACKER_GOOGLE_API_ENABLED" value="1"'.(getDolGlobalString('LEADTRACKER_GOOGLE_API_ENABLED') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleCustomerId").'</td><td>';
print '<input type="text" name="LEADTRACKER_GOOGLE_CUSTOMER_ID" value="'.dol_escape_htmltag(getDolGlobalString('LEADTRACKER_GOOGLE_CUSTOMER_ID')).'" placeholder="1234567890" size="16">';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleLoginCustomerId").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleLoginCustomerIdHelp").'</span></td><td>';
print '<input type="text" name="LEADTRACKER_GOOGLE_LOGIN_CUSTOMER_ID" value="'.dol_escape_htmltag(getDolGlobalString('LEADTRACKER_GOOGLE_LOGIN_CUSTOMER_ID')).'" size="16">';
print '</td></tr>';

foreach (LeadtrackerGoogleFeed::CATEGORIES as $category) {
	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleActionId", $langs->transnoentitiesnoconv('LeadtrackerCategory'.ucfirst($category))).'</td><td>';
	print '<input type="text" name="LEADTRACKER_GOOGLE_ACTION_'.strtoupper($category).'" value="'.dol_escape_htmltag($googleActions[$category]).'" size="16">';
	print '</td></tr>';
}
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleActionIdHelp").'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleKey").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerGoogleKeyHelp").'</span></td><td>';
if ($googleAccount !== null) {
	print $langs->trans("LeadtrackerGoogleKeyStored", dol_escape_htmltag($googleAccount['client_email']));
	print ' <label><input type="checkbox" name="LEADTRACKER_GOOGLE_SA_KEY_REMOVE" value="1"> '.$langs->trans("LeadtrackerGoogleKeyRemove").'</label><br>';
}
print '<textarea name="LEADTRACKER_GOOGLE_SA_KEY" rows="3" class="quatrevingtpercent" autocomplete="off" spellcheck="false" placeholder="'.dol_escape_htmltag($langs->trans($googleAccount !== null ? "LeadtrackerGoogleKeyReplace" : "LeadtrackerGoogleKeyPaste")).'"></textarea>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerGoogleApiStatus").'</td><td>';
print $langs->trans("LeadtrackerGoogleApiStatusDetail", $googleCounts[LeadtrackerEvents::STATUS_SENT], $googleCounts[LeadtrackerEvents::STATUS_PENDING], $googleCounts[LeadtrackerEvents::STATUS_FAILED]);
print '</td></tr>';

print '</table>';

print '<br>';


// ============================================================
// SECTION 4 — Opportunity filter
// ============================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerFilterSectionTitle").'</td></tr>';
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerFilterUsageNote").'</span></td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerFilterMode").'</td><td>';
print $form->selectarray('LEADTRACKER_FILTER_MODE', array(
	'all'          => $langs->trans('LeadtrackerFilterAll'),
	'has_category' => $langs->trans('LeadtrackerFilterHasCategory'),
	'no_category'  => $langs->trans('LeadtrackerFilterNoCategory'),
), $currentFilter, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerFilterCategory").'</td><td>';
if (count($cats) > 1) {
	print $form->selectarray('LEADTRACKER_FLAG_CATEGORY_ID', $cats, $currentCatId, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
} else {
	print '<span class="opacitymedium">'.$langs->trans("LeadtrackerFilterNoCategoriesYet").'</span>';
	print ' <a href="'.DOL_URL_ROOT.'/categories/index.php?type=project">'.$langs->trans("LeadtrackerGoToCategories").'</a>';
	print '<input type="hidden" name="LEADTRACKER_FLAG_CATEGORY_ID" value="0">';
}
print '</td></tr>';

print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">';
print $langs->trans("LeadtrackerFilterSectionHelp");
print '</span></td></tr>';
print '</table>';

print '<br>';


// ============================================================
// SECTION 5 — Display
// ============================================================

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerDisplayOptions").'</td></tr>';

print '<tr class="oddeven"><td style="width:40%">'.$langs->trans("LeadtrackerDisplayMode").'</td><td>';
print $form->selectarray('LEADTRACKER_DISPLAY_MODE', array(
	'full'    => $langs->trans('LeadtrackerDisplayModeFull'),
	'compact' => $langs->trans('LeadtrackerDisplayModeCompact'),
), getDolGlobalString('LEADTRACKER_DISPLAY_MODE', 'full'), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerActionLinks").'</td><td>';
print '<input type="checkbox" name="LEADTRACKER_ACTION_LINKS" value="1"'.(getDolGlobalString('LEADTRACKER_ACTION_LINKS', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerShowDetails").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerShowDetailsHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_SHOW_DETAILS" value="1"'.(getDolGlobalString('LEADTRACKER_SHOW_DETAILS', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealMapEnable").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealMapEnableHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_MAP" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_MAP', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealDateLayout").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealDateLayoutHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_DATE_LAYOUT" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_DATE_LAYOUT', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealEvents").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealEventsHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_EVENTS" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_EVENTS', '0') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealWarranty").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealWarrantyHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_WARRANTY" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_WARRANTY', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealCalls").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealCallsHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_CALLS" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_CALLS', '1') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDealBroad").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDealBroadHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEAL_BROAD" value="1"'.(getDolGlobalString('LEADTRACKER_DEAL_BROAD', '0') ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDebug").'<br><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerDebugHelp").'</span></td><td>';
print '<input type="checkbox" name="LEADTRACKER_DEBUG" value="1"'.(getDolGlobalString('LEADTRACKER_DEBUG') ? ' checked' : '').'>';
print '</td></tr>';

print '</table>';

print '<br>';

// Colors
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("LeadtrackerColors").'</td></tr>';
$colorRows = array(
	'LEADTRACKER_COLOR_COMPLETE' => 'LeadtrackerColorComplete',
	'LEADTRACKER_COLOR_CURRENT'  => 'LeadtrackerColorCurrent',
	'LEADTRACKER_COLOR_PENDING'  => 'LeadtrackerColorPending',
	'LEADTRACKER_COLOR_LOST'     => 'LeadtrackerColorLost',
);
foreach ($colorRows as $key => $lkey) {
	print '<tr class="oddeven"><td style="width:40%">'.$langs->trans($lkey).'</td><td>';
	print '<input type="text" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'" placeholder="#2e7d32" size="14">';
	print '</td></tr>';
}
print '<tr><td colspan="2"><span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerColorHelp").'</span></td></tr>';
print '</table>';

print $form->buttonsSaveCancel("Save", '');
print '</form>';


// ============================================================
// Outbound stage events — queue
// ============================================================

$eventsHelper = new LeadtrackerEvents($db);
$recentEvents = $eventsHelper->fetchRecent(10);
$eventStatusLabels = array(
	LeadtrackerEvents::STATUS_PENDING => 'LeadtrackerWebhookStatusPending',
	LeadtrackerEvents::STATUS_SENT    => 'LeadtrackerWebhookStatusSent',
	LeadtrackerEvents::STATUS_FAILED  => 'LeadtrackerWebhookStatusFailed',
	LeadtrackerEvents::STATUS_SKIPPED => 'LeadtrackerWebhookStatusSkipped',
);

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("LeadtrackerWebhookRecent").'</td>';
print '<td>'.$langs->trans("Project").'</td>';
print '<td>'.$langs->trans("LeadtrackerWebhookColStage").'</td>';
print '<td class="right">'.$langs->trans("Amount").'</td>';
print '<td class="right">%</td>';
print '<td>'.$langs->trans("Status").'</td>';
print '</tr>';
if (empty($recentEvents)) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans("LeadtrackerWebhookNoEvents").'</span></td></tr>';
}
foreach ($recentEvents as $ev) {
	$statusKey = isset($eventStatusLabels[(int) $ev->delivery_status]) ? $eventStatusLabels[(int) $ev->delivery_status] : '';
	print '<tr class="oddeven">';
	print '<td>'.dol_print_date($db->jdate($ev->date_event), 'dayhour').' <span class="opacitymedium">'.dol_escape_htmltag($ev->source).'</span></td>';
	print '<td><a href="'.DOL_URL_ROOT.'/projet/card.php?id='.((int) $ev->fk_project).'">'.dol_escape_htmltag($ev->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag(($ev->from_code !== null ? $ev->from_code.' → ' : '').$ev->to_code);
	print ' <span class="opacitymedium">'.$langs->trans('LeadtrackerCategory'.ucfirst($eventsHelper->getStageCategory($ev->fk_stage_to))).'</span></td>';
	print '<td class="right">'.($ev->opp_amount === null ? '' : price($ev->opp_amount)).'</td>';
	print '<td class="right">'.($ev->opp_percent === null ? '' : (float) $ev->opp_percent).'</td>';
	print '<td>'.$langs->trans($statusKey);
	if ((int) $ev->attempts > 0 && (int) $ev->delivery_status !== LeadtrackerEvents::STATUS_SENT) {
		print ' <span class="opacitymedium">('.((int) $ev->attempts).')</span>';
	}
	if (!empty($ev->last_error)) {
		print ' <span class="opacitymedium">'.dol_escape_htmltag($ev->last_error).'</span>';
	}
	print '</td>';
	print '</tr>';
}
print '</table>';

print '<div class="tabsAction">';
foreach (array('webhooktest' => 'LeadtrackerWebhookTest', 'googletest' => 'LeadtrackerGoogleApiTest', 'webhookdeliver' => 'LeadtrackerWebhookDeliverNow', 'webhookrequeue' => 'LeadtrackerWebhookRequeue') as $act => $label) {
	print '<a class="butAction" href="'.$_SERVER["PHP_SELF"].'?action='.$act.'&token='.newToken().'">'.$langs->trans($label).'</a>';
}
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
