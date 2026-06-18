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
 *  \file       class/dealgraphrenderer.class.php
 *  \ingroup    leadtracker
 *  \brief      Pure HTML output for the branching deal graph. No DB access.
 */

/**
 *  DealGraphRenderer
 *
 *  Renders the graph from DealGraphResolver::build() as a top-down org-chart
 *  tree: the lead at the root, each child branch beneath it, connectors drawn
 *  with CSS. A node with more than one parent (a merge — e.g. one invoice over
 *  two orders) is rendered once under its primary parent; other parents show a
 *  compact reference chip pointing at it. Dead branches stay visible but muted.
 *
 *  Output-only: every value comes pre-built from the resolver.
 */
class DealGraphRenderer
{
	/** @var bool  Offer orderprogress drill-down links on document nodes. */
	public $opEnabled = false;

	/** @var bool  Order siblings by date and stagger them vertically (earlier
	 *             sits higher). When false, a sibling row is flat. */
	public $dateLayout = true;

	/** @var bool  Current state of the "Events" switch rendered beside the legend
	 *             (checked = events are showing). Set from the resolver's
	 *             includeEvents so the control reflects what is on screen. */
	public $showEvents = false;

	/** @var int  Vertical pixels each successive (later) sibling drops. */
	const STAGGER_STEP = 18;

	/** @var array  childKey => true, marks nodes already rendered in-tree. */
	private $rendered = array();

	/** @var array  fromKey => [edge, ...] adjacency built once per render. */
	private $childrenOf = array();

	/** @var array  toKey => count, in-degree for merge detection. */
	private $indeg = array();

	/**
	 *  Render the whole graph.
	 *
	 *  @param  array  $graph  ['root','nodes','edges'] from DealGraphResolver
	 *  @return string         HTML fragment
	 */
	public function render($graph)
	{
		global $langs;

		if (empty($graph['nodes']) || empty($graph['root']) || !isset($graph['nodes'][$graph['root']])) {
			return '<div class="dealgraph-empty">'.dol_escape_htmltag($langs->trans('LeadtrackerDealEmpty')).'</div>';
		}

		$this->rendered   = array();
		$this->childrenOf = array();
		$this->indeg      = array();

		foreach ($graph['edges'] as $e) {
			$this->childrenOf[$e['from']][] = $e;
			$this->indeg[$e['to']] = (isset($this->indeg[$e['to']]) ? $this->indeg[$e['to']] : 0) + 1;
		}

		$out  = '<div class="dealgraph">';
		$out .= $this->renderLegend();
		$out .= '<div class="dealgraph-tree">';
		$out .= $this->renderNode($graph['root'], $graph['nodes']);
		$out .= '</div>';
		$out .= '</div>';
		return $out;
	}

