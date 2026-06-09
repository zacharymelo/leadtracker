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
 *  \file       class/leadprogressrenderer.class.php
 *  \ingroup    leadtracker
 *  \brief      Pure HTML renderer for the lead pipeline tracker. No DB access.
 */

/**
 *  LeadProgressRenderer
 *
 *  Converts a normalized steps array (from LeadProgressResolver) into HTML.
 *  Output only — reads nothing from the database.
 */
class LeadProgressRenderer
{
	/** @var bool Compact display: circles only, no labels */
	public $compact = false;

	/** @var bool Hide pending stages after a terminal (WON/LOST) stage */
	public $hideSkipped = false;

	/** @var bool Completed stages link to native view (not used for lead tracker) */
	public $clickable = false;

	/** @var bool Show action link on the current stage */
	public $actionLinks = true;

	/** @var bool Show per-stage date subtitles and hover tooltips */
	public $showDetails = true;

	/** @var int|null Native project lifecycle status (0 draft, 1 validated, 2 closed) */
	public $lifecycleStatus = null;

	/**
	 *  Render the tracker HTML.
	 *
	 *  @param  array  $steps  Normalized steps from LeadProgressResolver
	 *  @param  User   $user   Current user (for permission checks on action links)
	 *  @return string          HTML (empty string if nothing to show)
	 */
	public function render($steps, $user)
	{
		global $langs;

		$langs->loadLangs(array('leadtracker@leadtracker'));

		if (empty($steps) || !is_array($steps)) {
			return '';
		}

		$visible = array();
		foreach ($steps as $s) {
			if ($this->hideSkipped
				&& $s['state'] === LeadProgressResolver::STATE_PENDING
				&& in_array($this->currentCode($steps), array('WON', 'LOST'))) {
				continue;
			}
			$visible[] = $s;
		}
		if (empty($visible)) {
			return '';
		}

		$classMode = $this->compact ? ' leadtracker-compact' : '';
		// Draft and Closed projects are frozen — dim the whole track so it reads as
		// "not actively progressing" without removing the information.
		$mutedMode = in_array((int) $this->lifecycleStatus, array(0, 2), true) ? ' leadtracker-muted' : '';
		$out = '<div class="leadtracker-tracker'.$classMode.$mutedMode.'" role="list" aria-label="'.dol_escape_htmltag($langs->trans('LeadtrackerTitle')).'">';

		$out .= $this->lifecycleBadge();

		$prevState = null;
		foreach ($visible as $idx => $step) {
			$out .= $this->renderStep($step, $user, ($idx > 0), $prevState);
			$prevState = $step['state'];
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Render a single step with connector, circle, and label.
	 *
	 *  @param  array   $step           Step descriptor
	 *  @param  User    $user           Current user
	 *  @param  bool    $withConnector  Prepend connector line
	 *  @param  string  $prevState      State of the previous step (for connector color)
	 *  @return string                   HTML
	 */
	private function renderStep($step, $user, $withConnector, $prevState = null)
	{
		global $langs;

		$state = isset($step['state']) ? $step['state'] : LeadProgressResolver::STATE_PENDING;

		$stateClass = 'leadtracker-'.preg_replace('/[^a-z]/', '', $state);

		// Connector is "traveled" only when both the previous and this step are done.
		$donePrev = in_array($prevState, array(
			LeadProgressResolver::STATE_COMPLETE,
			LeadProgressResolver::STATE_CURRENT,
			LeadProgressResolver::STATE_WON,
		));
		$doneSelf = in_array($state, array(
			LeadProgressResolver::STATE_COMPLETE,
			LeadProgressResolver::STATE_CURRENT,
			LeadProgressResolver::STATE_WON,
		));
		$connectorClass = ($donePrev && $doneSelf) ? ' leadtracker-connector-complete' : '';

		$tooltip  = $this->showDetails ? $this->stepTooltip($step, $state) : '';
		$titleAttr = ($tooltip !== '')
			? ' title="'.dol_escape_htmltag($tooltip).'"'
			: '';

		$out = '<div class="leadtracker-step '.$stateClass.'" role="listitem"'.$titleAttr.'>';

		if ($withConnector) {
			$out .= '<span class="leadtracker-connector'.$connectorClass.'" aria-hidden="true"></span>';
		}

		$circleInner = $this->circleGlyph($state);

		$href = '';
		$linkClass = 'leadtracker-link';

		$isActionable = ($state === LeadProgressResolver::STATE_CURRENT)
			&& $this->actionLinks
			&& !empty($step['action_url'])
			&& $this->userCanProject($user);

		if ($isActionable) {
			$href = $step['action_url'];
			$linkClass .= ' leadtracker-action';
		}

		$canLink = ($href !== '');
		if ($canLink) {
			$out .= '<a class="'.$linkClass.'" href="'.dol_escape_htmltag($href).'">';
		}

		$out .= '<span class="leadtracker-circle" aria-hidden="true">'.$circleInner.'</span>';
		$out .= '<span class="leadtracker-label">'.dol_escape_htmltag($step['label']).'</span>';

		if ($this->showDetails) {
			$subtitle = $this->stepSubtitle($step, $state);
			if ($subtitle !== '') {
				$out .= '<span class="leadtracker-subtitle">'.dol_escape_htmltag($subtitle).'</span>';
			}
		}

		if ($canLink) {
			$out .= '</a>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 *  Compact subtitle shown beneath a stage label — the date the stage was
	 *  reached. Only reached stages (complete / current / won) carry a date.
	 *
	 *  @param  array   $step
	 *  @param  string  $state
	 *  @return string   Localized short date, or '' when none applies
	 */
	private function stepSubtitle($step, $state)
	{
		$reached = in_array($state, array(
			LeadProgressResolver::STATE_COMPLETE,
			LeadProgressResolver::STATE_CURRENT,
			LeadProgressResolver::STATE_WON,
		));
		if (!$reached || empty($step['event_date'])) {
			return '';
		}
		return dol_print_date((int) $step['event_date'], 'day');
	}

	/**
	 *  Hover tooltip describing the stage: when it completed, that it is in
	 *  progress, or what is still required to advance into it.
	 *
	 *  @param  array   $step
	 *  @param  string  $state
	 *  @return string   Plain text (escaped by the caller)
	 */
	private function stepTooltip($step, $state)
	{
		global $langs;

		$date    = !empty($step['event_date']) ? dol_print_date((int) $step['event_date'], 'day') : '';
		$doneKey = !empty($step['event_done_key']) ? $step['event_done_key'] : '';
		$needs   = $this->conditionLabels($step);

		switch ($state) {
			case LeadProgressResolver::STATE_WON:
				return $langs->trans('LeadtrackerTipWon');
			case LeadProgressResolver::STATE_LOST:
				return $langs->trans('LeadtrackerTipLost');
			case LeadProgressResolver::STATE_COMPLETE:
				// Describe what actually happened ("Called on …", "Proposal sent on …").
				if ($date !== '' && $doneKey !== '') {
					return $langs->trans($doneKey, $date);
				}
				return ($date !== '')
					? $langs->trans('LeadtrackerTipCompletedOn', $date)
					: $langs->trans('LeadtrackerTipCompleted');
			case LeadProgressResolver::STATE_CURRENT:
				// The stage is current because this evidence landed — name it.
				if ($date !== '' && $doneKey !== '') {
					return $langs->trans($doneKey, $date);
				}
				return ($needs !== '')
					? $langs->trans('LeadtrackerTipCurrentNeeds', $needs)
					: $langs->trans('LeadtrackerTipCurrent');
			default: // pending
				return ($needs !== '')
					? $langs->trans('LeadtrackerTipNeeds', $needs)
					: $langs->trans('LeadtrackerTipManual');
		}
	}

	/**
	 *  Human-readable, comma-joined list of the conditions a stage needs to
	 *  advance. 'manual_only' is dropped — it has its own tooltip wording.
	 *
	 *  @param  array  $step
	 *  @return string
	 */
	private function conditionLabels($step)
	{
		global $langs;

		if (empty($step['conditions']) || !is_array($step['conditions'])) {
			return '';
		}

		$map = array(
			'has_outbound_contact' => 'LeadtrackerConditionHasContact',
			'has_proposal'         => 'LeadtrackerConditionHasProposal',
			'has_signed_proposal'  => 'LeadtrackerConditionHasSignedProposal',
			'has_order'            => 'LeadtrackerConditionHasOrder',
			'has_invoice'          => 'LeadtrackerConditionHasInvoice',
		);

		$labels = array();
		foreach ($step['conditions'] as $ctype) {
			if (isset($map[$ctype])) {
				$labels[] = $langs->trans($map[$ctype]);
			}
		}
		return implode(', ', $labels);
	}

	/**
	 *  Lifecycle status pill rendered ahead of the funnel. Only Draft and Closed
	 *  are surfaced — a Validated project needs no badge (it is the normal case).
	 *
	 *  @return string  HTML (empty for validated / unknown)
	 */
	private function lifecycleBadge()
	{
		global $langs;

		switch ((int) $this->lifecycleStatus) {
			case 0:
				$label = $langs->trans('LeadtrackerStatusDraft');
				$cls   = 'leadtracker-badge-draft';
				break;
			case 2:
				$label = $langs->trans('LeadtrackerStatusClosed');
				$cls   = 'leadtracker-badge-closed';
				break;
			default:
				return '';
		}

		return '<span class="leadtracker-badge '.$cls.'">'.dol_escape_htmltag($label).'</span>';
	}

	/**
	 *  HTML glyph for the circle interior.
	 *
	 *  @param  string  $state
	 *  @return string   HTML
	 */
	private function circleGlyph($state)
	{
		switch ($state) {
			case LeadProgressResolver::STATE_COMPLETE:
			case LeadProgressResolver::STATE_WON:
				return '<span class="leadtracker-glyph">&#10003;</span>';
			case LeadProgressResolver::STATE_LOST:
				return '<span class="leadtracker-glyph">&#10007;</span>';
			default:
				return '<span class="leadtracker-glyph"></span>';
		}
	}

	/**
	 *  Find the current stage key from the steps array.
	 *
	 *  @param  array  $steps
	 *  @return string
	 */
	private function currentCode($steps)
	{
		foreach ($steps as $s) {
			if ($s['state'] === LeadProgressResolver::STATE_CURRENT
				|| $s['state'] === LeadProgressResolver::STATE_WON
				|| $s['state'] === LeadProgressResolver::STATE_LOST) {
				return $s['key'];
			}
		}
		return '';
	}

	/**
	 *  Check if the user has project read permissions.
	 *
	 *  @param  User  $user
	 *  @return bool
	 */
	private function userCanProject($user)
	{
		if (!is_object($user)) {
			return false;
		}
		return $user->hasRight('projet', 'lire') || $user->hasRight('project', 'read');
	}
}
