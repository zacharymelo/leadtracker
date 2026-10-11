# Lead Tracker — Dolibarr Module

Lead Tracker turns Dolibarr's Projects module into a working sales pipeline. Dolibarr already stores an opportunity status and probability on each project, but it gives you no at-a-glance view of where a lead stands, no automatic way to keep that status current as proposals, orders and invoices accumulate, and no way to tell the systems that generated the lead what became of it. Lead Tracker fills those three gaps.

## Contents

- [What it does](#what-it-does)
- [Requirements](#requirements)
- [Installation and upgrading](#installation-and-upgrading)
- [Configuration](#configuration)
- [Reporting lead outcomes](#reporting-lead-outcomes)
  - [How the pieces fit](#how-the-pieces-fit)
  - [Attribution data](#attribution-data)
  - [Stage categories ("Report as")](#stage-categories-report-as)
  - [Outbound webhook](#outbound-webhook)
  - [Google Ads](#google-ads)
- [Troubleshooting](#troubleshooting)
- [Privacy and security notes](#privacy-and-security-notes)
- [What the module stores](#what-the-module-stores)
- [Development](#development)
- [Licence](#licence)

## What it does

### Visual pipeline bar
A horizontal step indicator is added to every project card that has **Follow opportunity** checked. Each circle is a stage from your lead status dictionary (Home → Setup → Dictionaries → Lead status). The bar shows where the lead is and what has already happened.

Stage states are worked out from live evidence. The module inspects linked contacts, proposals, orders and invoices on every page load instead of relying only on the stored status, so the bar reflects reality even if an event was processed out of order or a document was linked after the fact.

### Deal map
An expandable branching view beneath the bar shows the lead and every linked proposal, order, invoice and shipment, with optional correspondence and call events.

### Automatic stage advancement
A trigger fires on every relevant native event:

| Event | Dolibarr action |
|---|---|
| `PROPAL_VALIDATE` | Proposal sent to client |
| `PROPAL_SIGN` | Proposal accepted |
| `ORDER_VALIDATE` / `COMMANDE_VALIDATE` | Order confirmed |
| `BILL_VALIDATE` | Invoice issued |
| `ACTION_CREATE` / `ACTION_MODIFY` | Contact logged (call, email, meeting) |
| `*_SENTBYMAIL` | A document or the project was emailed |

The module evaluates the conditions you configured for the stages ahead of the current one and advances the lead to the first stage whose conditions are met. Any one matching condition is enough. Draft projects are not advanced, and closed projects are left untouched.

### Lead amount tracking
When **Lead amount source** is Auto, the opportunity amount follows a closest-to-cash hierarchy:

1. **Sum of validated invoices**: real money billed, accumulating as a lifetime total
2. **Sum of validated orders**: committed spend
3. **Sum of accepted proposals**: confirmed intent
4. **Average of open proposals**: estimated value while the deal is forming
5. **A numeric project attribute of your choice**: an estimate captured at intake, used only until a document exists

The amount is recalculated on every trigger event, not only when the stage advances. A switch on each project card turns the automatic recalculation off for that lead.

### Probability sync
When **Probability percentage** is Auto, the default percentage of the stage reached is written to the lead whenever the stage changes.

### Outcome reporting
Every stage change is recorded, and can be reported to outside systems: as a signed JSON event to any URL, and as conversions to Google Ads. See [Reporting lead outcomes](#reporting-lead-outcomes).

## Requirements

- Dolibarr 14.0 or later (tested on 22.x)
- PHP 7.0 or later (tested on 8.2), with the `openssl` and `curl` extensions for outcome reporting
- Projects module enabled, with opportunities in use
- Scheduled jobs module enabled and running, for outcome reporting only

## Installation and upgrading

1. Download the latest zip from [`bin/`](bin/).
2. In Dolibarr: Home → Setup → Modules → Deploy external module, and upload the zip.
3. Enable **Lead Tracker** in the module list.
4. Open the module's setup page to configure it.

**When upgrading, disable the module and enable it again after uploading the new zip.** New database tables, the attribution attribute and the scheduled job are only created when the module is enabled. Disabling never deletes data or settings. If you skip this step, lead handling keeps working, but stage changes are not recorded and an error is written to the Dolibarr log each time.

Everything related to outcome reporting is off until you switch it on.

## Configuration

All settings are on one page: Home → Setup → Modules → Lead Tracker → setup.

### Stage table
One row per stage of your lead status dictionary.

**Conditions** decide what qualifies a lead as having reached the stage. Tick one or more per stage.

| Condition | Satisfied when |
|---|---|
| Contact | An outbound call, email or meeting is logged, or a document is emailed |
| Proposal | At least one validated proposal exists |
| Signed | At least one accepted proposal exists |
| Order | At least one validated order exists |
| Invoice | At least one validated invoice exists |
| Manual | The stage is never reached automatically |

**Report as** decides what reaching the stage means to outside systems. See [Stage categories](#stage-categories-report-as).

### Data sync
| Setting | Options |
|---|---|
| Lead amount source | Manual / Auto (hierarchy above) |
| Baseline attribute | Code of a numeric project attribute used as the intake estimate |
| Probability percentage | Manual / Auto (stage default) |

Both sources default to Manual, so existing data is not touched until you opt in.

### Opportunity filter
The tracker only applies to projects with **Follow opportunity** checked. That gate cannot be disabled. An optional category filter restricts the tracker to a subset of those projects, for example one team or product line.

### Display
- Full mode (circles and labels) or compact mode (circles only)
- Colours for completed, current, pending and lost stages
- Action link on the current stage that quick-creates the next native document
- Deal map options: date layout, correspondence events, calls, warranty items
- A debug panel for administrators

## Reporting lead outcomes

### How the pieces fit

```
 lead captured            stage changes              destinations
┌───────────────┐      ┌──────────────────┐      ┌──────────────────────┐
│ attribution   │      │ recorded with    │      │ webhook (any URL)    │
│ token stored  │ ───▶ │ time, amount,    │ ───▶ │ Google Ads file      │
│ on the lead   │      │ probability and  │      │ Google Ads API       │
└───────────────┘      │ a category       │      └──────────────────────┘
                       └──────────────────┘
```

1. **At intake**, whatever creates the lead stores an attribution token on it: the click identifiers and campaign parameters seen when the visitor arrived.
2. **As the lead progresses**, every stage change is recorded with its time, the lead amount and the probability at that moment. This happens whether the automation advanced the lead or someone changed the stage by hand.
3. **A scheduled job** delivers those records to the destinations you enabled, every five minutes.

Each destination is independent and optional. Leads that existed before the module started recording are not announced retroactively; their next stage change is.

### Attribution data

The module creates a hidden long-text project attribute, `lt_attribution`, when it is enabled. Lead intake writes one token into it and the module never changes it.

The token is a flat map of names to values, in either of these forms:

- a JSON object: `{"gclid":"...","utm_source":"..."}`
- base64url-encoded JSON, optionally preceded by a short format tag and a dot: `v1.eyJnY2xpZCI6Ii4uLiJ9`

The second form survives email templates and form tools that would mangle quotes and braces. The names inside are whatever the capturing side recorded. The module only gives meaning to the ones a destination needs, such as `gclid`.

How the token gets there is up to you: an email collector rule that extracts it into `options_lt_attribution`, an API call, or an import.

### Stage categories ("Report as")

Receiving systems should not need to know how your pipeline is named. So each stage is given a category in the **Report as** column of the stage table:

| Category | Meaning |
|---|---|
| Lead | A new lead |
| Qualified lead | A lead worth pursuing |
| Other progress | Any other step forward |
| Sale | The deal is won |
| Lost | The deal is lost |
| Do not send | Kept in the lead's history, never reported |

Until you choose, the first stage is a lead, `WON` is a sale, `LOST` is lost, and the rest are other progress.

Each lead is reported **once per category** to the conversion destinations, at the time it first got there. A lead that leaves a stage and returns is not counted twice.

**Values.** A sale is valued at the lead amount. Earlier stages are valued at the amount multiplied by the probability, so a lead at 40% is worth 40% of its amount. When that comes to zero, no value is sent, so the destination applies its own default.

### Outbound webhook

Posts every stage change as JSON to a URL of your choice: a tag server, an automation tool, or your own service.

**Setup.** Tick **Send stage events**, enter an `https://` destination and save. A signing secret is generated. Use **Send a test event** to check the receiving end.

**Payload.**

```json
{
  "event": "lead_stage_change",
  "category": "progress",
  "event_id": "lt-1-42",
  "event_name": "lead_stage_propo",
  "occurred_at": "2026-01-15T14:03:22+00:00",
  "occurred_ts": 1768485802,
  "source": "auto",
  "stage": { "code": "PROPO", "label": "Proposal", "position": 30, "from_code": "QUAL" },
  "value": { "amount": 1000.0, "percent": 40.0, "weighted": 400.0, "currency": "EUR" },
  "lead": { "id": 12, "ref": "PJ2601-0012", "created_ts": 1768300000 },
  "contact": { "email_sha256": "...", "phone_sha256": "..." },
  "attribution": { "example_click_id": "abc123" },
  "attribution_raw": "..."
}
```

| Field | Notes |
|---|---|
| `category` | The stage's **Report as** category. Match on this. |
| `event_id` | Stable per stage change. Use it to ignore a repeat delivery. |
| `event_name` | `lead_stage_` plus the stage code in lower case. |
| `source` | `auto` (automation), `manual` (project saved) or `create` (new lead). |
| `value.weighted` | Amount multiplied by probability. |
| `contact` | SHA-256 of the lower-cased email, and of the phone when stored in international `+` form. Name, email and phone in clear are added only if you tick **Include contact details in clear**. |
| `attribution` | The decoded token, passed through as-is. |

Unlike the conversion destinations, the webhook sends every stage change, including repeats and lost leads.

**Verifying the signature.** Each request carries `X-Leadtracker-Signature: sha256=<hex>`, the HMAC-SHA256 of the raw request body using the signing secret.

```php
$body = file_get_contents('php://input');
$expected = 'sha256='.hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_LEADTRACKER_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

**Delivery.** Any 2xx response counts as delivered. A failure is retried after 10 minutes, then 20, then 40, doubling up to a day, and abandoned after eight attempts. **Retry failed events** puts abandoned ones back in the queue. Events recorded while the webhook was off are not sent later.

### Google Ads

Reports which ad clicks became leads and which became sales, so Google can optimise for buyers instead of form submissions. There are two routes. They report the same conversions and can run together without double counting, because a conversion carries the same identifier on both.

| | Conversion file | Data Manager API |
|---|---|---|
| How it works | Google fetches a file from your Dolibarr on a schedule | Dolibarr pushes to Google every five minutes |
| Google-side setup | A scheduled import | A Google Cloud service account |
| Delay | Up to a day | Minutes |
| Standard click identifier (`gclid`) | Yes | Yes |
| iOS click identifiers (`gbraid`, `wbraid`) | No | Yes |
| Leads with no click identifier | No | Yes, matched on hashed email or phone |
| Identifies a conversion action by | Name | Numeric ID |

Both read the last 90 days of recorded history. **When you first enable a route, stage changes from the previous 90 days are reported**, for leads that can be matched.

A lead is matched by the click identifier in its attribution data: `gclid`, or the one held in a `_gcl_aw` cookie value. Conversion actions in Google Ads must be of the "import from clicks" kind.

Two settings are shared by both routes:

- **Add hashed email and phone**: includes SHA-256 hashes of the third party's email and phone. Leave off for a click-identifier-only file import.
- **Consent stated**: declares ad user data and ad personalisation consent on every conversion. Only state what your own consent collection supports.

#### Route 1: conversion file

1. In Google Ads, create one conversion action for each outcome you want reported.
2. On the setup page, enter those names, exactly as written in Google Ads, against the matching categories. Tick **Publish the conversion file** and save. A password is generated.
3. In Google Ads, add a scheduled import with an HTTPS source, using the file address, user name and password shown on the setup page.

The address ends in `googleads.php/conversions.csv`. Open it in a browser first: you should be asked for the user name and password, then see the file. If your web server refuses a path after `.php`, this route will not work on that server.

Columns: `Google Click ID`, `Conversion Name`, `Conversion Time` (UTC, with offset), `Conversion Value`, `Conversion Currency`, `Order ID`, then optionally `Email` and `Phone Number` (hashed) and `Ad User Data` and `Ad Personalization`. The file is rebuilt on every request, and Google ignores rows it has already imported.

The setup page shows how many conversions are in the file and why others were left out: no click identifier, no conversion name for their category, or a repeat.

#### Route 2: Data Manager API

1. In Google Cloud, create a project, enable the **Data Manager API**, create a service account and download a JSON key for it.
2. In Google Ads, under Admin → Access and security, add the service account's email address as a user. Any level except "Email only" works.
3. On the setup page, enter the Google Ads customer ID, the numeric ID of the conversion action for each category, and paste the content of the JSON key file. Tick **Send conversions through the API** and save.
4. Click **Test Google Ads connection**. Google validates a request without recording anything, which proves the key, the account access and a conversion action in one go.

If the service account was added to a manager account instead of the advertiser account, also enter the manager account ID.

The key is stored encrypted and never displayed again. To change it, paste a new one; to delete it, tick **Remove it** and save.

Google rejects a whole request when one conversion in it is invalid. The module then resends that batch one conversion at a time, so valid ones go through and the faulty one is identified with Google's error message. Retries follow the same policy as the webhook.

## Troubleshooting

**Nothing is being sent.**
- Check that the Scheduled jobs module is enabled and that the job "Lead Tracker: deliver stage events" has a recent run date. The job is driven by your server's cron calling Dolibarr's scheduler.
- **Deliver pending now** on the setup page runs it immediately.
- The **Recent stage events** list shows each event's status. "Not sent" means the destination was off, the stage is set to "Do not send", or the lead predates recording.

**The recent events list stays empty.**
- The module was upgraded without being disabled and re-enabled.
- The project does not have **Follow opportunity** checked, or is excluded by the category filter.

**A lead is missing from Google.**
- The setup page counts leads left out for having no click identifier. Those leads were captured without attribution data, or the token did not reach `lt_attribution`.
- The category of the stage has no conversion name (file) or conversion action ID (API).
- The lead already reported that category once.
- Google only accepts conversions for clicks from roughly the last 90 days.

**The Google connection test fails.**
- "Authentication failed" means the key is wrong, revoked, or the Data Manager API is not enabled in its project.
- A permission error means the service account has not been added as a user of the Google Ads account, or the customer ID is not the account that owns the conversion action.

**A webhook receiver rejects the signature.** Compute the HMAC over the raw request body, before any JSON parsing or re-encoding.

**A won deal was later lost.** The sale already reported to Google is not withdrawn.

For anything else, set Dolibarr's log level to debug and search the log for `leadtracker`.

## Privacy and security notes

- Contact details leave Dolibarr as SHA-256 hashes unless you explicitly opt in to sending them in clear to the webhook.
- The webhook secret, the file password and the service account key are stored encrypted by Dolibarr. The service account key is write-only in the interface.
- The conversion file is served only over HTTP Basic authentication and returns "not found" while the route is off. Serve Dolibarr over HTTPS.
- Destinations must be external `https://` addresses.
- Whether you may report a lead's outcome to an advertising platform depends on the consent you collected. The module states consent only when you configure it to.

## What the module stores

| Where | What |
|---|---|
| `llx_leadtracker_stage_config` | Conditions per stage |
| `llx_leadtracker_project` | Per-lead switch for automatic amount and probability |
| `llx_leadtracker_stage_event` | One row per stage change: the lead's stage history and the webhook queue |
| `llx_leadtracker_event_delivery` | Delivery state per stage change and destination |
| Project attribute `lt_attribution` | The attribution token of a lead |
| Module constants (`LEADTRACKER_*`) | Settings |

Disabling the module keeps all of it.

## Development

A `docker-compose.yml` at the repo root starts MariaDB 10.11 and a Dolibarr image with the module mounted live at `custom/leadtracker/`.

```bash
docker compose up -d
```

Dolibarr is then at http://localhost:8080 (login `admin` / `admin`). Enable the module in the interface; edits under `module/` take effect immediately.

To build a release zip, which also updates the version strings in the source:

```bash
python3 bin/build.py 1.15.0
```

Layout:

| Path | Purpose |
|---|---|
| `module/core/modules/` | Module descriptor: settings, scheduled job, activation |
| `module/core/triggers/` | Listens to native events |
| `module/class/leadtrackerautomation.class.php` | Stage advancement, amount and probability |
| `module/class/leadtrackerevents.class.php` | Stage history, categories, webhook delivery |
| `module/class/leadtrackergooglefeed.class.php` | Google Ads conversion file |
| `module/class/leadtrackergoogleapi.class.php` | Google Ads Data Manager API |
| `module/public/googleads.php` | Address the conversion file is served from |
| `module/admin/setup.php` | Setup page |
| `module/sql/` | Tables, created on activation |

For local testing of outbound calls only, the hidden constants `LEADTRACKER_WEBHOOK_ALLOW_LOCAL`, `LEADTRACKER_GOOGLE_TOKEN_URL` and `LEADTRACKER_GOOGLE_API_URL` allow local receivers and stand-in endpoints. Never set them in production.

## Licence

GPL-3.0-or-later. See individual source files for full licence text.
