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
 *  \file       class/leadtrackerevents.class.php
 *  \ingroup    leadtracker
 *  \brief      Records pipeline stage changes and delivers them to an outbound webhook.
 */

/**
 *  LeadtrackerEvents
 *
 *  Every stage change of a tracked lead is written to llx_leadtracker_stage_event.
 *  The table is both the stage history of the lead and the delivery queue of the
 *  outbound webhook.
 *
 *  Recording happens inside the caller's DB transaction (trigger context), so no
 *  HTTP call is made there: a rolled-back change must never be announced. Delivery
 *  is done afterwards by the scheduled job (deliverPending), or on demand from the
 *  setup page.
 *
 *  The payload is deliberately generic. It says which stage was reached, when, and
 *  what the lead is worth; mapping that onto a given destination's vocabulary is
 *  the receiver's job.
 */
class LeadtrackerEvents
{
	const STATUS_PENDING = 0;
	const STATUS_SENT    = 1;
	const STATUS_FAILED  = 2;
	const STATUS_SKIPPED = 3;

	const MAX_ATTEMPTS = 8;

	/** What reaching a stage means to the outside world. 'none' = never sent. */
	const CATEGORIES = array('none', 'lead', 'qualified', 'progress', 'sale', 'lost');

	/** @var array<int,string>|null  Stage rowid => category, resolved once per request */
	private $categoryMap = null;

	/** @var DoliDB */
	public $db;

	/** @var string  Error message, read by the scheduled-job runner */
	public $error = '';

	/** @var string  Result message, read by the scheduled-job runner */
	public $output = '';

