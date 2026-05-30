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
 *  \file       core/modules/modLeadtracker.class.php
 *  \ingroup    leadtracker
 *  \brief      Module descriptor for Lead Tracker
 *
 *  Lead Tracker adds a visual pipeline step-indicator to every project/
 *  opportunity card and automatically advances fk_opp_status when
 *  configurable conditions are met. No core files are modified.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Class to describe and enable module Leadtracker
 */
class modLeadtracker extends DolibarrModules
{
	/**
	 *  Constructor.
	 *
	 *  @param  DoliDB  $db  Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		$this->numero = 500121;

		$this->rights_class = 'leadtracker';

		$this->family = "crm";
		$this->module_position = '90';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "Visual pipeline tracker and automation engine for Dolibarr opportunities.";
		$this->descriptionlong = "Lead Tracker renders a horizontal step-indicator bar on every project/opportunity card showing where the lead sits in the sales pipeline. An automation engine listens to native triggers (order validated, proposal signed, action logged, etc.) and advances the pipeline stage automatically based on configurable conditions.";

		$this->editor_name = 'Lead Tracker contributors';
		$this->editor_url = '';

		$this->version = '1.1.15';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'projectpub';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(
				'data'   => array('elementproperties', 'projectcard'),
				'entity' => '0',
			),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();

		$this->config_page_url = array("setup.php@leadtracker");

		$this->hidden = false;
		$this->depends = array('modProjet');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("leadtracker@leadtracker");
		$this->phpmin = array(7, 0);
		$this->need_dolibarr_version = array(14, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			array('LEADTRACKER_FILTER_MODE',      'chaine', 'all',  'Opportunity filter: all | has_category | no_category', 0),
			array('LEADTRACKER_FLAG_CATEGORY_ID', 'chaine', '0',    'Category rowid used as follow flag (0 = none)', 0),
			array('LEADTRACKER_DISPLAY_MODE',     'chaine', 'full', 'Display mode: full or compact', 0),
			array('LEADTRACKER_SKIPPED_BEHAVIOR', 'chaine', 'show', 'Skipped steps: show or hide', 0),
			array('LEADTRACKER_CLICKABLE',        'chaine', '1',    'Completed stages link to their document', 0),
			array('LEADTRACKER_ACTION_LINKS',     'chaine', '1',    'Current stage links to next native action', 0),
			array('LEADTRACKER_DEBUG',            'chaine', '0',    'Show debug output to admins only', 0),
			array('LEADTRACKER_COLOR_COMPLETE',   'chaine', '',     'Override color for completed stages (empty = theme)', 0),
			array('LEADTRACKER_COLOR_CURRENT',    'chaine', '',     'Override color for current stage (empty = theme)', 0),
			array('LEADTRACKER_COLOR_PENDING',    'chaine', '',     'Override color for pending stages (empty = theme)', 0),
			array('LEADTRACKER_COLOR_LOST',       'chaine', '',     'Override color for lost stage (empty = theme)', 0),
			array('LEADTRACKER_AMOUNT_MODE',        'chaine', 'manual', 'Amount source: manual | auto (LTV hierarchy)', 0),
			array('LEADTRACKER_PERCENT_MODE',       'chaine', 'manual', 'Percent source: manual | stage_default', 0),
			array('LEADTRACKER_AMOUNT_EXTRAFIELD',  'chaine', '',       'Project extrafield code used as baseline amount (empty = disabled)', 0),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", $r + 1); // 50012101
		$this->rights[$r][1] = 'See the lead progress tracker';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'read';
		$this->rights[$r][5] = '';

		$this->menu = array();
	}

	/**
	 *  Called when module is enabled. Creates the DB table and registers constants/permissions.
	 *  Uses _load_tables() so run_sql() substitutes  with MAIN_DB_PREFIX for non-default prefixes.
	 *
	 *  @param  string  $options  Options
	 *  @return int                1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$this->remove($options);

		$result = $this->_load_tables('/leadtracker/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init(array(), $options);
	}

	/**
	 *  Called when module is disabled. Does NOT drop the config table (preserve data).
	 *
	 *  @param  string  $options  Options
	 *  @return int                1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
