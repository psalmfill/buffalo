# BUFFALO — Phase 6B: Threat Reports (Early Warnings)

Read `CLAUDE.md` before starting. Read `docs/handover/phase-6a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Incidents record what has happened; flashpoints record places of concern. **Threat reports** record what may be about to happen: credible warnings of a planned attack, kidnapping, clash, protest turning violent, or an expected flood. They must reach decision-makers quickly, carry an explicit assessment of credibility, and expire when no longer relevant.

---

## Step 6B.1 — Threat reports table

`threat_reports`:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `reference_no` | `THR/<STATE>/<YEAR>/<5-digit seq>`, assigned at creation, gap-free per state per year (counter table, same approach as incidents) |
| `threat_type_id` | FK (register with `ReferenceUsage`) |
| `title` | required, max 200 |
| `description` | text, required |
| `credibility` | enum `unconfirmed`, `possible`, `probable`, `confirmed` |
| `urgency` | enum `routine`, `priority`, `immediate` |
| `source_type` | enum `informant`, `community`, `agency_report`, `open_source`, `officer_observation`, `other` |
| `source_notes` | nullable text; help text: never record an informant's identity here |
| `state_id`, `lga_id` | required; composite FK |
| `community_id` | nullable; composite FK |
| `location` | nullable point; `radius_meters` nullable (100–50,000) |
| `target_description` | nullable text (e.g. "Sunday service at a church", "weekly cattle market") |
| `expected_from`, `expected_until` | nullable `timestampTz` (the window in which the threat may materialise) |
| `expires_at` | `timestampTz`, required; default `expected_until` if set, otherwise 14 days after creation (`config/buffalo.php`); maximum 90 days ahead |
| `status` | enum `active`, `mitigated`, `materialised`, `expired`, `withdrawn` |
| `status_note` | required when status changes away from `active` |
| `recommended_action` | nullable text |
| `classification` | default `restricted` |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` above `restricted`.
- Every change of credibility, urgency, status or expiry is a named activity event; credibility and urgency changes require a comment.
- `materialised` requires linking at least one incident (6B.2).

## Step 6B.2 — Links

Each link table carries the **threat report's** place (composite FK with cascade), is visible only when the viewer can see both sides, never reveals hidden counts, and is logged:

- `threat_report_flashpoints` (flashpoint must be visible to the linker)
- `threat_report_criminal_groups` (group visible), with `role` enum `suspected`, `named_in_report`
- `threat_report_incidents` (incident visible, not invalid), with `relation` enum `materialised_as`, `related`

**Incident submission hook (5C):** when an incident is submitted, find active threat reports of the same threat category whose area (point and radius, or community, or LGA when neither) covers the incident, with `occurred_at` inside the expected window or before expiry. Show "Possibly related threat report: [reference]" on the incident page to viewers who can see the threat, with a one-click "Mark as materialised".

## Step 6B.3 — Expiry and lifecycle

- A job every 15 minutes sets `active` threats past `expires_at` to `expired` (system context, logged).
- 24 hours before expiry, notify the creator and the SD/DSD of the state, with an **Extend** action (new expiry, comment required, within the 90-day maximum from the extension date).
- Expired, mitigated and withdrawn threats can be reactivated by SD and above with a new expiry and comment.

## Step 6B.4 — Notifications

Payloads: reference, threat type, urgency, LGA name and link only. Never descriptions, targets or sources. Recipients must be able to see the threat report.

| Event | Recipients | Severity |
|---|---|---|
| Created, `immediate` urgency | SD/DSD of the state, the LGA chief, and the DG and DDGs covering the state | critical |
| Created, `priority` | SD/DSD and the LGA chief | warning |
| Created, `routine` | SD/DSD (included in their daily digest rather than individually; add a simple daily threat digest at 07:00) | info |
| Urgency raised to `immediate`, or credibility raised to `confirmed` | as for `immediate` creation | critical |
| Materialised | the creator, SD/DSD, and national if the threat was `immediate` | warning |
| Expiring in 24 hours | creator and SD/DSD | info |

Real-time: broadcast `ThreatReportCreated` and `ThreatReportChanged` on the existing state and national incident channels (or parallel `...threats` channels; choose one and report), payload ULID and event name only.

## Step 6B.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `threats.view`, `threats.create` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `threats.manage` (edit, status, links, extend) | ✓ | ✓ | ✓ | ✓ | ✓ ¹ |
| `threats.reactivate` | ✓ | ✓ | ✓ | | |
| `threats.delete` | ✓ | ✓ | ✓ | | |

¹ LGA chiefs may manage only threat reports they created. Super admin: none. Update the matrix, drift tests and `auth.ts`.

## Step 6B.6 — UI

Under the **"Flashpoints"** navigation section (rename it **"Threats & flashpoints"**): "Threat reports" with a badge for active `immediate` threats in reach.

- **`/threats`**: list, default filter active only, sorted by urgency then expected window; filters for threat type, credibility, urgency, status, state, LGA, expiring within 48 hours; map with points and radius circles coloured by urgency.
- **Create/edit form:** all fields, map picker with radius, a credibility and urgency explanation panel, links to flashpoints and groups (pickers).
- **`/threats/{ulid}`**: overview with a countdown to expiry and the expected window, status actions with required notes, links (flashpoints, groups, incidents), timeline of changes, history.
- **Incident page:** "Possibly related threat reports" section.
- **Flashpoint page:** "Threat reports" tab.
- **Dashboards:**
  - LGA chief: active threats in my LGA.
  - State roles: active threats by urgency, expiring soon, recently materialised.
  - National: active `immediate` threats nationally (top of the page), active threats by state and type.

Run `make wayfinder` after route changes.

## Step 6B.7 — Tests

- Reference numbers: format, gap-free per state and year under concurrency.
- Scope and classification matrix for threat reports and link tables; link visibility requires both sides; no hidden counts.
- Expiry defaults and the 90-day maximum; the expiry job; extension and reactivation rules.
- Status rules: note required; `materialised` requires an incident link.
- Incident submission hook: category, area and time window logic; visibility.
- Notifications: each row of the table, recipient visibility, payload contents, the routine digest.
- LGA chiefs can manage only their own threat reports.
- Real-time payloads contain only ULID and event name.

## Acceptance checklist

- [ ] An LGA chief records an `immediate`, `probable` threat of an attack on a market this weekend; the State Director and DG receive live critical notifications.
- [ ] A matching incident submitted in the area shows "Possibly related threat report"; marking it materialised updates the threat's status and notifies national.
- [ ] A routine threat appears in the State Director's 07:00 digest, not as an individual notification.
- [ ] An unattended threat expires automatically and can be reactivated by the State Director.
- [ ] `make test` and `make lint` pass.

## Out of scope

Cross-state dissemination of threats (see the handover note request below); operational map and aggregation (6C).

In the handover, note any practical problems you see with threats that affect neighbouring LGAs or states, since a threat report is visible only within its own jurisdiction (plus national).

## Handover

Write `docs/handover/phase-6b.md` per CLAUDE.md section 9 and print it.
