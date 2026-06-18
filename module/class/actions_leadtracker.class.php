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
		$renderer->compact         = ($this->getConf('LEADTRACKER_DISPLAY_MODE', 'full') === 'compact');
		$renderer->hideSkipped     = ($this->getConf('LEADTRACKER_SKIPPED_BEHAVIOR', 'show') === 'hide');
		$renderer->clickable       = true;
		$renderer->actionLinks     = ($this->getConf('LEADTRACKER_ACTION_LINKS', '1') == '1');
		$renderer->showDetails     = ($this->getConf('LEADTRACKER_SHOW_DETAILS', '1') == '1');
		$renderer->lifecycleStatus = $resolver->projectStatus;

		$autoSync = $this->getAutoSync((int) $object->id);

		// Cache-bust the stylesheet by file mtime. Without this the browser keeps
		// serving the stylesheet it cached under the same URL, so CSS added in a
		// module upgrade (badge, muted funnel, subtitles) silently fails to apply.
		$cssUrl  = dol_buildpath('/leadtracker/css/leadtracker.css', 1);
		$cssFile = dol_buildpath('/leadtracker/css/leadtracker.css', 0);
		$cssVer  = @filemtime($cssFile);
		$url     = $cssUrl.($cssVer ? '?v='.$cssVer : '');
		$out  = '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag($url).'">'."\n";
		$out .= $this->colorOverrides();
		$out .= '<div id="leadtracker-holder" style="display:none;">';
		$out .= '<div class="leadtracker-wrap">';
		$out .= $renderer->render($steps, $user);
		// Branching deal map — lazy-loaded expandable panel. View mode only.
		if ($action !== 'edit' && $action !== 'create'
			&& $this->getConf('LEADTRACKER_DEAL_MAP', '1') == '1') {
			$out .= $this->dealMapPanel((int) $object->id);
		}
		$out .= '</div>';
		// Only inject the toggle in view mode. In edit/create mode the card is already
		// inside a <form>; our button would become a nested form (invalid HTML) and the
		// browser would submit the parent form instead of ours.
		if ($action !== 'edit' && $action !== 'create') {
			$out .= $this->syncToggle((int) $object->id, $autoSync);
		}
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
			."  if(ta.length){ta.prepend(toggle);}\n"
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
		$lifecycleMap = array(0 => 'draft', 1 => 'validated', 2 => 'closed');
		$lifecycle    = $resolver->projectStatus;
		$lifecycleStr = isset($lifecycleMap[$lifecycle]) ? $lifecycleMap[$lifecycle] : (string) $lifecycle;

		$out  = '<div class="leadtracker-debug"><strong>Leadtracker debug</strong>';
		$out .= ' &mdash; fk_opp_status: '.dol_escape_htmltag((string) $object->fk_opp_status);
		$out .= ' &mdash; fk_statut: '.dol_escape_htmltag($lifecycleStr);
		$out .= ' &mdash; current code: '.dol_escape_htmltag($resolver->currentCode).'<br>';

		if (!empty($resolver->evidence)) {
			$parts = array();
			foreach ($resolver->evidence as $k => $v) {
				$when = '';
				if ($v && !empty($resolver->evidenceDates[$k])) {
					$when = ' ('.dol_print_date((int) $resolver->evidenceDates[$k], 'day').')';
				}
				$parts[] = dol_escape_htmltag($k).': '.($v ? '<b>yes</b>'.dol_escape_htmltag($when) : 'no');
			}
			$out .= '<em>evidence:</em> '.implode(' &nbsp;|&nbsp; ', $parts).'<br>';
		}

		foreach ($steps as $s) {
			$out .= dol_escape_htmltag($s['key'].' =&gt; '.$s['state']).'<br>';
		}

		// Amount / extrafield diagnostics.
		$amountMode  = getDolGlobalString('LEADTRACKER_AMOUNT_MODE', 'manual');
		$efCode      = trim(getDolGlobalString('LEADTRACKER_AMOUNT_EXTRAFIELD', ''));
		$percentMode = getDolGlobalString('LEADTRACKER_PERCENT_MODE', 'manual');
		$out .= '<em>amount_mode:</em> '.dol_escape_htmltag($amountMode);
		$out .= ' &nbsp;|&nbsp; <em>percent_mode:</em> '.dol_escape_htmltag($percentMode);

		if ($efCode !== '') {
			$efClean = preg_replace('/[^a-zA-Z0-9_]/', '', $efCode);
			$rawVal  = '(no row)';
			if ($efClean !== '' && !empty($object->id)) {
				$sqlEf = "SELECT ".$efClean." as val FROM ".MAIN_DB_PREFIX."projet_extrafields"
					." WHERE fk_object = ".(int) $object->id;
				$resEf = $this->db->query($sqlEf);
				if ($resEf && ($rowEf = $this->db->fetch_object($resEf))) {
					$rawVal = ($rowEf->val !== null) ? dol_escape_htmltag((string) $rowEf->val) : '(null)';
				} elseif (!$resEf) {
					$rawVal = '(query error — column missing?)';
				}
			}
			$out .= ' &nbsp;|&nbsp; <em>extrafield</em> <b>'.dol_escape_htmltag($efCode).'</b> = '.$rawVal;
		} else {
			$out .= ' &nbsp;|&nbsp; <em>extrafield:</em> <b>(not configured)</b>';
		}
		$out .= '<br>';

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
	 *  at the start of the card's native action button row (Send email, Modify, etc.).
	 *  Only rendered in view mode — not injected during edit/create (see formObjectOptions).
	 *  Red = auto-sync on; gray = manual override.
	 *
	 *  Uses type="button" + a JS-created form appended to <body> so the POST is never
	 *  a nested form regardless of the surrounding page structure.
	 *
	 *  @param  int   $projectId
	 *  @param  bool  $autoSync  Current state
	 *  @return string            HTML
	 */
	private function syncToggle($projectId, $autoSync)
	{
		global $langs;

		$langs->loadLangs(array('leadtracker@leadtracker'));

		$newVal   = $autoSync ? 0 : 1;
		$label    = $langs->trans($autoSync ? 'LeadtrackerAutoSyncOn' : 'LeadtrackerAutoSyncOff');
		$title    = $langs->trans($autoSync ? 'LeadtrackerAutoSyncOnHelp' : 'LeadtrackerAutoSyncOffHelp');
		$ajaxUrl  = dol_buildpath('/leadtracker/ajax/toggle_sync.php', 1);
		$backurl  = urlencode($_SERVER['REQUEST_URI'] ?? '');
		$token    = newToken();
		$btnClass = 'butAction leadtracker-sync-btn '.($autoSync ? 'leadtracker-sync-on' : 'leadtracker-sync-off');

		// All values go in data-* attributes; the JS reads them and builds a temporary
		// form appended to <body> so it is never nested inside another form.
		$out  = '<div class="leadtracker-sync-row inline-block divButAction">';
		$out .= '<button type="button"';
		$out .= ' class="'.dol_escape_htmltag($btnClass).'"';
		$out .= ' title="'.dol_escape_htmltag($title).'"';
		$out .= ' data-lt-url="'.dol_escape_htmltag($ajaxUrl).'"';
		$out .= ' data-lt-token="'.dol_escape_htmltag($token).'"';
		$out .= ' data-lt-pid="'.(int) $projectId.'"';
		$out .= ' data-lt-val="'.(int) $newVal.'"';
		$out .= ' data-lt-back="'.dol_escape_htmltag($backurl).'"';
		$out .= ' onclick="leadtrackerSyncPost(this)">';
		$out .= dol_escape_htmltag($label);
		$out .= '</button>';
		$out .= '</div>';

		// Helper — defined once per page via a window flag.
		$out .= '<script>';
		$out .= 'if(!window.leadtrackerSyncPost){';
		$out .= 'window.leadtrackerSyncPost=function(b){';
		$out .= 'var f=document.createElement("form");';
		$out .= 'f.method="post";f.action=b.dataset.ltUrl;f.style.display="none";';
		$out .= 'var h=function(n,v){var i=document.createElement("input");i.type="hidden";i.name=n;i.value=v;f.appendChild(i);};';
		$out .= 'h("token",b.dataset.ltToken);';
		$out .= 'h("project_id",b.dataset.ltPid);';
		$out .= 'h("auto_sync",b.dataset.ltVal);';
		$out .= 'h("backurl",b.dataset.ltBack);';
		$out .= 'document.body.appendChild(f);f.submit();';
		$out .= '};}';
		$out .= '</script>';

		return $out;
	}

	/**
	 *  Render the "Deal map" expander: a toggle button plus an empty panel that
	 *  lazy-loads the branching deal graph from ajax/deal_graph.php the first time
	 *  it is opened. The graph HTML is fetched on demand so nothing heavy runs on
	 *  card load — only when the user actually asks to see the map.
	 *
	 *  @param  int  $projectId
	 *  @return string  HTML
	 */
	private function dealMapPanel($projectId)
	{
		global $langs;

		$langs->loadLangs(array('leadtracker@leadtracker'));

		$ajaxUrl = dol_buildpath('/leadtracker/ajax/deal_graph.php', 1);
		$label   = $langs->trans('LeadtrackerDealMap');
		$loading = $langs->trans('LeadtrackerDealLoading');
		$failed  = $langs->trans('LeadtrackerDealError');
		$evLabel = $langs->trans('LeadtrackerDealEventsToggle');

		// Default position of the per-view "Events" switch.
		$evDefault = (getDolGlobalString('LEADTRACKER_DEAL_EVENTS', '0') == '1');

		$out  = '<div class="leadtracker-dealmap">';
		$out .= '<button type="button" class="leadtracker-dealmap-toggle"';
		$out .= ' data-lt-url="'.dol_escape_htmltag($ajaxUrl).'"';
		$out .= ' data-lt-pid="'.(int) $projectId.'"';
		$out .= ' data-lt-loading="'.dol_escape_htmltag($loading).'"';
		$out .= ' data-lt-failed="'.dol_escape_htmltag($failed).'"';
		$out .= ' aria-expanded="false"';
		$out .= ' onclick="leadtrackerDealMapToggle(this)">';
		$out .= '<span class="leadtracker-dealmap-caret">&#9656;</span> ';
		$out .= dol_escape_htmltag($label);
		$out .= '</button>';

		// Toolbar: the "Events" show/hide switch. Only meaningful once the map is
		// open, so it stays hidden until then. Its initial state comes from the
		// configured default; flipping it re-fetches the graph with ?events=0/1.
		$out .= '<div class="leadtracker-dealmap-toolbar" style="display:none;">';
		$out .= '<label class="leadtracker-dealmap-events">';
		$out .= '<input type="checkbox" class="leadtracker-dealmap-events-cb"'.($evDefault ? ' checked' : '').' onchange="leadtrackerDealMapEvents(this)"> ';
		$out .= dol_escape_htmltag($evLabel);
		$out .= '</label>';
		$out .= '</div>';

		$out .= '<div class="leadtracker-dealmap-panel" style="display:none;" data-lt-loaded="0"></div>';
		$out .= '</div>';

		// Helpers — defined once per page via a window flag.
		$out .= '<script>';
		$out .= 'if(!window.leadtrackerDealMapToggle){';
		// Shared (re)loader: reads the events switch and fetches the graph for it.
		$out .= 'window.leadtrackerDealMapFetch=function(wrap){';
		$out .= 'var panel=wrap.querySelector(".leadtracker-dealmap-panel");';
		$out .= 'var b=wrap.querySelector(".leadtracker-dealmap-toggle");';
		$out .= 'var cb=wrap.querySelector(".leadtracker-dealmap-events-cb");';
		$out .= 'if(!panel||!b){return;}';
		$out .= 'var ev=(cb&&cb.checked)?"1":"0";';
		$out .= 'panel.innerHTML="<div class=\'dealgraph-empty\'>"+b.dataset.ltLoading+"</div>";';
		$out .= 'var u=b.dataset.ltUrl+(b.dataset.ltUrl.indexOf("?")>-1?"&":"?")+"projectid="+encodeURIComponent(b.dataset.ltPid)+"&events="+ev;';
		$out .= 'fetch(u,{credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})';
		$out .= '.then(function(r){if(!r.ok){throw 0;}return r.text();})';
		$out .= '.then(function(html){panel.innerHTML=html;panel.setAttribute("data-lt-loaded","1");})';
		$out .= '.catch(function(){panel.innerHTML="<div class=\'dealgraph-empty\'>"+b.dataset.ltFailed+"</div>";});';
		$out .= '};';
		// Open / close the panel (and its toolbar); lazy-load on first open.
		$out .= 'window.leadtrackerDealMapToggle=function(b){';
		$out .= 'var wrap=b.parentNode;';
		$out .= 'var panel=wrap.querySelector(".leadtracker-dealmap-panel");';
		$out .= 'var bar=wrap.querySelector(".leadtracker-dealmap-toolbar");';
		$out .= 'if(!panel){return;}';
		$out .= 'var open=panel.style.display!=="none";';
		$out .= 'if(open){panel.style.display="none";if(bar){bar.style.display="none";}b.setAttribute("aria-expanded","false");b.classList.remove("lt-open");return;}';
		$out .= 'panel.style.display="";if(bar){bar.style.display="";}b.setAttribute("aria-expanded","true");b.classList.add("lt-open");';
		$out .= 'if(panel.getAttribute("data-lt-loaded")==="1"){return;}';
		$out .= 'leadtrackerDealMapFetch(wrap);';
		$out .= '};';
		// Events switch flipped: re-fetch the graph with the new state.
		$out .= 'window.leadtrackerDealMapEvents=function(cb){';
		$out .= 'var wrap=cb.closest(".leadtracker-dealmap");';
		$out .= 'if(wrap){leadtrackerDealMapFetch(wrap);}';
		$out .= '};';
		$out .= '}';
		$out .= '</script>';

		return $out;
	}
}
