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

/**
 *  ActionsLeadtracker
 *
 *  Loaded by Dolibarr's HookManager for the elementproperties and projectcard
 *  contexts. Uses formObjectOptions (NOT printCommonFooter — v22 silently discards
 *  resprints from printCommonFooter). A jQuery snippet relocates the rendered
 *  tracker to just below the card banner. All includes are deferred until
 *  formObjectOptions runs so that a missing file never causes a PHP fatal on load.
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
	public $resprints = '';

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Register element type so Dolibarr can resolve it in linked-object lookups.
	 *  Called by HookManager on the 'elementproperties' context.
	 *
	 *  Lead Tracker has no business objects, so this always returns 0 with an
	 *  empty results array. The method must exist to satisfy the hook contract
	 *  whenever 'elementproperties' is listed in module_parts hooks.
	 *
	 *  @param  array       $parameters
	 *  @param  object      $object
	 *  @param  string      $action
	 *  @param  HookManager $hookmanager
	 *  @return int          0 = continue
	 */
	public function getElementProperties($parameters, &$object, &$action, $hookmanager)
	{
		return 0;
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

		// Only project cards. Project::$element = 'project' in Dolibarr v14+.
		if ($object->element !== 'project') {
			return 0;
		}

		// Only track projects with the native "Follow opportunity" flag enabled.
		if (empty($object->usage_opportunity)) {
			return 0;
		}

		// Deduplication guard — some card pages call the hook more than once per request.
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

		// Defer includes so a missing file returns 0 instead of a fatal error.
		if (!class_exists('LeadProgressResolver')) {
			dol_include_once('/leadtracker/class/leadprogressresolver.class.php');
		}
		if (!class_exists('LeadProgressRenderer')) {
			dol_include_once('/leadtracker/class/leadprogressrenderer.class.php');
		}
		if (!class_exists('LeadProgressResolver') || !class_exists('LeadProgressRenderer')) {
			return 0;
		}
		if (!function_exists('leadtrackerProjectPassesFilter')) {
			dol_include_once('/leadtracker/lib/leadtracker.lib.php');
		}

		// Category flag filter — only show tracker for qualifying projects.
		if (function_exists('leadtrackerProjectPassesFilter')
			&& !leadtrackerProjectPassesFilter((int) $object->id, $this->db)) {
			return 0;
		}

		$langs->loadLangs(array('leadtracker@leadtracker'));

		$resolver = new LeadProgressResolver($this->db);
		$steps    = $resolver->resolve($object);
		if (empty($steps)) {
			return 0;
		}

		$renderer = new LeadProgressRenderer();
		$renderer->compact     = ($this->getConf('LEADTRACKER_DISPLAY_MODE', 'full') === 'compact');
		$renderer->hideSkipped = ($this->getConf('LEADTRACKER_SKIPPED_BEHAVIOR', 'show') === 'hide');
		$renderer->clickable   = ($this->getConf('LEADTRACKER_CLICKABLE', '1') == '1');
		$renderer->actionLinks = ($this->getConf('LEADTRACKER_ACTION_LINKS', '1') == '1');

		$autoSync = $this->getAutoSync((int) $object->id);

		$url = dol_buildpath('/leadtracker/css/leadtracker.css', 1);
		$out  = '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($url).'">'."\n";
		$out .= $this->colorOverrides();
		$out .= '<div id="leadtracker-holder" style="display:none;">';
		$out .= '<div class="leadtracker-wrap">';
		$out .= $renderer->render($steps, $user);
		$out .= '</div>';
		$out .= $this->syncToggle((int) $object->id, $autoSync);
		$out .= '</div>'."\n";

		if ($this->getConf('LEADTRACKER_DEBUG', '0') == '1' && !empty($user->admin)) {
			$out .= $this->debugPanel($resolver, $steps, $object);
		}

		$out .= $this->relocationScript();

		$this->resprints = $out;
		return 0;
	}

	/**
	 *  Read a module constant with a default fallback.
	 *
	 *  @param  string  $name
	 *  @param  string  $default
	 *  @return string
	 */
	private function getConf($name, $default = '')
	{
		global $conf;
		if (isset($conf->global->$name) && $conf->global->$name !== '') {
			return $conf->global->$name;
		}
		return $default;
	}

	/**
	 *  Build inline CSS custom-property overrides for admin-configured colors.
	 *
	 *  @return string
	 */
	private function colorOverrides()
	{
		$vars = array(
			'--leadtracker-complete' => $this->getConf('LEADTRACKER_COLOR_COMPLETE', ''),
			'--leadtracker-current'  => $this->getConf('LEADTRACKER_COLOR_CURRENT', ''),
			'--leadtracker-pending'  => $this->getConf('LEADTRACKER_COLOR_PENDING', ''),
			'--leadtracker-lost'     => $this->getConf('LEADTRACKER_COLOR_LOST', ''),
		);
		$decl = '';
		foreach ($vars as $name => $val) {
			$val = trim($val);
			if ($val !== '' && preg_match('/^#[0-9a-fA-F]{3,8}$|^[a-zA-Z]+$|^rgba?\([0-9,\s\.%]+\)$/', $val)) {
				$decl .= $name.':'.$val.';';
			}
		}
		if ($decl !== '') {
			return '<style>.leadtracker-tracker{'.$decl.'}</style>'."\n";
		}
		return '';
	}

	/**
	 *  Build the JS snippet that moves the hidden tracker to just below the card banner.
	 *
	 *  @return string
	 */
	private function relocationScript()
	{
		return "<script>\n"
			."jQuery(function(){\n"
			." var holder=jQuery('#leadtracker-holder');\n"
			." if(!holder.length){return;}\n"
			// Move tracker bar to just above the card detail area.
			." var wrap=holder.children('.leadtracker-wrap');\n"
			." if(wrap.length){\n"
			."  var anchor=jQuery('div.arearef').first();\n"
			."  if(anchor.length){anchor.before(wrap);}\n"
			."  else{var c=jQuery('div.fichecenter').first();\n"
			."   if(c.length){c.prepend(wrap);}\n"
			."   else{jQuery('div.tabBar').first().prepend(wrap);}}\n"
			." }\n"
			// Move sync toggle into the tabsAction button row.
			." var toggle=holder.children('.leadtracker-sync-row');\n"
			." if(toggle.length){\n"
			."  var ta=jQuery('div.tabsAction').first();\n"
			."  if(ta.length){ta.append(toggle);}\n"
			." }\n"
			." holder.remove();\n"
			."});\n"
			."</script>\n";
	}

	/**
	 *  Build admin-only debug panel.
	 *
	 *  @param  LeadProgressResolver  $resolver
	 *  @param  array                 $steps
	 *  @param  object                $object
	 *  @return string
	 */
	private function debugPanel($resolver, $steps, $object)
	{
		$out  = '<div class="leadtracker-debug"><strong>Leadtracker debug</strong>';
		$out .= ' &mdash; fk_opp_status: '.dol_escape_htmltag((string) $object->fk_opp_status);
		$out .= ' &mdash; current code: '.dol_escape_htmltag($resolver->currentCode).'<br>';

		if (!empty($resolver->evidence)) {
			$parts = array();
			foreach ($resolver->evidence as $k => $v) {
				$parts[] = dol_escape_htmltag($k).': '.($v ? '<b>yes</b>' : 'no');
			}
			$out .= '<em>evidence:</em> '.implode(' &nbsp;|&nbsp; ', $parts).'<br>';
		}

		foreach ($steps as $s) {
			$out .= dol_escape_htmltag($s['key'].' =&gt; '.$s['state']).'<br>';
		}
		$out .= '</div>';
		return $out;
	}

	/**
	 *  Read the auto_sync flag for a project. Returns true (on) if no row exists.
	 *
	 *  @param  int  $projectId
	 *  @return bool
	 */
	private function getAutoSync($projectId)
	{
		$sql = "SELECT auto_sync FROM ".MAIN_DB_PREFIX."leadtracker_project"
			." WHERE fk_project = ".(int) $projectId;
		$res = $this->db->query($sql);
		if ($res && ($obj = $this->db->fetch_object($res))) {
			return (bool) $obj->auto_sync;
		}
		return true;
	}

	/**
	 *  Render the auto-sync toggle. Relocated by JS into div.tabsAction so it sits
	 *  alongside the card's native action buttons (Send email, Back to draft, etc.).
	 *
	 *  @param  int   $projectId
	 *  @param  bool  $autoSync  Current state
	 *  @return string            HTML
	 */
	private function syncToggle($projectId, $autoSync)
	{
		global $langs;

		$langs->loadLangs(array('leadtracker@leadtracker'));

		$newVal  = $autoSync ? 0 : 1;
		$label   = $langs->trans($autoSync ? 'LeadtrackerAutoSyncOn' : 'LeadtrackerAutoSyncOff');
		$title   = $langs->trans($autoSync ? 'LeadtrackerAutoSyncOnHelp' : 'LeadtrackerAutoSyncOffHelp');
		$ajaxUrl = dol_buildpath('/leadtracker/ajax/toggle_sync.php', 1);
		$backurl = htmlspecialchars(urlencode($_SERVER['REQUEST_URI'] ?? ''), ENT_QUOTES, 'UTF-8');

		// .inline-block.divButAction makes it vertically align with Dolibarr's action buttons.
		$out  = '<div class="leadtracker-sync-row inline-block divButAction">';
		$out .= '<form id="leadtracker-sync-form" method="post"';
		$out .= ' action="'.dol_escape_htmltag($ajaxUrl).'" style="margin:0;padding:0;">';
		$out .= '<input type="hidden" name="token" value="'.dol_escape_htmltag(newToken()).'">';
		$out .= '<input type="hidden" name="project_id" value="'.(int) $projectId.'">';
		$out .= '<input type="hidden" name="auto_sync" value="'.(int) $newVal.'">';
		$out .= '<input type="hidden" name="backurl" value="'.$backurl.'">';
		$out .= '<label class="leadtracker-sync-label" title="'.dol_escape_htmltag($title).'">';
		$out .= '<span class="leadtracker-sync-text">'.dol_escape_htmltag($label).'</span>';
		$out .= '<span class="leadtracker-sync-switch">';
		$out .= '<input type="checkbox" class="leadtracker-sync-check"'.($autoSync ? ' checked' : '').'>';
		$out .= '<span class="leadtracker-sync-slider"></span>';
		$out .= '</span>';
		$out .= '</label>';
		$out .= '</form>';
		$out .= '<script>document.querySelector(\'.leadtracker-sync-check\').addEventListener(\'change\',function(){document.getElementById(\'leadtracker-sync-form\').submit();});</script>';
		$out .= '</div>';

		return $out;
	}
}