	/**
	 *  @param  DoliDB  $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	// -------------------------------------------------------------------------
	// Stage categories
	// -------------------------------------------------------------------------

	/**
	 *  Category each active stage is reported as.
	 *
	 *  Receivers match on the category, so they need not know how a given
	 *  installation names or orders its pipeline. The admin's choice per stage is
	 *  stored in LEADTRACKER_STAGE_REPORT (JSON, stage rowid => category). A stage
	 *  with no stored choice gets a default: WON is a sale, LOST is lost, the first
	 *  stage of the pipeline is a lead, anything in between is progress.
	 *
	 *  @return array<int,string>
	 */
	public function getCategoryMap()
	{
		if ($this->categoryMap !== null) {
			return $this->categoryMap;
		}

		$stored = json_decode(getDolGlobalString('LEADTRACKER_STAGE_REPORT', '{}'), true);
		if (!is_array($stored)) {
			$stored = array();
		}

		$map = array();
		$first = true;
		$sql = "SELECT rowid, code FROM ".MAIN_DB_PREFIX."c_lead_status WHERE active = 1 ORDER BY position ASC";
		$res = $this->db->query($sql);
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$id = (int) $obj->rowid;
				if (isset($stored[$id]) && in_array($stored[$id], self::CATEGORIES, true)) {
					$map[$id] = $stored[$id];
				} elseif ($obj->code === 'WON') {
					$map[$id] = 'sale';
				} elseif ($obj->code === 'LOST') {
					$map[$id] = 'lost';
				} elseif ($first) {
					$map[$id] = 'lead';
				} else {
					$map[$id] = 'progress';
				}
				$first = false;
			}
		}

		$this->categoryMap = $map;
		return $map;
	}

	/**
	 *  Category of one stage. A stage that is inactive or unknown is 'progress'.
	 *
	 *  @param  int  $stageId
	 *  @return string
	 */
	public function getStageCategory($stageId)
	{
		$map = $this->getCategoryMap();
		return isset($map[(int) $stageId]) ? $map[(int) $stageId] : 'progress';
	}

	// -------------------------------------------------------------------------
	// Recording
	// -------------------------------------------------------------------------

	/**
	 *  Record a stage event if the project's stage differs from the last one recorded.
	 *
	 *  The comparison is against our own history rather than against the caller's
	 *  idea of the previous stage, so it catches changes however they were made
	 *  (automation, project card, API, mass action).
	 *
	 *  @param  int       $projectId
	 *  @param  string    $source      'auto' (automation), 'create' (new lead) or 'manual' (project saved)
	 *  @param  int|null  $knownFrom   Previous stage rowid when the caller knows it for certain
	 *  @return int                    Event rowid, 0 if nothing to record, <0 if KO
	 */
	public function recordIfChanged($projectId, $source = 'auto', $knownFrom = null)
	{
		$projectId = (int) $projectId;

		if (!function_exists('leadtrackerProjectPassesFilter')) {
			dol_include_once('/leadtracker/lib/leadtracker.lib.php');
		}
		if (function_exists('leadtrackerProjectPassesFilter')
			&& !leadtrackerProjectPassesFilter($projectId, $this->db)) {
			return 0;
		}

		$sql = "SELECT rowid, entity, fk_opp_status, opp_percent, opp_amount, usage_opportunity"
			." FROM ".MAIN_DB_PREFIX."projet"
			." WHERE rowid = ".$projectId
			." AND entity IN (".getEntity('projet').")";
		$res = $this->db->query($sql);
		if (!$res || !($proj = $this->db->fetch_object($res))) {
			return 0;
		}
		if (!(int) $proj->usage_opportunity) {
			return 0;
		}
		$current = (int) $proj->fk_opp_status;
		if ($current <= 0) {
			return 0;
		}

		$last = null;
		$sql = "SELECT rowid, fk_stage_to FROM ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." WHERE fk_project = ".$projectId
			." ORDER BY rowid DESC LIMIT 1";
		$res = $this->db->query($sql);
		if ($res) {
			$last = $this->db->fetch_object($res);
		}

		if ($last && (int) $last->fk_stage_to === $current) {
			return 0;
		}

		$from = null;
		if ($last) {
			$from = (int) $last->fk_stage_to;
		} elseif ($knownFrom !== null && (int) $knownFrom > 0 && (int) $knownFrom !== $current) {
			$from = (int) $knownFrom;
		}

		// A lead that existed before events were recorded, seen for the first time
		// without evidence of a real change: note where it stands, announce nothing.
		$isBaseline = (!$last && $from === null && $source !== 'create');

		$status = self::STATUS_PENDING;
		if ($isBaseline || !$this->isEnabled() || $this->getStageCategory($current) === 'none') {
			$status = self::STATUS_SKIPPED;
		}

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." (entity, fk_project, fk_stage_from, fk_stage_to, source, opp_percent, opp_amount, date_event, delivery_status)"
			." VALUES ("
			.((int) $proj->entity)
			.", ".$projectId
			.", ".($from === null ? "NULL" : (int) $from)
			.", ".$current
			.", '".$this->db->escape($isBaseline ? 'baseline' : $source)."'"
			.", ".($proj->opp_percent === null ? "NULL" : (float) $proj->opp_percent)
			.", ".($proj->opp_amount === null ? "NULL" : (float) $proj->opp_amount)
			.", '".$this->db->idate(dol_now())."'"
			.", ".$status
			.")";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX."leadtracker_stage_event");
	}

	// -------------------------------------------------------------------------
	// Delivery
	// -------------------------------------------------------------------------

	/**
	 *  Whether the outbound webhook is switched on and has a destination.
	 *
	 *  @return bool
	 */
	public function isEnabled()
	{
		return getDolGlobalString('LEADTRACKER_WEBHOOK_ENABLED') === '1'
			&& trim(getDolGlobalString('LEADTRACKER_WEBHOOK_URL')) !== '';
	}

	/**
	 *  Deliver pending stage events. Entry point of the scheduled job.
	 *
	 *  A failed delivery is retried with a growing delay (10 min, 20 min, 40 min ...
	 *  capped at one day) and abandoned after MAX_ATTEMPTS.
	 *
	 *  @param  int  $limit  Max events handled per run
	 *  @return int          0 if OK (scheduled-job convention), <0 if KO
	 */
	public function deliverPending($limit = 50)
	{
		global $conf;

		$limit = max(1, (int) $limit);
		$this->output = '';

		dol_include_once('/leadtracker/class/leadtrackergoogleapi.class.php');
		$google = new LeadtrackerGoogleApi($this->db);
		if ($google->isEnabled()) {
			$google->deliverPending();
			$this->output = 'Google Ads: '.$google->output.'. ';
		}

		if (!$this->isEnabled()) {
			$this->output .= 'Webhook: disabled';
			return 0;
		}

		$now   = dol_now();

		$sql = "SELECT rowid, attempts, date_last_try FROM ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." WHERE delivery_status = ".self::STATUS_PENDING
			." AND entity = ".((int) $conf->entity)
			." ORDER BY rowid ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		$due = array();
		while ($obj = $this->db->fetch_object($res)) {
			$attempts = (int) $obj->attempts;
			if ($attempts > 0 && $obj->date_last_try) {
				$wait = min(86400, 600 * pow(2, $attempts - 1));
				if ($this->db->jdate($obj->date_last_try) + $wait > $now) {
					continue;
				}
			}
			$due[] = (int) $obj->rowid;
			if (count($due) >= $limit) {
				break;
			}
		}

		$sent = 0;
		$failed = 0;
		foreach ($due as $eventId) {
			if ($this->deliver($eventId) > 0) {
				$sent++;
			} else {
				$failed++;
			}
		}

		$this->output .= 'Webhook: '.$sent.' sent, '.$failed.' failed';
		return 0;
	}

	/**
	 *  Deliver one event and update its queue state.
	 *
	 *  @param  int  $eventId
	 *  @return int             1 if delivered, 0 if not
	 */
	public function deliver($eventId)
	{
		$eventId = (int) $eventId;
		$payload = $this->buildPayload($eventId);

		if ($payload === null) {
			// The lead no longer exists: nothing meaningful can be sent.
			$this->markResult($eventId, self::STATUS_SKIPPED, 'Lead not found');
			return 0;
		}
		if ($payload['category'] === 'none') {
			// The stage was switched to "do not send" while the event was queued.
			$this->markResult($eventId, self::STATUS_SKIPPED, '');
			return 0;
		}

		$result = $this->post($payload);

		if ($result['ok']) {
			$sql = "UPDATE ".MAIN_DB_PREFIX."leadtracker_stage_event"
				." SET delivery_status = ".self::STATUS_SENT
				.", attempts = attempts + 1"
				.", date_last_try = '".$this->db->idate(dol_now())."'"
				.", date_sent = '".$this->db->idate(dol_now())."'"
				.", last_error = NULL"
				." WHERE rowid = ".$eventId;
			$this->db->query($sql);
			return 1;
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." SET attempts = attempts + 1"
			.", date_last_try = '".$this->db->idate(dol_now())."'"
			.", last_error = '".$this->db->escape(dol_trunc($result['error'], 250, 'right', 'UTF-8', 1))."'"
			.", delivery_status = CASE WHEN attempts + 1 >= ".self::MAX_ATTEMPTS
			." THEN ".self::STATUS_FAILED." ELSE ".self::STATUS_PENDING." END"
			." WHERE rowid = ".$eventId;
		$this->db->query($sql);
		return 0;
	}

	/**
	 *  Put abandoned events back in the queue.
	 *
	 *  @return int  Number of events requeued, <0 if KO
	 */
	public function requeueFailed()
	{
		global $conf;

		$sql = "UPDATE ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." SET delivery_status = ".self::STATUS_PENDING.", attempts = 0, date_last_try = NULL"
			." WHERE delivery_status = ".self::STATUS_FAILED
			." AND entity = ".((int) $conf->entity);
		if (!$this->db->query($sql)) {
			return -1;
		}
		$count = (int) $this->db->affected_rows(null);

		$sql = "UPDATE ".MAIN_DB_PREFIX."leadtracker_event_delivery"
			." SET status = ".self::STATUS_PENDING.", attempts = 0, date_last_try = NULL"
			." WHERE status = ".self::STATUS_FAILED;
		if ($this->db->query($sql)) {
			$count += (int) $this->db->affected_rows(null);
		}
		return $count;
	}

	/**
	 *  Send a synthetic event so the receiving end can be wired up and checked.
	 *
	 *  @return array{ok:bool,code:int,error:string}
	 */
	public function sendTest()
	{
		global $conf;

		$now = dol_now();
		$payload = array(
			'event'       => 'test',
			'category'    => 'lead',
			'event_id'    => 'lt-test-'.$now,
			'event_name'  => 'lead_stage_test',
			'occurred_at' => gmdate('c', $now),
			'occurred_ts' => $now,
			'source'      => 'test',
			'stage'       => array('code' => 'TEST', 'label' => 'Test', 'position' => 0, 'from_code' => null),
			'value'       => array('amount' => 1000.0, 'percent' => 50.0, 'weighted' => 500.0, 'currency' => $conf->currency),
			'lead'        => array('id' => 0, 'ref' => 'TEST', 'created_ts' => $now),
			'contact'     => array('email_sha256' => hash('sha256', 'test@example.com')),
			'attribution' => array(),
		);
		return $this->post($payload);
	}

	/**
	 *  Assemble the JSON document for one event.
	 *
	 *  Stage, amount and probability are the values frozen when the event was
	 *  recorded. Contact and attribution are read now.
	 *
	 *  @param  int  $eventId
	 *  @return array|null     Null if the event or its lead cannot be loaded
	 */
	public function buildPayload($eventId)
	{
		global $conf;

		$sql = "SELECT e.rowid, e.entity, e.fk_project, e.fk_stage_to, e.source, e.opp_percent, e.opp_amount, e.date_event,"
			." p.ref, p.datec, p.fk_soc,"
			." st.code as to_code, st.label as to_label, st.position as to_position,"
			." sf.code as from_code,"
			." s.email, s.phone, s.nom as soc_name"
			." FROM ".MAIN_DB_PREFIX."leadtracker_stage_event e"
			." INNER JOIN ".MAIN_DB_PREFIX."projet p ON p.rowid = e.fk_project"
			." LEFT JOIN ".MAIN_DB_PREFIX."c_lead_status st ON st.rowid = e.fk_stage_to"
			." LEFT JOIN ".MAIN_DB_PREFIX."c_lead_status sf ON sf.rowid = e.fk_stage_from"
			." LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = p.fk_soc"
			." WHERE e.rowid = ".((int) $eventId);
		$res = $this->db->query($sql);
		if (!$res || !($obj = $this->db->fetch_object($res))) {
			return null;
		}

		$ts      = $this->db->jdate($obj->date_event);
		$amount  = ($obj->opp_amount === null) ? null : (float) $obj->opp_amount;
		$percent = ($obj->opp_percent === null) ? null : (float) $obj->opp_percent;
		$code    = (string) $obj->to_code;

		$contact = array();
		$email = strtolower(trim((string) $obj->email));
		if ($email !== '') {
			$contact['email_sha256'] = hash('sha256', $email);
		}
		// Only a number already in international form can be hashed consistently.
		$phone = preg_replace('/[^\d+]/', '', (string) $obj->phone);
		if ($phone !== '' && $phone[0] === '+') {
			$contact['phone_sha256'] = hash('sha256', $phone);
		}
		if (getDolGlobalString('LEADTRACKER_WEBHOOK_CONTACT') === '1') {
			$contact['email'] = $email;
			$contact['phone'] = (string) $obj->phone;
			$contact['name']  = (string) $obj->soc_name;
		}

		$rawAttribution = $this->getAttributionToken((int) $obj->fk_project);

		return array(
			'event'       => 'lead_stage_change',
			'category'    => $this->getStageCategory((int) $obj->fk_stage_to),
			'event_id'    => 'lt-'.((int) $obj->entity).'-'.((int) $obj->rowid),
			'event_name'  => 'lead_stage_'.strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $code)),
			'occurred_at' => gmdate('c', $ts),
			'occurred_ts' => (int) $ts,
			'source'      => (string) $obj->source,
			'stage'       => array(
				'code'      => $code,
				'label'     => (string) $obj->to_label,
				'position'  => (int) $obj->to_position,
				'from_code' => ($obj->from_code === null ? null : (string) $obj->from_code),
			),
			'value'       => array(
				'amount'   => $amount,
				'percent'  => $percent,
				'weighted' => ($amount === null || $percent === null) ? null : round($amount * $percent / 100, 2),
				'currency' => $conf->currency,
			),
			'lead'        => array(
				'id'         => (int) $obj->fk_project,
				'ref'        => (string) $obj->ref,
				'created_ts' => (int) $this->db->jdate($obj->datec),
			),
			'contact'         => $contact,
			'attribution'     => self::decodeAttribution($rawAttribution),
			'attribution_raw' => $rawAttribution,
		);
	}

	/**
	 *  Decode an attribution token into a flat map.
	 *
	 *  Accepts a JSON object, or base64url-encoded JSON optionally preceded by a
	 *  "<tag>." format marker. Anything else yields an empty map; the raw token is
	 *  still passed along untouched.
	 *
	 *  @param  string  $token
	 *  @return array<string,string>
	 */
	public static function decodeAttribution($token)
	{
		$token = trim((string) $token);
		if ($token === '') {
			return array();
		}

		$json = null;
		if ($token[0] === '{') {
			$json = $token;
		} else {
			$body = $token;
			if (preg_match('/^[A-Za-z0-9]+\.([A-Za-z0-9_-]+)$/', $token, $m)) {
				$body = $m[1];
			}
			if (preg_match('/^[A-Za-z0-9_-]+$/', $body)) {
				$decoded = base64_decode(strtr($body, '-_', '+/'), true);
				if ($decoded !== false) {
					$json = $decoded;
				}
			}
		}
		if ($json === null) {
			return array();
		}

		$data = json_decode($json, true);
		if (!is_array($data)) {
			return array();
		}

		$out = array();
		foreach ($data as $key => $value) {
			if (!is_scalar($value) || count($out) >= 60) {
				continue;
			}
			$key = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $key);
			if ($key === '') {
				continue;
			}
			$out[$key] = dol_trunc(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value), 1000, 'right', 'UTF-8', 1);
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Shared by the destinations that report conversions
	// -------------------------------------------------------------------------

	/**
	 *  The first time each lead reached each category, for events inside a window.
	 *
	 *  A lead that leaves a stage and comes back must not be reported twice, so
	 *  the whole history is read: an old first occurrence still shadows a recent
	 *  repeat. Baseline rows (where a pre-existing lead merely stood) are ignored.
	 *
	 *  Each returned row carries: event_id, key (stable per lead and category),
	 *  category, project_id, ts, amount, percent, value, email, phone, attribution.
	 *  value is the lead amount for a sale and the probability-weighted amount
	 *  before that; null when it would be zero, so that the receiving platform's
	 *  own default applies instead of the lead being declared worthless.
	 *
	 *  @param  int  $windowDays
	 *  @return array{rows:array<int,array<string,mixed>>,repeats:int}
	 */
	public function selectFirstOccurrences($windowDays)
	{
		global $conf;

		$out = array('rows' => array(), 'repeats' => 0);
		$since = dol_now() - ((int) $windowDays) * 86400;

		// The attribution column is absent on installs where the field was never created.
		$res = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields WHERE name = 'lt_attribution' AND elementtype = 'projet'");
		$hasField = ($res && $this->db->num_rows($res) > 0);

		$sql = "SELECT e.rowid, e.entity, e.fk_project, e.fk_stage_to, e.opp_percent, e.opp_amount, e.date_event,"
			." s.email, s.phone"
			.($hasField ? ", ef.lt_attribution" : ", NULL as lt_attribution")
			." FROM ".MAIN_DB_PREFIX."leadtracker_stage_event e"
			." INNER JOIN ".MAIN_DB_PREFIX."projet p ON p.rowid = e.fk_project"
			." LEFT JOIN ".MAIN_DB_PREFIX."societe s ON s.rowid = p.fk_soc"
			.($hasField ? " LEFT JOIN ".MAIN_DB_PREFIX."projet_extrafields ef ON ef.fk_object = p.rowid" : "")
			." WHERE e.source <> 'baseline'"
			." AND e.entity = ".((int) $conf->entity)
			." ORDER BY e.rowid ASC";
		$res = $this->db->query($sql);
		if (!$res) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return $out;
		}

		$seen = array();
		while ($obj = $this->db->fetch_object($res)) {
			$category = $this->getStageCategory((int) $obj->fk_stage_to);
			$key = 'lt-'.((int) $obj->entity).'-'.((int) $obj->fk_project).'-'.$category;
			$isRepeat = isset($seen[$key]);
			$seen[$key] = true;

			$ts = $this->db->jdate($obj->date_event);
			if ($ts < $since || $category === 'none') {
				continue;
			}
			if ($isRepeat) {
				$out['repeats']++;
				continue;
			}

			$amount  = ($obj->opp_amount === null) ? null : (float) $obj->opp_amount;
			$percent = ($obj->opp_percent === null) ? null : (float) $obj->opp_percent;
			if ($category === 'sale') {
				$value = $amount;
			} else {
				$value = ($amount === null || $percent === null) ? null : $amount * $percent / 100;
			}
			if ($value !== null && $value <= 0) {
				$value = null;
			}

			$out['rows'][] = array(
				'event_id'    => (int) $obj->rowid,
				'key'         => $key,
				'category'    => $category,
				'project_id'  => (int) $obj->fk_project,
				'ts'          => (int) $ts,
				'amount'      => $amount,
				'percent'     => $percent,
				'value'       => $value,
				'email'       => strtolower(trim((string) $obj->email)),
				'phone'       => preg_replace('/[^\d+]/', '', (string) $obj->phone),
				'attribution' => self::decodeAttribution((string) $obj->lt_attribution),
			);
		}

		return $out;
	}

	/**
	 *  Delivery state of events for one destination.
	 *
	 *  @param  string  $destination
	 *  @return array<int,object>  Keyed by event rowid
	 */
	public function getDeliveryStates($destination)
	{
		$states = array();
		$sql = "SELECT fk_event, status, attempts, date_last_try FROM ".MAIN_DB_PREFIX."leadtracker_event_delivery"
			." WHERE destination = '".$this->db->escape($destination)."'";
		$res = $this->db->query($sql);
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$states[(int) $obj->fk_event] = $obj;
			}
		}
		return $states;
	}

	/**
	 *  Whether an event is due for a (re)try at a destination, given its state.
	 *  Same policy as the webhook: growing delay, abandoned after MAX_ATTEMPTS.
	 *
	 *  @param  object|null  $state  Row from getDeliveryStates(), null if never tried
	 *  @return bool
	 */
	public function isDeliveryDue($state)
	{
		if (!$state) {
			return true;
		}
		if ((int) $state->status !== self::STATUS_PENDING) {
			return false;
		}
		$attempts = (int) $state->attempts;
		if ($attempts > 0 && $state->date_last_try) {
			$wait = min(86400, 600 * pow(2, $attempts - 1));
			if ($this->db->jdate($state->date_last_try) + $wait > dol_now()) {
				return false;
			}
		}
		return true;
	}

	/**
	 *  Record the outcome of a delivery attempt at a destination.
	 *
	 *  @param  int     $eventId
	 *  @param  string  $destination
	 *  @param  bool    $ok
	 *  @param  string  $error     Reason when not ok
	 *  @param  string  $remoteId  Identifier returned by the destination, if any
	 *  @return void
	 */
	public function markDelivery($eventId, $destination, $ok, $error = '', $remoteId = '')
	{
		$now  = "'".$this->db->idate(dol_now())."'";
		$dest = "'".$this->db->escape($destination)."'";
		$err  = "'".$this->db->escape(dol_trunc($error, 250, 'right', 'UTF-8', 1))."'";
		$rid  = "'".$this->db->escape(dol_trunc($remoteId, 120, 'right', 'UTF-8', 1))."'";

		$sql = "INSERT INTO ".MAIN_DB_PREFIX."leadtracker_event_delivery"
			." (fk_event, destination, status, attempts, date_last_try, date_sent, remote_id, last_error)"
			." VALUES (".((int) $eventId).", ".$dest.", ".($ok ? self::STATUS_SENT : self::STATUS_PENDING).", 1, ".$now
			.", ".($ok ? $now : "NULL").", ".($ok ? $rid : "NULL").", ".($ok ? "NULL" : $err).")"
			." ON DUPLICATE KEY UPDATE"
			." attempts = attempts + 1, date_last_try = ".$now;
		if ($ok) {
			$sql .= ", status = ".self::STATUS_SENT.", date_sent = ".$now.", remote_id = ".$rid.", last_error = NULL";
		} else {
			$sql .= ", last_error = ".$err
				.", status = CASE WHEN attempts >= ".self::MAX_ATTEMPTS." THEN ".self::STATUS_FAILED." ELSE ".self::STATUS_PENDING." END";
		}
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		}
	}

	/**
	 *  Count deliveries per status for one destination.
	 *
	 *  @param  string  $destination
	 *  @return array<int,int>  Status => count
	 */
	public function countDeliveries($destination)
	{
		$counts = array(self::STATUS_PENDING => 0, self::STATUS_SENT => 0, self::STATUS_FAILED => 0);
		$sql = "SELECT status, COUNT(*) as nb FROM ".MAIN_DB_PREFIX."leadtracker_event_delivery"
			." WHERE destination = '".$this->db->escape($destination)."' GROUP BY status";
		$res = $this->db->query($sql);
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$counts[(int) $obj->status] = (int) $obj->nb;
			}
		}
		return $counts;
	}

	/**
	 *  Recent events for the setup page.
	 *
	 *  @param  int  $limit
	 *  @return array
	 */
	public function fetchRecent($limit = 10)
	{
		global $conf;

		$rows = array();
		$sql = "SELECT e.rowid, e.fk_project, e.fk_stage_to, e.source, e.date_event, e.delivery_status, e.attempts, e.last_error,"
			." e.opp_amount, e.opp_percent, p.ref, st.code as to_code, sf.code as from_code"
			." FROM ".MAIN_DB_PREFIX."leadtracker_stage_event e"
			." LEFT JOIN ".MAIN_DB_PREFIX."projet p ON p.rowid = e.fk_project"
			." LEFT JOIN ".MAIN_DB_PREFIX."c_lead_status st ON st.rowid = e.fk_stage_to"
			." LEFT JOIN ".MAIN_DB_PREFIX."c_lead_status sf ON sf.rowid = e.fk_stage_from"
			." WHERE e.entity = ".((int) $conf->entity)
			." ORDER BY e.rowid DESC LIMIT ".max(1, (int) $limit);
		$res = $this->db->query($sql);
		if ($res) {
			while ($obj = $this->db->fetch_object($res)) {
				$rows[] = $obj;
			}
		}
		return $rows;
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 *  POST a payload to the configured URL, signed with the shared secret.
	 *
	 *  The signature is an HMAC-SHA256 of the exact request body, sent as
	 *  "X-Leadtracker-Signature: sha256=<hex>", so the receiver can check that the
	 *  call really comes from this installation.
	 *
	 *  @param  array  $payload
	 *  @return array{ok:bool,code:int,error:string}
	 */
	private function post($payload)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/geturl.lib.php';

		$url = trim(getDolGlobalString('LEADTRACKER_WEBHOOK_URL'));
		if ($url === '') {
			return array('ok' => false, 'code' => 0, 'error' => 'No destination URL');
		}

		$body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($body === false) {
			return array('ok' => false, 'code' => 0, 'error' => 'Payload could not be encoded');
		}

		$headers = array(
			'Content-Type: application/json',
			'User-Agent: Dolibarr-Leadtracker',
			'X-Leadtracker-Event: '.$payload['event'],
			'X-Leadtracker-Event-Id: '.$payload['event_id'],
		);
		$secret = getDolGlobalString('LEADTRACKER_WEBHOOK_SECRET');
		if ($secret !== '') {
			$headers[] = 'X-Leadtracker-Signature: sha256='.hash_hmac('sha256', $body, $secret);
		}

		// External hosts only, unless a developer explicitly allows local receivers.
		$localurl = getDolGlobalString('LEADTRACKER_WEBHOOK_ALLOW_LOCAL') === '1' ? 2 : 0;

		$result = getURLContent($url, 'POSTALREADYFORMATED', $body, 0, $headers, array('http', 'https'), $localurl, -1, 5, 10);

		$code = isset($result['http_code']) ? (int) $result['http_code'] : 0;
		if ($code >= 200 && $code < 300) {
			return array('ok' => true, 'code' => $code, 'error' => '');
		}

		$error = 'HTTP '.$code;
		if (!empty($result['curl_error_msg'])) {
			$error .= ' '.$result['curl_error_msg'];
		}
		dol_syslog(__METHOD__.' delivery failed: '.$error, LOG_WARNING);
		return array('ok' => false, 'code' => $code, 'error' => $error);
	}

	/**
	 *  Read the attribution token stored on the lead, if the field exists.
	 *
	 *  @param  int  $projectId
	 *  @return string
	 */
	private function getAttributionToken($projectId)
	{
		// The column is absent on installs where the field was never created.
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."extrafields"
			." WHERE name = 'lt_attribution' AND elementtype = 'projet'";
		$res = $this->db->query($sql);
		if (!$res || $this->db->num_rows($res) == 0) {
			return '';
		}

		$sql = "SELECT lt_attribution as val FROM ".MAIN_DB_PREFIX."projet_extrafields"
			." WHERE fk_object = ".((int) $projectId);
		$res = $this->db->query($sql);
		if ($res && ($obj = $this->db->fetch_object($res)) && $obj->val !== null) {
			return (string) $obj->val;
		}
		return '';
	}

	/**
	 *  Set a final state on an event without attempting delivery.
	 *
	 *  @param  int     $eventId
	 *  @param  int     $status
	 *  @param  string  $message
	 *  @return void
	 */
	private function markResult($eventId, $status, $message)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX."leadtracker_stage_event"
			." SET delivery_status = ".((int) $status)
			.", last_error = '".$this->db->escape($message)."'"
			." WHERE rowid = ".((int) $eventId);
		$this->db->query($sql);
	}
}
