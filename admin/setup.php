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
dol_include_once('/leadtracker/lib/leadtracker.lib.php');

$langs->loadLangs(array("admin", "leadtracker@leadtracker"));

if (!$user->admin) {
	accessforbidden();
}

$action     = GETPOST('action', 'aZ09');
$activeTab  = GETPOST('tab', 'alpha');
if (!in_array($activeTab, array('pipeline', 'display'))) {
	$activeTab = 'pipeline';
}
$backtopage = GETPOST('backtopage', 'alpha');

// Condition types available per stage
$allConditions = array(
	'has_outbound_contact'  => 'LeadtrackerConditionHasContact',
	'has_proposal'          => 'LeadtrackerConditionHasProposal',
	'has_signed_proposal'   => 'LeadtrackerConditionHasSignedProposal',
	'has_order'             => 'LeadtrackerConditionHasOrder',
	'has_invoice'           => 'LeadtrackerConditionHasInvoice',
	'manual_only'           => 'LeadtrackerConditionManualOnly',
);

$boolKeys = array(
	'LEADTRACKER_CLICKABLE',
	'LEADTRACKER_ACTION_LINKS',
	'LEADTRACKER_DEBUG',
);

$colorKeys = array(
	'LEADTRACKER_COLOR_COMPLETE',
	'LEADTRACKER_COLOR_CURRENT',
	'LEADTRACKER_COLOR_PENDING',
	'LEADTRACKER_COLOR_LOST',
);


/*
 * Actions
 */

if ($action == 'update_display') {
	$error = 0;

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

	$skip = GETPOST('LEADTRACKER_SKIPPED_BEHAVIOR', 'alpha');
	if (!in_array($skip, array('show', 'hide'))) {
		$skip = 'show';
	}
	if (dolibarr_set_const($db, 'LEADTRACKER_SKIPPED_BEHAVIOR', $skip, 'chaine', 0, '', $conf->entity) < 0) {
		$error++;
	}

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
	$activeTab = 'display';
}

