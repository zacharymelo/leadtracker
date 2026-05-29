# Lead Tracker — Dolibarr Module

Lead Tracker extends Dolibarr's built-in CRM to make it practical to run a sales pipeline directly from the Projects module. Out of the box, Dolibarr tracks opportunity status and probability on projects, but gives you no at-a-glance view of where a lead sits in your pipeline and no automatic way to keep that status current as proposals, orders, and invoices accumulate. Lead Tracker fills that gap.

## What it does

### Visual pipeline bar
A horizontal step indicator is injected into every project card that has **Follow opportunity** checked. Each circle represents a stage from your lead status dictionary (Home → Setup → Dictionary → Lead status). The bar tells you instantly where the lead is and what has already happened.

Stage states are determined from live evidence — the module inspects linked contacts, proposals, orders, and invoices on every page load rather than relying solely on the stored status field. This means the bar always reflects reality, even if an event was processed out of order or a document was linked manually after the fact.

### Automatic stage advancement
A trigger fires on every relevant native event:

| Event | Dolibarr action |
|---|---|
| `PROPAL_VALIDATE` | Proposal sent to client |
| `PROPAL_SIGN` | Proposal accepted |
| `ORDER_VALIDATE` / `COMMANDE_VALIDATE` | Order confirmed |
| `BILL_VALIDATE` | Invoice issued |
| `ACTION_CREATE` | Contact logged (call, email, meeting) |

When the event fires, the module evaluates the conditions you configured for each stage and advances `fk_opp_status` to the furthest stage whose conditions are satisfied. Logic is OR — any one matching condition is enough.

### LTV-based lead amount tracking
When **Lead amount source** is set to Auto, the module keeps `opp_amount` current using a closest-to-cash hierarchy:

1. **Sum of validated invoices** — real money billed; accumulates over time as a lifetime total
2. **Sum of validated orders** — committed spend
3. **Sum of accepted proposals** — confirmed intent
4. **Average of open proposals** — estimated pipeline value while the deal is still forming

Amount is recalculated on every trigger event, not just when the stage advances, so a new invoice immediately supersedes an order total even if the lead status hasn't changed.

### Probability percentage sync
When **Probability percentage** is set to Auto, the module writes the default percentage from the lead status dictionary to `opp_percent` whenever the stage advances.

## Requirements

- Dolibarr 14.0 or later (tested on 22.x)
- PHP 7.0 or later (tested on 8.2)
- Projects module enabled

## Installation

1. Download the latest zip from [`bin/`](bin/)
2. In Dolibarr: Home → Setup → Modules → upload the zip
3. Enable **Lead Tracker** in the module list
4. Go to **Lead Tracker → Setup** to configure stage conditions and data sync behaviour

## Configuration

### Stage conditions
The conditions matrix maps each pipeline stage to the event(s) that qualify a lead as having reached it. Check one or more conditions per stage — the first event that matches any checked condition will advance the lead to that stage.

| Condition | Satisfied when |
|---|---|
| Contact | An outbound call, email, or meeting is logged |
| Proposal | At least one validated proposal exists |
| Signed | At least one accepted proposal exists |
| Order | At least one validated order exists |
| Invoice | At least one validated invoice exists |
| Manual | Stage is never auto-advanced |

### Opportunity filter
The tracker only appears on projects with **Follow opportunity** checked — that is the primary gate and cannot be disabled. An optional category filter lets you restrict the tracker to a subset of those projects (e.g. a specific team or product line).

### Data sync
| Setting | Options |
|---|---|
| Lead amount source | Manual / Auto (LTV hierarchy) |
| Probability percentage | Manual / Auto (stage default) |

Both default to Manual so existing data is never touched until you opt in.

### Display
- Full mode (circles + labels) or compact mode (circles only)
- Configurable colours for completed, current, pending, and lost stages
- Action link on the current stage (quick-creates the next native document)

## Development

A `docker-compose.yml` is included at the repo root. It spins up MariaDB 10.11 and the latest Dolibarr image with the module mounted live at `custom/leadtracker/`.

```bash
docker compose up -d
# Dolibarr available at http://localhost:8080
# Login: admin / admin
```

Enable the module via the UI, then edit files in `module/` — changes are reflected immediately without a restart.

## Licence

GPL-3.0-or-later. See individual source files for full licence text.
