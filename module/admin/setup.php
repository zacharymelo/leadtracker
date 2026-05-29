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

	// --- Data sync ---
	$amountMode = GETPOST('LEADTRACKER_AMOUNT_MODE', 'alpha');
	if (!in_array($amountMode, array('manual', 'auto'))) {
		$amountMode = 'manual';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_AMOUNT_MODE', $amountMode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}
	$percentMode = GETPOST('LEADTRACKER_PERCENT_MODE', 'alpha');
	if (!in_array($percentMode, array('manual', 'stage_default'))) {
		$percentMode = 'manual';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_PERCENT_MODE', $percentMode, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

	// --- Display ---
	$boolKeys = array('LEADTRACKER_CLICKABLE', 'LEADTRACKER_ACTION_LINKS', 'LEADTRACKER_DEBUG');
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

// Load project categories for the flag selector (type = 6 for projects in llx_categorie)
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
print '</tr>';

if (empty($stages)) {
	print '<tr class="oddeven"><td colspan="'.(1 + count($allConditions)).'">';
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
		print '</tr>';
	}
}
print '<tr><td colspan="'.(1 + count($allConditions)).'">';
print '<span class="opacitymedium" style="font-size:11px;">'.$langs->trans("LeadtrackerAutoSectionHelp").'</span>';
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

print dol_get_fiche_end();

llxFooter();
$db->close();
