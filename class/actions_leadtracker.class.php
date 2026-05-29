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
 *  \file       class/actions_leadtracker.class.php
 *  \ingroup    leadtracker
 *  \brief      Hook handler — injects the pipeline tracker into project card pages.
 */

dol_include_once('/leadtracker/class/leadprogressresolver.class.php');
dol_include_once('/leadtracker/class/leadprogressrenderer.class.php');

/**
 *  ActionsLeadtracker
 *
 *  Loaded by Dolibarr's HookManager for the contexts declared in the module
 *  descriptor. Uses formObjectOptions (NOT printCommonFooter — Dolibarr v22
 *  silently discards resprints from printCommonFooter). A jQuery snippet
 *  relocates the rendered tracker to just below the card banner.
 */
class ActionsLeadtracker
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array */
	public $results = array();

	/** @var string */
	public $resprints;

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Read a module constant with a default fallback.
	 *
	 *  @param  string  $name
	 *  @param  string  $default
	 *  @return string
	 */
	private function conf($name, $default = '')
	{
		global $conf;
		if (isset($conf->global->$name) && $conf->global->$name !== '') {
			return $conf->global->$name;
		}
		return $default;
	}

	/**
	 *  Hook fired during the card form render. Injects the pipeline tracker into
	 *  project card pages.
	 *
	 *  @param  array         $parameters
	 *  @param  CommonObject  $object
	 *  @param  string        $action
	 *  @param  HookManager   $hookmanager
	 *  @return int            0 on success, <0 on error
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs, $user;

		// Dolibarr v22 does not always forward the page object into the hook.
		$globalObj = isset($GLOBALS['object']) && is_object($GLOBALS['object']) ? $GLOBALS['object'] : null;
		if ((!is_object($object) || empty($object->element) || empty($object->id))
			&& $globalObj !== null && !empty($globalObj->element) && !empty($globalObj->id)) {
			$object = $globalObj;
		}

		if (!is_object($object) || empty($object->element) || empty($object->id)) {
			return 0;
		}

		// Only project cards
		if ($object->element !== 'project') {
			return 0;
		}

		// Deduplication guard — some cards invoke the hook more than once per request
		static $rendered = array();
		$renderKey = $object->element.':'.$object->id;
		if (isset($rendered[$renderKey])) {
			return 0;
		}
		$rendered[$renderKey] = true;

		// Permission check
		if (!$user->hasRight('leadtracker', 'read')) {
			return 0;
		}

		$langs->loadLangs(array('leadtracker@leadtracker'));

		$resolver = new LeadProgressResolver($this->db);
		$steps    = $resolver->resolve($object);
		if (empty($steps)) {
			return 0;
		}

		$renderer = new LeadProgressRenderer();
		$renderer->compact     = ($this->conf('LEADTRACKER_DISPLAY_MODE', 'full') === 'compact');
		$renderer->hideSkipped = ($this->conf('LEADTRACKER_SKIPPED_BEHAVIOR', 'show') === 'hide');
		$renderer->clickable   = ($this->conf('LEADTRACKER_CLICKABLE', '1') == '1');
		$renderer->actionLinks = ($this->conf('LEADTRACKER_ACTION_LINKS', '1') == '1');

		$html = $renderer->render($steps, $user);
		if (empty($html)) {
			return 0;
		}

		$out  = $this->stylesheet();
		$out .= $this->colorOverrides();

		$out .= '<div id="leadtracker-holder" style="display:none;">'.$html.'</div>';

		if ($this->conf('LEADTRACKER_DEBUG', '0') == '1' && !empty($user->admin)) {
			$out .= $this->debugPanel($resolver, $steps);
		}

		$out .= $this->relocationScript();

		$this->resprints = $out;
		return 0;
	}

	/**
	 *  <link> tag for the module stylesheet.
	 *
	 *  @return string
	 */
	private function stylesheet()
	{
		$url = dol_buildpath('/leadtracker/css/leadtracker.css', 1);
		return '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($url).'">'."\n";
	}

	/**
	 *  Inline CSS custom-property overrides for admin-configured colors.
	 *
	 *  @return string  HTML <style> block (may be empty)
	 */
	private function colorOverrides()
	{
		$vars = array(
			'--leadtracker-complete' => $this->conf('LEADTRACKER_COLOR_COMPLETE', ''),
			'--leadtracker-current'  => $this->conf('LEADTRACKER_COLOR_CURRENT', ''),
			'--leadtracker-pending'  => $this->conf('LEADTRACKER_COLOR_PENDING', ''),
			'--leadtracker-lost'     => $this->conf('LEADTRACKER_COLOR_LOST', ''),
		);
		$decl = '';
		foreach ($vars as $name => $val) {
			$val = trim($val);
			if ($val !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,\s\.%]+\)$/', $val)) {
				$decl .= $name.':'.$val.';';
			}
		}
		if ($decl === '') {
			return '';
		}
		return '<style>.leadtracker-tracker{'.$decl.'}</style>'."\n";
	}

	/**
	 *  JS snippet that moves the hidden tracker to just below the card banner.
	 *
	 *  @return string
	 */
	private function relocationScript()
	{
		return "<script>\n"
			."jQuery(function(){\n"
			." var holder = jQuery('#leadtracker-holder');\n"
			." if (!holder.length) { return; }\n"
			." var content = holder.children('.leadtracker-tracker');\n"
			." if (!content.length) { holder.remove(); return; }\n"
			." var anchor = jQuery('div.arearef').first();\n"
			." if (anchor.length) { anchor.before(content); }\n"
			." else { var c = jQuery('div.fichecenter').first();\n"
			."   if (c.length) { c.prepend(content); }\n"
			."   else { jQuery('div.tabBar').first().prepend(content); } }\n"
			." holder.remove();\n"
			."});\n"
			."</script>\n";
	}

	/**
	 *  Admin-only debug panel.
	 *
	 *  @param  LeadProgressResolver  $resolver
	 *  @param  array                 $steps
	 *  @return string
	 */
	private function debugPanel($resolver, $steps)
	{
		$out = '<div class="leadtracker-debug"><strong>Leadtracker debug</strong>';
		$out .= ' &mdash; current code: '.dol_escape_htmltag($resolver->currentCode).'<br>';
		foreach ($steps as $s) {
			$out .= dol_escape_htmltag($s['key'].' => '.$s['state']).'<br>';
		}
		$out .= '</div>';
		return $out;
	}
}
