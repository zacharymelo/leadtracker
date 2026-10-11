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
 *  \file       class/leadtrackergooglefeed.class.php
 *  \ingroup    leadtracker
 *  \brief      Builds the offline-conversion file that Google Ads imports on a schedule.
 */

dol_include_once('/leadtracker/class/leadtrackerevents.class.php');

/**
 *  LeadtrackerGoogleFeed
 *
 *  Google Ads can fetch a conversions file from an HTTPS address on a schedule.
 *  This class turns recorded stage events into that file, so nothing has to be
 *  pushed and no API credentials are needed.
 *
 *  The file is a rolling window rebuilt on every request. It is stateless on
 *  purpose: Google ignores a conversion it has already imported, so serving the
 *  same row on consecutive days is harmless.
 *
 *  A stage event becomes a row when all of these hold:
 *    - its stage category has a conversion name configured here,
 *    - the lead carries a Google click identifier in its attribution data,
 *    - it happened within the window.
 *  Only the first time a lead reaches a given conversion is exported, so a lead
 *  that leaves a stage and comes back is not counted twice.
 */
class LeadtrackerGoogleFeed
{
	/** Stage categories that can be mapped to a conversion name. */
	const CATEGORIES = array('lead', 'qualified', 'progress', 'sale');

	const WINDOW_DAYS = 90;
	const MAX_ROWS    = 5000;

	/** @var DoliDB */
	public $db;

	/** @var array{events:int,unmapped:int,no_click_id:int,repeat:int,exported:int}  Why events did or did not make it into the last build */
	public $stats = array('events' => 0, 'unmapped' => 0, 'no_click_id' => 0, 'repeat' => 0, 'exported' => 0);

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Conversion name configured for each category (empty = not exported).
	 *
	 *  @return array<string,string>
	 */
	public function getConversionNames()
	{
		$names = array();
		foreach (self::CATEGORIES as $category) {
			$names[$category] = trim(getDolGlobalString('LEADTRACKER_GOOGLE_CONV_'.strtoupper($category)));
		}
		return $names;
	}

	/**
	 *  Column headers of the file, in order.
	 *
	 *  @return string[]
	 */
	public function getHeaders()
	{
		$headers = array('Google Click ID', 'Conversion Name', 'Conversion Time', 'Conversion Value', 'Conversion Currency', 'Order ID');
		if (getDolGlobalString('LEADTRACKER_GOOGLE_HASHED_CONTACT') === '1') {
			$headers[] = 'Email';
			$headers[] = 'Phone Number';
		}
		if (getDolGlobalString('LEADTRACKER_GOOGLE_CONSENT') !== '') {
			$headers[] = 'Ad User Data';
			$headers[] = 'Ad Personalization';
		}
		return $headers;
	}

	/**
	 *  Build the rows of the file.
	 *
	 *  @return array<int,string[]>  Rows in the order of getHeaders()
	 */
	public function buildRows()
	{
		global $conf;

		$this->stats = array('events' => 0, 'unmapped' => 0, 'no_click_id' => 0, 'repeat' => 0, 'exported' => 0);

		$names      = $this->getConversionNames();
		$events     = new LeadtrackerEvents($this->db);
		$withHashes = getDolGlobalString('LEADTRACKER_GOOGLE_HASHED_CONTACT') === '1';
		$consent    = getDolGlobalString('LEADTRACKER_GOOGLE_CONSENT');

		$selection = $events->selectFirstOccurrences(self::WINDOW_DAYS);
		$this->stats['repeat'] = $selection['repeats'];
		$this->stats['events'] = count($selection['rows']) + $selection['repeats'];

		$rows = array();
		foreach ($selection['rows'] as $event) {
			$name = isset($names[$event['category']]) ? $names[$event['category']] : '';
			if ($name === '') {
				$this->stats['unmapped']++;
				continue;
			}

			// The file format only carries the standard click identifier.
			$clickId = self::extractClickId($event['attribution']);
			if ($clickId === '') {
				$this->stats['no_click_id']++;
				continue;
			}

			$row = array(
				$clickId,
				$name,
				gmdate('Y-m-d H:i:s', $event['ts']).'+0000',
				($event['value'] === null ? '' : number_format($event['value'], 2, '.', '')),
				($event['value'] === null ? '' : $conf->currency),
				$event['key'],
			);
			if ($withHashes) {
				$row[] = ($event['email'] !== '' ? hash('sha256', $event['email']) : '');
				$row[] = (($event['phone'] !== '' && $event['phone'][0] === '+') ? hash('sha256', $event['phone']) : '');
			}
			if ($consent !== '') {
				$row[] = $consent;
				$row[] = $consent;
			}

			$rows[] = $row;
			$this->stats['exported']++;
			if (count($rows) >= self::MAX_ROWS) {
				break;
			}
		}

		return $rows;
	}

	/**
	 *  Render the file.
	 *
	 *  The time zone is carried by each conversion time rather than by a leading
	 *  parameters line, so the header is always the first row.
	 *
	 *  @return string  CSV, UTF-8
	 */
	public function toCsv()
	{
		$rows = $this->buildRows();

		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $this->getHeaders(), ',', '"', '\\');
		foreach ($rows as $row) {
			fputcsv($handle, $row, ',', '"', '\\');
		}
		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);

		return (string) $csv;
	}

	/**
	 *  Find the Google click identifier in a lead's attribution data.
	 *
	 *  Uses the identifier captured from the landing URL, or failing that the one
	 *  held in the advertiser's first-party click cookie ("<prefix>.<timestamp>.<id>").
	 *
	 *  @param  array<string,string>  $attribution
	 *  @return string                              Empty if none
	 */
	public static function extractClickId($attribution)
	{
		$candidate = '';
		if (!empty($attribution['gclid'])) {
			$candidate = (string) $attribution['gclid'];
		} elseif (!empty($attribution['_gcl_aw'])) {
			$parts = explode('.', (string) $attribution['_gcl_aw']);
			if (count($parts) >= 3) {
				$candidate = (string) end($parts);
			}
		}
		$candidate = trim($candidate);
		// Anything outside the identifier alphabet means the value is not one.
		if ($candidate === '' || !preg_match('/^[A-Za-z0-9_-]{10,512}$/', $candidate)) {
			return '';
		}
		return $candidate;
	}
}