	/**
	 *  Recursively render a node and its (not-yet-rendered) children.
	 *
	 *  @param  string  $key    Node key
	 *  @param  array   $nodes  Node map
	 *  @return string
	 */
	private function renderNode($key, $nodes, $staggerPx = 0, $isChild = false)
	{
		if (!isset($nodes[$key]) || isset($this->rendered[$key])) {
			return '';
		}
		$this->rendered[$key] = true;
		$node = $nodes[$key];

		$out  = '<div class="dealgraph-branch">';
		// A child branch carries a single stem: one vertical line from the rail
		// down to the card. Its height also encodes the date stagger (later
		// siblings get a longer stem, so their card sits lower). One element →
		// no overlapping segments, no doubled lines.
		if ($isChild) {
			$out .= $this->stem($staggerPx);
		}
		$out .= $this->renderCard($node);

		// Children: edges where this node is the parent. A child already rendered
		// elsewhere (a merge target) is shown as a reference chip instead.
		$kids = isset($this->childrenOf[$key]) ? $this->childrenOf[$key] : array();
		if (!empty($kids)) {
			$dated = ($this->dateLayout && count($kids) > 1);
			if ($dated) {
				$kids = $this->sortKidsByDate($kids, $nodes);
			}
			$cards = '';
			$i = 0;
			foreach ($kids as $edge) {
				$ckey = $edge['to'];
				if (!isset($nodes[$ckey])) {
					continue;
				}
				$offset = $dated ? ($i * self::STAGGER_STEP) : 0;
				if (isset($this->rendered[$ckey])) {
					$cards .= '<div class="dealgraph-branch">'.$this->stem($offset).$this->renderRefChip($nodes[$ckey], $edge).'</div>';
				} else {
					$cards .= $this->renderNode($ckey, $nodes, $offset, true);
				}
				$i++;
			}
			if ($cards !== '') {
				$out .= '<div class="dealgraph-children">'.$cards.'</div>';
			}
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 *  The single vertical connector segment from the rail down to a child card.
	 *  Base height plus any date-stagger offset.
	 *
	 *  @param  int  $staggerPx
	 *  @return string
	 */
	private function stem($staggerPx)
	{
		$h = 14 + (int) $staggerPx;
		return '<div class="dealgraph-stem" style="height:'.$h.'px;"></div>';
	}

	/**
	 *  Order sibling edges by their child node's date (earliest first → sits
	 *  highest and leftmost). Undated nodes sort last, keeping stable order.
	 *
	 *  @param  array  $kids   Edges out of the parent
	 *  @param  array  $nodes  Node map
	 *  @return array          Reordered edges
	 */
	private function sortKidsByDate($kids, $nodes)
	{
		// Decorate with original index so the sort is stable across PHP versions.
		$decorated = array();
		foreach ($kids as $i => $edge) {
			$d = isset($nodes[$edge['to']]) ? $nodes[$edge['to']]['date'] : null;
			$decorated[] = array('i' => $i, 'd' => $d, 'edge' => $edge);
		}
		usort($decorated, function ($a, $b) {
			if ($a['d'] === $b['d']) {
				return $a['i'] - $b['i'];
			}
			if ($a['d'] === null) {
				return 1;
			}
			if ($b['d'] === null) {
				return -1;
			}
			return ($a['d'] < $b['d']) ? -1 : 1;
		});
		$out = array();
		foreach ($decorated as $row) {
			$out[] = $row['edge'];
		}
		return $out;
	}

	/**
	 *  Render one node card.
	 *
	 *  @param  array  $node
	 *  @return string
	 */
	private function renderCard($node)
	{
		global $langs;

		if ($node['type'] === 'event') {
			return $this->renderEventCard($node);
		}
		if ($node['type'] === 'aux') {
			return $this->renderAuxCard($node);
		}
		if ($node['type'] === 'call') {
			return $this->renderCallCard($node);
		}

		$classes = array('dealgraph-card', 'dealgraph-'.preg_replace('/[^a-z]/', '', $node['type']), 'dealgraph-state-'.$node['state']);
		if (!empty($node['deadBranch'])) {
			$classes[] = 'dealgraph-dead';
		}
		if (!empty($node['mergePoint'])) {
			$classes[] = 'dealgraph-merge';
		}

		$typeLabel = $langs->trans($node['label']);
		$ref       = ($node['ref'] !== '') ? $node['ref'] : ('#'.$node['id']);
		$sub       = (!empty($node['sub'])) ? $langs->trans($node['sub']) : '';

		$out  = '<div class="'.dol_escape_htmltag(implode(' ', $classes)).'">';
		$out .= '<div class="dealgraph-card-head">';
		$out .= '<span class="dealgraph-type">'.dol_escape_htmltag($typeLabel).'</span>';
		if (!empty($node['mergePoint'])) {
			$out .= ' <span class="dealgraph-flag" title="'.dol_escape_htmltag($langs->trans('LeadtrackerMergeHelp')).'">&#8862;</span>';
		}
		$out .= '</div>';

		$out .= '<a class="dealgraph-ref" href="'.dol_escape_htmltag($node['url']).'">'.dol_escape_htmltag($ref).'</a>';

		if ($node['type'] === 'project' && !empty($node['title'])) {
			$out .= '<div class="dealgraph-title">'.dol_escape_htmltag($node['title']).'</div>';
		}

		$meta = array();
		if ($sub !== '') {
			$meta[] = '<span class="dealgraph-sub dealgraph-sub-'.$node['state'].'">'.dol_escape_htmltag($sub).'</span>';
		}
		if ($node['date'] !== null) {
			$meta[] = '<span class="dealgraph-date">'.dol_escape_htmltag(dol_print_date((int) $node['date'], 'day')).'</span>';
		}
		if ($node['amount'] !== null) {
			$meta[] = '<span class="dealgraph-amount">'.price($node['amount'], 0, $langs, 1, -1, -1, $GLOBALS['conf']->currency).'</span>';
		}
		if (!empty($meta)) {
			$out .= '<div class="dealgraph-meta">'.implode(' &middot; ', $meta).'</div>';
		}

		// Drill-down: when orderprogress is enabled, its bar renders the deep
		// workflow on the document's own card — surface a hint link.
		if ($this->opEnabled && $node['type'] !== 'project') {
			$out .= '<a class="dealgraph-drill" href="'.dol_escape_htmltag($node['url']).'">'
				.dol_escape_htmltag($langs->trans('LeadtrackerDrillDown')).'</a>';
		}

		$out .= '</div>';
		return $out;
	}

	/**
	 *  Render a compact "event" leaf card (call / email / meeting / fax / other).
	 *  Smaller and lighter than a document card; links to the agenda event.
	 *
	 *  @param  array  $node
	 *  @return string
	 */
	private function renderEventCard($node)
	{
		global $langs;

		$kind      = isset($node['eventKind']) ? $node['eventKind'] : 'other';
		$kindClean = preg_replace('/[^a-z]/', '', $kind);
		$kindLabel = $langs->trans('LeadtrackerEvent'.ucfirst($kind));
		$label     = (string) $node['ref'];
		$broad     = !empty($node['broad']);

		$cls   = 'dealgraph-card dealgraph-event dealgraph-event-'.$kindClean.($broad ? ' dealgraph-broad' : '');
		$title = $broad ? ' title="'.dol_escape_htmltag($langs->trans('LeadtrackerCallBroadHelp')).'"' : '';
		$out   = '<a class="'.$cls.'"'.$title.' href="'.dol_escape_htmltag($node['url']).'">';
		$out .= '<span class="dealgraph-event-head">';
		$out .= '<span class="dealgraph-event-icon">'.$this->eventIcon($kind).'</span> ';
		$out .= '<span class="dealgraph-event-kind">'.dol_escape_htmltag($kindLabel).'</span>';
		if ($broad) {
			$out .= ' <span class="dealgraph-broadtag">'.dol_escape_htmltag($langs->trans('LeadtrackerCallBroadTag')).'</span>';
		}
		$out .= '</span>';
		if ($node['date'] !== null) {
			$out .= '<span class="dealgraph-event-date">'.dol_escape_htmltag(dol_print_date((int) $node['date'], 'day')).'</span>';
		}
		if ($label !== '' && $label !== $kindLabel) {
			$out .= '<span class="dealgraph-event-label">'.dol_escape_htmltag(dol_trunc($label, 42)).'</span>';
		}
		$out .= '</a>';
		return $out;
	}

	/**
	 *  Render a compact aux card (warranty claim / service request).
	 *
	 *  @param  array  $node
	 *  @return string
	 */
	private function renderAuxCard($node)
	{
		global $langs;

		$kind      = isset($node['auxKind']) ? $node['auxKind'] : 'warranty';
		$kindClean = preg_replace('/[^a-z]/', '', $kind);
		$typeLabel = $langs->trans($node['label']);
		$ref       = ($node['ref'] !== '') ? $node['ref'] : ('#'.$node['id']);

		$out  = '<a class="dealgraph-card dealgraph-aux dealgraph-aux-'.$kindClean.'" href="'.dol_escape_htmltag($node['url']).'">';
		$out .= '<span class="dealgraph-aux-kind">&#128737; '.dol_escape_htmltag($typeLabel).'</span>';
		$out .= '<span class="dealgraph-aux-ref">'.dol_escape_htmltag($ref).'</span>';
		if ($node['date'] !== null) {
			$out .= '<span class="dealgraph-event-date">'.dol_escape_htmltag(dol_print_date((int) $node['date'], 'day')).'</span>';
		}
		$out .= '</a>';
		return $out;
	}

	/**
	 *  Render a compact phone-call card.
	 *
	 *  @param  array  $node
	 *  @return string
	 */
	private function renderCallCard($node)
	{
		global $langs;

		$dir   = isset($node['callDir']) ? $node['callDir'] : '';
		$icon  = ($dir === 'in') ? '&#128229;' : (($dir === 'out') ? '&#128228;' : '&#9742;'); // 📥/📤/☎
		$label = ($node['ref'] !== '') ? $node['ref'] : $langs->trans('LeadtrackerNodeCall');
		$broad = !empty($node['broad']);

		$meta = array();
		if (!empty($node['disposition'])) {
			$meta[] = ucfirst(strtolower($node['disposition']));
		}
		if (!empty($node['duration'])) {
			$meta[] = $this->formatDuration((int) $node['duration']);
		}

		$cls   = 'dealgraph-card dealgraph-call'.($broad ? ' dealgraph-broad' : '');
		$title = $broad ? ' title="'.dol_escape_htmltag($langs->trans('LeadtrackerCallBroadHelp')).'"' : '';
		$out   = '<a class="'.$cls.'"'.$title.' href="'.dol_escape_htmltag($node['url']).'">';
		$out .= '<span class="dealgraph-event-head"><span class="dealgraph-call-icon">'.$icon.'</span> '
			.'<span class="dealgraph-event-kind">'.dol_escape_htmltag($langs->trans('LeadtrackerNodeCall')).'</span>';
		if ($broad) {
			$out .= ' <span class="dealgraph-broadtag">'.dol_escape_htmltag($langs->trans('LeadtrackerCallBroadTag')).'</span>';
		}
		$out .= '</span>';
		$out .= '<span class="dealgraph-aux-ref">'.dol_escape_htmltag(dol_trunc($label, 28)).'</span>';
		if ($node['date'] !== null) {
			$out .= '<span class="dealgraph-event-date">'.dol_escape_htmltag(dol_print_date((int) $node['date'], 'dayhour')).'</span>';
		}
		if (!empty($meta)) {
			$out .= '<span class="dealgraph-event-label">'.dol_escape_htmltag(implode(' · ', $meta)).'</span>';
		}
		$out .= '</a>';
		return $out;
	}

	/**
	 *  Format a duration in seconds as m:ss (or h:mm:ss).
	 *
	 *  @param  int  $sec
	 *  @return string
	 */
	private function formatDuration($sec)
	{
		if ($sec < 3600) {
			return floor($sec / 60).':'.str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT);
		}
		return floor($sec / 3600).':'.str_pad((string) (floor(($sec % 3600) / 60)), 2, '0', STR_PAD_LEFT)
			.':'.str_pad((string) ($sec % 60), 2, '0', STR_PAD_LEFT);
	}

	/**
	 *  HTML-entity icon for an event kind.
	 *
	 *  @param  string  $kind
	 *  @return string
	 */
	private function eventIcon($kind)
	{
		switch ($kind) {
			case 'call':    return '&#9742;';   // ☎
			case 'email':   return '&#9993;';   // ✉
			case 'sms':     return '&#128172;'; // 💬
			case 'meeting': return '&#128197;'; // 📅
			case 'fax':     return '&#128224;'; // 📠
		}
		return '&#9679;'; // ●
	}

	/**
	 *  Render a compact reference chip for a merge target already drawn elsewhere.
	 *
	 *  @param  array  $node
	 *  @param  array  $edge
	 *  @return string
	 */
	private function renderRefChip($node, $edge)
	{
		global $langs;
		$ref = ($node['ref'] !== '') ? $node['ref'] : ('#'.$node['id']);
		$typeLabel = $langs->trans($node['label']);
		return '<a class="dealgraph-card dealgraph-refchip" href="'.dol_escape_htmltag($node['url']).'"'
			.' title="'.dol_escape_htmltag($langs->trans('LeadtrackerRefChipHelp')).'">'
			.'&#8599; '.dol_escape_htmltag($typeLabel.' '.$ref).'</a>';
	}

	/**
	 *  Render the small state legend.
	 *
	 *  @return string
	 */
	private function renderLegend()
	{
		global $langs;
		$items = array(
			'done'    => 'LeadtrackerLegendDone',
			'active'  => 'LeadtrackerLegendActive',
			'pending' => 'LeadtrackerLegendPending',
			'dead'    => 'LeadtrackerLegendDead',
		);
		$out = '<div class="dealgraph-legend">';
		// Events show/hide switch leads the row, ahead of the status list. Toggling
		// it re-fetches the graph with the opposite events state (panel JS).
		$out .= '<label class="dealgraph-events-toggle" title="'.dol_escape_htmltag($langs->trans('LeadtrackerDealEventsToggleHelp')).'">';
		$out .= '<input type="checkbox" class="leadtracker-dealmap-events-cb"'.($this->showEvents ? ' checked' : '').' onchange="leadtrackerDealMapEvents(this)"> ';
		$out .= dol_escape_htmltag($langs->trans('LeadtrackerDealEventsToggle'));
		$out .= '</label>';
		$out .= '<span class="dealgraph-legend-states">';
		foreach ($items as $state => $key) {
			$out .= '<span class="dealgraph-legend-item">'
				.'<span class="dealgraph-dot dealgraph-state-'.$state.'"></span>'
				.dol_escape_htmltag($langs->trans($key)).'</span>';
		}
		$out .= '</span>';
		$out .= '</div>';
		return $out;
	}
}