if ($action == 'update_pipeline') {
	$error = 0;

	// Load stages to get their rowids
	$sqlStages = "SELECT rowid FROM ".MAIN_DB_PREFIX."c_lead_status WHERE active = 1 ORDER BY position ASC";
	$resStages = $db->query($sqlStages);
	$stageRowids = array();
	if ($resStages) {
		while ($obj = $db->fetch_object($resStages)) {
			$stageRowids[] = (int) $obj->rowid;
		}
	}

	// Reorder: positions submitted as stage_order_<idx> = rowid
	$newOrder = GETPOST('stage_order', 'array');
	if (!empty($newOrder) && is_array($newOrder)) {
		$db->begin();
		$pos = 10;
		$orderFailed = false;
		foreach ($newOrder as $rowid) {
			$rowid = (int) $rowid;
			if (!$rowid) {
				continue;
			}
			$sqlUpd = "UPDATE ".MAIN_DB_PREFIX."c_lead_status SET position = ".$pos." WHERE rowid = ".$rowid;
			if (!$db->query($sqlUpd)) {
				$orderFailed = true;
				break;
			}
			$pos += 10;
		}
		if ($orderFailed) {
			$db->rollback();
			$error++;
		} else {
			$db->commit();
		}
	}

	// Save conditions for each stage
	foreach ($stageRowids as $sid) {
		// Delete existing conditions for this stage / entity
		$sqlDel = "DELETE FROM ".MAIN_DB_PREFIX."leadtracker_stage_config"
			." WHERE fk_lead_status = ".$sid
			." AND entity = ".(int) $conf->entity;
		$db->query($sqlDel);

		// Insert checked conditions
		foreach (array_keys($allConditions) as $ctype) {
			$postKey = 'cond_'.$sid.'_'.$ctype;
			if (GETPOST($postKey, 'alpha')) {
				$sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."leadtracker_stage_config"
					." (fk_lead_status, condition_type, active, entity)"
					." VALUES (".$sid.", '".$db->escape($ctype)."', 1, ".(int) $conf->entity.")";
				if (!$db->query($sqlIns)) {
					$error++;
				}
			}
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	$activeTab = 'pipeline';
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("LeadtrackerSetup");
llxHeader('', $title, '', '', 0, 0, array(DOL_URL_ROOT.'/includes/jquery/plugins/jquery-ui/jquery-ui.min.js'), array(), '', 'mod-leadtracker page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'projectpub');

$head = leadtrackerAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans("Module500121Name"), -1, 'projectpub');

print '<span class="opacitymedium">'.$langs->trans("LeadtrackerSetupPage").'</span><br><br>';

// Tab switcher
print '<div class="tabsAction">';
$tabUrl = $_SERVER["PHP_SELF"];
print '<a class="butAction'.($activeTab == 'pipeline' ? 'Selected' : '').'" href="'.$tabUrl.'?tab=pipeline">'.$langs->trans("LeadtrackerTabPipeline").'</a>';
print ' ';
print '<a class="butAction'.($activeTab == 'display' ? 'Selected' : '').'" href="'.$tabUrl.'?tab=display">'.$langs->trans("LeadtrackerTabDisplay").'</a>';
print '</div><br>';


// ============================================================
// PIPELINE TAB
// ============================================================

if ($activeTab == 'pipeline') {
	// Load stages
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

	print '<p class="opacitymedium">'.$langs->trans("LeadtrackerPipelineHelp").'</p>';

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_pipeline">';
	print '<input type="hidden" name="tab" value="pipeline">';

	print '<ul id="leadtracker-stage-list" style="list-style:none;padding:0;margin:0;">';
	foreach ($stages as $stage) {
		$sid = (int) $stage->rowid;
		print '<input type="hidden" name="stage_order[]" value="'.$sid.'" class="lt-order-input" data-id="'.$sid.'">';
		print '<li class="lt-stage-row" data-id="'.$sid.'" style="border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:8px;background:#fafafa;">';
		print '<div style="display:flex;align-items:center;gap:12px;margin-bottom:8px;">';
		print '<span class="lt-drag-handle" style="cursor:grab;font-size:18px;color:#aaa;" title="'.$langs->trans("DragDrop").'">&#9776;</span>';
		print '<strong>'.dol_escape_htmltag($langs->trans($stage->label) !== $stage->label ? $langs->trans($stage->label) : $stage->label).'</strong>';
		print ' <span class="opacitymedium" style="font-size:11px;">['.dol_escape_htmltag($stage->code).']</span>';
		print '</div>';
		print '<div style="display:flex;flex-wrap:wrap;gap:16px;">';
		foreach ($allConditions as $ctype => $langKey) {
			$checked = !empty($condMap[$sid][$ctype]) ? ' checked' : '';
			$postKey = 'cond_'.$sid.'_'.$ctype;
			print '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;">';
			print '<input type="checkbox" name="'.$postKey.'" value="1"'.$checked.'>';
			print dol_escape_htmltag($langs->trans($langKey));
			print '</label>';
		}
		print '</div>';
		print '</li>';
	}
	print '</ul>';

	print '<br>';
	print $form->buttonsSaveCancel("Save", '');
	print '</form>';

	// Sortable JS
	print '<script>
jQuery(function(){
  jQuery("#leadtracker-stage-list").sortable({
    handle: ".lt-drag-handle",
    update: function(){
      jQuery("#leadtracker-stage-list li").each(function(idx){
        var id = jQuery(this).data("id");
        jQuery(".lt-order-input[data-id="+id+"]").val(id);
      });
      // rebuild the hidden order inputs in DOM order
      var inputs = [];
      jQuery("#leadtracker-stage-list li").each(function(){
        inputs.push(jQuery(this).data("id"));
      });
      jQuery(".lt-order-input").each(function(idx){
        jQuery(this).val(inputs[idx]);
      });
    }
  });
  jQuery("#leadtracker-stage-list").disableSelection();
});
</script>';
}


// ============================================================
// DISPLAY TAB
// ============================================================

if ($activeTab == 'display') {
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update_display">';
	print '<input type="hidden" name="tab" value="display">';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans("LeadtrackerDisplayMode").'</td><td width="220"></td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDisplayMode").'</td><td>';
	$modeval = getDolGlobalString('LEADTRACKER_DISPLAY_MODE', 'full');
	print $form->selectarray('LEADTRACKER_DISPLAY_MODE', array(
		'full'    => $langs->trans('LeadtrackerDisplayModeFull'),
		'compact' => $langs->trans('LeadtrackerDisplayModeCompact'),
	), $modeval);
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerClickable").'</td><td>';
	print '<input type="checkbox" name="LEADTRACKER_CLICKABLE" value="1"'.(getDolGlobalString('LEADTRACKER_CLICKABLE') ? ' checked' : '').'>';
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerActionLinks").'</td><td>';
	print '<input type="checkbox" name="LEADTRACKER_ACTION_LINKS" value="1"'.(getDolGlobalString('LEADTRACKER_ACTION_LINKS') ? ' checked' : '').'>';
	print '</td></tr>';

	print '<tr class="oddeven"><td>'.$langs->trans("LeadtrackerDebug").'</td><td>';
	print '<input type="checkbox" name="LEADTRACKER_DEBUG" value="1"'.(getDolGlobalString('LEADTRACKER_DEBUG') ? ' checked' : '').'>';
	print '</td></tr>';

	print '</table><br>';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans("LeadtrackerColors").'</td><td width="220"></td></tr>';
	$colorRows = array(
		'LEADTRACKER_COLOR_COMPLETE' => 'LeadtrackerColorComplete',
		'LEADTRACKER_COLOR_CURRENT'  => 'LeadtrackerColorCurrent',
		'LEADTRACKER_COLOR_PENDING'  => 'LeadtrackerColorPending',
		'LEADTRACKER_COLOR_LOST'     => 'LeadtrackerColorLost',
	);
	foreach ($colorRows as $key => $lkey) {
		print '<tr class="oddeven"><td>'.$langs->trans($lkey).'</td><td>';
		print '<input type="text" name="'.$key.'" value="'.dol_escape_htmltag(getDolGlobalString($key)).'" placeholder="#2e7d32" size="14">';
		print '</td></tr>';
	}
	print '<tr><td colspan="2"><span class="opacitymedium">'.$langs->trans("LeadtrackerColorHelp").'</span></td></tr>';
	print '</table>';

	print $form->buttonsSaveCancel("Save", '');
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
