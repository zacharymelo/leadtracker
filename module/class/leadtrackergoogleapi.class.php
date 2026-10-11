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
 *  \file       class/leadtrackergoogleapi.class.php
 *  \ingroup    leadtracker
 *  \brief      Sends conversions to Google Ads through the Data Manager API.
 */

dol_include_once('/leadtracker/class/leadtrackerevents.class.php');
dol_include_once('/leadtracker/class/leadtrackergooglefeed.class.php');

/**
 *  LeadtrackerGoogleApi
 *
 *  Pushes the same conversions the file feed publishes, but through Google's
 *  Data Manager API. Compared with the file it also carries the two iOS click
 *  identifiers, can match a lead on hashed contact details when no click
 *  identifier was captured, and reports within minutes instead of once a day.
 *
 *  Authentication is a Google Cloud service account that has been added as a
 *  user of the Google Ads account: the key signs a short-lived token request, so
 *  no interactive sign-in and no token refresh storage is involved.
 *
 *  Both routes give a conversion the same identifier (the file's Order ID, the
 *  API's transaction ID), so a conversion that reaches Google by both is counted
 *  once.
 */
class LeadtrackerGoogleApi
{
	const DESTINATION = 'google';

	const TOKEN_URL = 'https://oauth2.googleapis.com/token';
	const API_URL   = 'https://datamanager.googleapis.com/v1/events:ingest';
	const SCOPE     = 'https://www.googleapis.com/auth/datamanager';

	/** Events per request. The API accepts more; small batches keep one bad event from holding back many. */
	const BATCH_SIZE = 200;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string */
	public $output = '';

	/** @var string|null  Access token for the current run */
	private $token = null;

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *  Whether the route is switched on and has what it needs to authenticate.
	 *
	 *  @return bool
	 */
	public function isEnabled()
	{
		return getDolGlobalString('LEADTRACKER_GOOGLE_API_ENABLED') === '1'
			&& $this->getCustomerId() !== ''
			&& $this->getServiceAccount() !== null;
	}

	/**
	 *  Google Ads customer ID that owns the conversion actions, digits only.
	 *
	 *  @return string
	 */
	public function getCustomerId()
	{
		return preg_replace('/\D/', '', getDolGlobalString('LEADTRACKER_GOOGLE_CUSTOMER_ID'));
	}

	/**
	 *  Conversion action ID configured for each category (empty = not sent).
	 *
	 *  @return array<string,string>
	 */
	public function getActionIds()
	{
		$ids = array();
		foreach (LeadtrackerGoogleFeed::CATEGORIES as $category) {
			$ids[$category] = preg_replace('/\D/', '', getDolGlobalString('LEADTRACKER_GOOGLE_ACTION_'.strtoupper($category)));
		}
		return $ids;
	}

