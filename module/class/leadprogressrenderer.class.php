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
		$out = '<div class="leadtracker-tracker'.$classMode.'" role="list" aria-label="'.dol_escape_htmltag($langs->trans('LeadtrackerTitle')).'">';

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

		$out = '<div class="leadtracker-step '.$stateClass.'" role="listitem">';

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

		if ($canLink) {
			$out .= '</a>';
		}

		$out .= '</div>';

		return $out;
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
