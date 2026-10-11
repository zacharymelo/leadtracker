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
 *  \file       public/googleads.php
 *  \ingroup    leadtracker
 *  \brief      Serves the offline-conversion file fetched by Google Ads on a schedule.
 *
 *  Address to give Google Ads: .../custom/leadtracker/public/googleads.php/conversions.csv
 *  (the trailing file name is ignored here; it is there because the importer wants
 *  an address ending in .csv). Protected by HTTP Basic authentication.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = realpath(__FILE__);
$i = strlen($tmp) - 1;
while ($i > 0 && !$res) {
	if (file_exists(substr($tmp, 0, $i)."/main.inc.php")) {
		$res = @include substr($tmp, 0, $i)."/main.inc.php";
		break;
	}
	$i--;
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/leadtracker/class/leadtrackergooglefeed.class.php');

if (!isModEnabled('leadtracker') || getDolGlobalString('LEADTRACKER_GOOGLE_FEED_ENABLED') !== '1') {
	http_response_code(404);
	exit;
}

// Credentials. Some server setups do not expose PHP_AUTH_*; fall back to the raw header.
$givenUser = isset($_SERVER['PHP_AUTH_USER']) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
$givenPass = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
if ($givenUser === '') {
	$rawAuth = '';
	foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $authKey) {
		if (!empty($_SERVER[$authKey])) {
			$rawAuth = (string) $_SERVER[$authKey];
			break;
		}
	}
	if (stripos($rawAuth, 'basic ') === 0) {
		$decoded = base64_decode(substr($rawAuth, 6), true);
		if ($decoded !== false && strpos($decoded, ':') !== false) {
			list($givenUser, $givenPass) = explode(':', $decoded, 2);
		}
	}
}

$expectedUser = getDolGlobalString('LEADTRACKER_GOOGLE_FEED_USER');
$expectedPass = getDolGlobalString('LEADTRACKER_GOOGLE_FEED_PASSWORD');

// Both comparisons always run, so the response time says nothing about which one failed.
$userOk = hash_equals($expectedUser, $givenUser);
$passOk = hash_equals($expectedPass, $givenPass);
if ($expectedUser === '' || $expectedPass === '' || !$userOk || !$passOk) {
	usleep(500000);
	dol_syslog('leadtracker googleads feed: rejected credentials from '.getUserRemoteIP(), LOG_WARNING);
	header('WWW-Authenticate: Basic realm="Lead Tracker"');
	http_response_code(401);
	exit;
}

$feed = new LeadtrackerGoogleFeed($db);
$csv = $feed->toCsv();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: inline; filename="conversions.csv"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
print $csv;

$db->close();