	/**
	 *  The stored service account, reduced to what signing a token request needs.
	 *
	 *  @return array{client_email:string,private_key:string}|null
	 */
	public function getServiceAccount()
	{
		$data = json_decode(getDolGlobalString('LEADTRACKER_GOOGLE_SA_KEY'), true);
		if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])) {
			return null;
		}
		return array('client_email' => (string) $data['client_email'], 'private_key' => (string) $data['private_key']);
	}

	/**
	 *  Check a pasted service account key file and reduce it to what is stored.
	 *
	 *  @param  string  $json  Content of the key file downloaded from Google Cloud
	 *  @return string|null    JSON to store, null if the key is not usable
	 */
	public static function normalizeServiceAccountKey($json)
	{
		$data = json_decode(trim((string) $json), true);
		if (!is_array($data) || empty($data['client_email']) || empty($data['private_key'])
			|| !is_string($data['client_email']) || !is_string($data['private_key'])) {
			return null;
		}
		if (!filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
			return null;
		}
		$key = @openssl_pkey_get_private($data['private_key']);
		if ($key === false) {
			return null;
		}
		return json_encode(array('client_email' => $data['client_email'], 'private_key' => $data['private_key']));
	}

	/**
	 *  Send every conversion that is due.
	 *
	 *  @return int  0 if OK, <0 if the run could not start
	 */
	public function deliverPending()
	{
		$events   = new LeadtrackerEvents($this->db);
		$actions  = $this->getActionIds();
		$states   = $events->getDeliveryStates(self::DESTINATION);
		$selected = $events->selectFirstOccurrences(LeadtrackerGoogleFeed::WINDOW_DAYS);

		// One request per conversion action.
		$byAction = array();
		foreach ($selected['rows'] as $event) {
			$actionId = isset($actions[$event['category']]) ? $actions[$event['category']] : '';
			if ($actionId === '') {
				continue;
			}
			$state = isset($states[$event['event_id']]) ? $states[$event['event_id']] : null;
			if (!$events->isDeliveryDue($state)) {
				continue;
			}
			$payload = $this->buildEvent($event);
			if ($payload === null) {
				continue; // nothing to match the lead on
			}
			$byAction[$actionId][] = array('id' => $event['event_id'], 'payload' => $payload);
		}

		if (empty($byAction)) {
			$this->output = 'nothing to send';
			return 0;
		}

		$sent = 0;
		$failed = 0;
		foreach ($byAction as $actionId => $items) {
			foreach (array_chunk($items, self::BATCH_SIZE) as $chunk) {
				$result = $this->ingest((string) $actionId, array_column($chunk, 'payload'), false);

				// The API rejects a whole request for one bad event. Resend one by one
				// so the good ones go through and the bad one is identified.
				if (!$result['ok'] && count($chunk) > 1 && $result['code'] >= 400 && $result['code'] < 500 && $result['code'] != 401 && $result['code'] != 403) {
					foreach ($chunk as $item) {
						$single = $this->ingest((string) $actionId, array($item['payload']), false);
						$events->markDelivery($item['id'], self::DESTINATION, $single['ok'], $single['error'], $single['request_id']);
						$single['ok'] ? $sent++ : $failed++;
					}
					continue;
				}

				foreach ($chunk as $item) {
					$events->markDelivery($item['id'], self::DESTINATION, $result['ok'], $result['error'], $result['request_id']);
					$result['ok'] ? $sent++ : $failed++;
				}
			}
		}

		$this->output = $sent.' sent, '.$failed.' failed';
		return 0;
	}

	/**
	 *  Ask Google to validate a request without recording anything.
	 *  Proves the key, the account access and the conversion action in one call.
	 *
	 *  @return array{ok:bool,code:int,error:string,request_id:string}
	 */
	public function sendTest()
	{
		$actionId = '';
		foreach ($this->getActionIds() as $id) {
			if ($id !== '') {
				$actionId = $id;
				break;
			}
		}
		if ($actionId === '') {
			return array('ok' => false, 'code' => 0, 'error' => 'No conversion action ID configured', 'request_id' => '');
		}

		$event = array(
			'eventTimestamp' => gmdate('Y-m-d\TH:i:s\Z', dol_now() - 3600),
			'transactionId'  => 'lt-test-'.dol_now(),
			'eventSource'    => 'WEB',
			'userData'       => array('userIdentifiers' => array(array('emailAddress' => hash('sha256', 'test@example.com')))),
		);
		return $this->ingest($actionId, array($event), true);
	}

	/**
	 *  Turn a selected stage event into an API event.
	 *
	 *  @param  array<string,mixed>  $event  Row from LeadtrackerEvents::selectFirstOccurrences()
	 *  @return array<string,mixed>|null     Null when the lead carries nothing Google could match on
	 */
	public function buildEvent($event)
	{
		global $conf;

		$out = array(
			'eventTimestamp' => gmdate('Y-m-d\TH:i:s\Z', $event['ts']),
			'transactionId'  => $event['key'],
			'eventSource'    => 'WEB',
		);

		$attribution = $event['attribution'];
		$clickId = LeadtrackerGoogleFeed::extractClickId($attribution);
		if ($clickId !== '') {
			$out['adIdentifiers'] = array('gclid' => $clickId);
		} else {
			foreach (array('gbraid', 'wbraid') as $name) {
				if (!empty($attribution[$name]) && preg_match('/^[A-Za-z0-9_-]{10,512}$/', $attribution[$name])) {
					$out['adIdentifiers'] = array($name => $attribution[$name]);
					break;
				}
			}
		}

		if (getDolGlobalString('LEADTRACKER_GOOGLE_HASHED_CONTACT') === '1') {
			$identifiers = array();
			if ($event['email'] !== '') {
				$identifiers[] = array('emailAddress' => hash('sha256', $event['email']));
			}
			if ($event['phone'] !== '' && $event['phone'][0] === '+') {
				$identifiers[] = array('phoneNumber' => hash('sha256', $event['phone']));
			}
			if (!empty($identifiers)) {
				$out['userData'] = array('userIdentifiers' => $identifiers);
			}
		}

		if (!isset($out['adIdentifiers']) && !isset($out['userData'])) {
			return null;
		}

		if ($event['value'] !== null) {
			$out['conversionValue'] = round($event['value'], 2);
			$out['currency'] = $conf->currency;
		}

		return $out;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 *  Call events.ingest for one conversion action.
	 *
	 *  @param  string  $actionId
	 *  @param  array   $apiEvents
	 *  @param  bool    $validateOnly
	 *  @return array{ok:bool,code:int,error:string,request_id:string}
	 */
	private function ingest($actionId, $apiEvents, $validateOnly)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$fail = array('ok' => false, 'code' => 0, 'error' => '', 'request_id' => '');

		$token = $this->getAccessToken();
		if ($token === '') {
			$fail['error'] = 'Authentication failed: '.$this->error;
			return $fail;
		}

		$destination = array(
			'operatingAccount'     => array('accountType' => 'GOOGLE_ADS', 'accountId' => $this->getCustomerId()),
			'productDestinationId' => $actionId,
		);
		$loginId = preg_replace('/\D/', '', getDolGlobalString('LEADTRACKER_GOOGLE_LOGIN_CUSTOMER_ID'));
		if ($loginId !== '') {
			$destination['loginAccount'] = array('accountType' => 'GOOGLE_ADS', 'accountId' => $loginId);
		}

		$body = array(
			'destinations' => array($destination),
			'events'       => $apiEvents,
			'encoding'     => 'HEX',
			'validateOnly' => (bool) $validateOnly,
		);
		$consent = getDolGlobalString('LEADTRACKER_GOOGLE_CONSENT');
		if ($consent === 'Granted' || $consent === 'Denied') {
			$value = 'CONSENT_'.strtoupper($consent);
			$body['consent'] = array('adUserData' => $value, 'adPersonalization' => $value);
		}

		$result = getURLContent(
			getDolGlobalString('LEADTRACKER_GOOGLE_API_URL', self::API_URL),
			'POSTALREADYFORMATED',
			json_encode($body, JSON_UNESCAPED_SLASHES),
			0,
			array('Content-Type: application/json', 'Authorization: Bearer '.$token),
			array('http', 'https'),
			$this->localUrlMode(),
			-1,
			5,
			20
		);

		$code = isset($result['http_code']) ? (int) $result['http_code'] : 0;
		$data = json_decode(isset($result['content']) ? (string) $result['content'] : '', true);

		if ($code >= 200 && $code < 300) {
			return array('ok' => true, 'code' => $code, 'error' => '', 'request_id' => (is_array($data) && !empty($data['requestId'])) ? (string) $data['requestId'] : '');
		}

		$message = 'HTTP '.$code;
		if (is_array($data) && !empty($data['error']['message'])) {
			$message .= ' '.$data['error']['message'];
		} elseif (!empty($result['curl_error_msg'])) {
			$message .= ' '.$result['curl_error_msg'];
		}
		if ($code == 401) {
			$this->token = null;
		}
		dol_syslog(__METHOD__.' '.$message, LOG_WARNING);

		$fail['code'] = $code;
		$fail['error'] = $message;
		return $fail;
	}

	/**
	 *  Obtain an access token by signing a request with the service account key.
	 *
	 *  @return string  Empty on failure (reason in $this->error)
	 */
	private function getAccessToken()
	{
		if ($this->token !== null) {
			return $this->token;
		}

		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$account = $this->getServiceAccount();
		if ($account === null) {
			$this->error = 'No service account key';
			return '';
		}

		$tokenUrl = getDolGlobalString('LEADTRACKER_GOOGLE_TOKEN_URL', self::TOKEN_URL);
		$now = dol_now();
		$segments = array(
			self::base64Url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))),
			self::base64Url(json_encode(array(
				'iss'   => $account['client_email'],
				'scope' => self::SCOPE,
				'aud'   => self::TOKEN_URL,
				'iat'   => $now,
				'exp'   => $now + 3600,
			), JSON_UNESCAPED_SLASHES)),
		);
		$signature = '';
		$key = @openssl_pkey_get_private($account['private_key']);
		if ($key === false || !openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
			$this->error = 'Service account key could not sign';
			return '';
		}
		$segments[] = self::base64Url($signature);

		$result = getURLContent(
			$tokenUrl,
			'POST',
			'grant_type='.urlencode('urn:ietf:params:oauth:grant-type:jwt-bearer').'&assertion='.urlencode(implode('.', $segments)),
			0,
			array('Content-Type: application/x-www-form-urlencoded'),
			array('http', 'https'),
			$this->localUrlMode(),
			-1,
			5,
			15
		);

		$code = isset($result['http_code']) ? (int) $result['http_code'] : 0;
		$data = json_decode(isset($result['content']) ? (string) $result['content'] : '', true);
		if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
			$this->error = 'HTTP '.$code.(is_array($data) && !empty($data['error_description']) ? ' '.$data['error_description'] : '');
			dol_syslog(__METHOD__.' token request failed: '.$this->error, LOG_WARNING);
			return '';
		}

		$this->token = (string) $data['access_token'];
		return $this->token;
	}

	/**
	 *  External hosts only, unless a developer explicitly allows local receivers.
	 *
	 *  @return int
	 */
	private function localUrlMode()
	{
		return getDolGlobalString('LEADTRACKER_WEBHOOK_ALLOW_LOCAL') === '1' ? 2 : 0;
	}

	/**
	 *  @param  string  $data
	 *  @return string
	 */
	private static function base64Url($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
