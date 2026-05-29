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
 *  \file       admin/about.php
 *  \ingroup    leadtracker
 *  \brief      About page for the Lead Tracker module.
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

$backtopage = GETPOST('backtopage', 'alpha');

$title = $langs->trans("Module500121Name");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-leadtracker page-admin-about');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($title, $linkback, 'projectpub');

$head = leadtrackerAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $langs->trans("Module500121Name"), -1, 'projectpub');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Module500121Name").' 1.0.0</td></tr>';
print '<tr class="oddeven"><td style="width:30%">'.$langs->trans("Description").'</td>';
print '<td>'.$langs->trans("Module500121Desc").'</td></tr>';
print '</table>';

print dol_get_fiche_end();

llxFooter();
$db->close();
