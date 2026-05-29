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
 *  \brief      Library functions for the Leadtracker module admin pages.
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
