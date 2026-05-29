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
 *  \file       lib/leadtracker.lib.php
 *  \ingroup    leadtracker
 *  \brief      Shared utilities and admin tab helper for the Lead Tracker module.
 */

/**
 *  Prepare the tab array for the Lead Tracker admin area.
 *
 *  @return array  Tabs for dol_get_fiche_head()
 */
function leadtrackerAdminPrepareHead()
{
	global $langs, $conf;

	$langs->loadLangs(array('leadtracker@leadtracker'));

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/leadtracker/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/leadtracker/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'leadtracker@leadtracker');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'leadtracker@leadtracker', 'remove');

	return $head;
}

/**
 *  Check whether a project passes the configured category flag filter.
 *
 *  Filter modes:
 *    'all'          — always pass (no filtering)
 *    'has_category' — only pass if project has the configured category
 *    'no_category'  — only pass if project does NOT have the configured category
 *
 *  Returns true if no category is configured, regardless of mode, so
 *  the tracker degrades gracefully when the admin hasn't finished setup.
 *
 *  @param  int     $projectId
 *  @param  DoliDB  $db
 *  @return bool
 */
function leadtrackerProjectPassesFilter($projectId, $db)
{
	$mode = getDolGlobalString('LEADTRACKER_FILTER_MODE', 'all');
	if ($mode === 'all') {
		return true;
	}

	$catId = (int) getDolGlobalString('LEADTRACKER_FLAG_CATEGORY_ID', '0');
	if ($catId <= 0) {
		return true;
	}

	$sql = "SELECT COUNT(*) as cnt FROM ".MAIN_DB_PREFIX."categorie_project"
		." WHERE fk_project = ".(int) $projectId
		." AND fk_categorie = ".$catId;
	$res = $db->query($sql);
	$hasCategory = false;
	if ($res) {
		$obj = $db->fetch_object($res);
		$hasCategory = ($obj && (int) $obj->cnt > 0);
	}

	return ($mode === 'has_category') ? $hasCategory : !$hasCategory;
}
