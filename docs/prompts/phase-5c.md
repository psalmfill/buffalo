# BUFFALO — Phase 5C: Incident Workflow, Follow-ups and Notifications

Read `CLAUDE.md` before starting. Read `docs/handover/phase-5a.md` and `docs/handover/phase-5b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Incidents can be drafted with full detail and evidence, but cannot yet be submitted. This step adds the reporting workflow from LGA to state to national level, reference numbers, the follow-up timeline, notifications, real-time updates, verification deadlines and duplicate detection.

**Speed matters.** For kidnappings, terrorist attacks and similar events, the first report must reach the State Director and national level within minutes, even if details are incomplete. So submission needs only the core facts, and everything else can be added afterwards.

---

## Step 5C.1 — Workflow columns

Add to `incidents` (all date-times `timestampTz`):

`submitted_by`, `submitted_at`, `returned_by`, `returned_at`, `return_reason`, `verified_by`, `verified_at`, `investigation_started_by`, `investigation_started_at`, `resolved_by`, `resolved_at`, `resolution_summary`, `closed_by`, `closed_at`, `invalidated_by`, `invalidated_at`, `invalid_reason` (enum `duplicate`, `false_report`, `not_security_related`, `other`), `invalid_note`, `duplicate_of_incident_id` (nullable self-FK; required when reason is `duplicate`; must be visible to the user and not itself invalid).

**Escalation** (a flag, independent of status, per CLAUDE.md): `escalated_at`, `escalated_by`, `escalation_reason`, `escalation_acknowledged_at`, `escalation_acknowledged_by`.

## Step 5C.2 — Reference numbers

Format `BUF/<STATE code>/<LGA code>/<YEAR>/<6-digit sequence>`, e.g. `BUF/LA/IKJ/2026/000123`.

- Assigned at **first** submission, never changed afterwards (even if the LGA is later corrected; record the correction in the history instead).
- Sequence per state per year of submission, gap-free, using a counter table (`incident_reference_counters`: `state_id`, `year`, `last_value`) updated with a row lock inside the submission transaction.
- The year is the Africa/Lagos year of `submitted_at`.
- Searching by reference number is exact and case-insensitive.

## Step 5C.3 — Transitions

All transitions are Actions, run in a transaction, write `incident_status_histories` (`incident_id`, `state_id`, `lga_id`, `from_status`, `to_status`, `comment`, `changed_by`, `changed_at`; **append-only** via trigger), and log a named activity event.

| Transition | From | Who | Rules |
|---|---|---|---|
| submit | draft, returned | creator, reporting officer, SD/DSD of state | Requires only: type, title, description (≥ 20 chars), severity, occurred at, LGA. Assigns reference on first submission. |
| return | submitted | SD, DSD | Reason required. |
| verify | submitted | SD, DSD | Optional comment. |
| start investigation | verified | SD, DSD, DG, DDG | |
| resolve | verified, under_investigation | SD, DSD, DG, DDG | `resolution_summary` required. |
| reopen | resolved | SD, DG, DDG | Reason required; back to `under_investigation`. |
| close | resolved | SD, DG, DDG | Closed incidents are **locked**: no edits, no new evidence, no new follow-ups (enforce in policies and with a trigger on `incidents`). |
| invalidate | submitted, verified | SD, DG, DDG | Reason required; note required for `other`; duplicate link when `duplicate`. Invalid incidents are locked like closed ones and excluded from statistics. |

**Escalation:**

| Action | Who | Rules |
|---|---|---|
| escalate | SD, DSD | Status submitted, verified or under investigation; reason required; not already escalated. |
| auto-escalate | system | Any incident submitted with severity `critical` is escalated automatically at submission (reason "Critical severity"). |
| acknowledge | DG, DDGs covering the state | Records who acknowledged; it does not change status. |

**Editing after submission:**
- Until `verified`: the reporting officer, creator and SD/DSD of the state may edit all fields.
- From `verified` until closed: SD, DSD, DG, DDG may edit; each save requires an **amendment reason**, stored in the activity event.
- Changing the LGA after submission is allowed for SD and above, keeps the reference number, and re-syncs all child rows' `state_id`/`lga_id` (from 5A).
- Casualty counts and persons can be updated at any status before closing; each change is logged, so the history shows how figures evolved (e.g. "killed" revised from 3 to 5).

## Step 5C.4 — Follow-ups

`incident_updates` table: `ulid`, `incident_id`, `state_id`, `lga_id` (composite FK), `type` (enum `follow_up`, `action_taken`, `intelligence`, `status_note`), `body` (text, required), `classification` (default the incident's; may be higher, never lower; within the author's clearance), `created_by`, `created_at` (`timestampTz`).

- **Append-only:** no editing or deleting (trigger). Corrections are made by adding another update.
- `HasJurisdiction`, `HasClassification`; `LogsReads` when above `restricted`.
- Allowed on any status except `closed` and `invalid`, by anyone who can view the incident and holds `incidents.update`.

**Timeline:** the incident page's Timeline tab merges follow-ups, status changes, escalation and acknowledgement, and evidence uploads in time order, each item showing author and time. Items above the viewer's clearance are absent.

## Step 5C.5 — Notifications

All via `BuffaloNotification`: payloads contain the reference number, incident type, LGA name and a link. Never persons' names, casualties or descriptions.

| Event | Recipients | Severity |
|---|---|---|
| Submitted | SD and DSD of the state | by incident severity: `critical` → critical, `high` → warning, else info |
| Submitted with `critical` severity (auto-escalated) | also DG and DDGs covering the state | critical |
| Returned, verified, resolved, closed, invalidated | reporting officer (and creator if different) | info |
| Escalated | DG and DDGs covering the state | warning (critical if incident is critical) |
| Acknowledged | the escalating officer | info |
| New `intelligence` or `action_taken` update on an escalated incident | DG and DDGs covering the state | info |
| New follow-up | reporting officer and SD/DSD of the state, excluding the author | info |

Recipients receive a notification only if they can see the incident (check jurisdiction and classification per recipient before sending).

## Step 5C.6 — Real-time updates

- Private channel `states.{state_ulid}.incidents`: authorised for users whose jurisdiction covers the state (LGA chiefs of that state included; their client filters by LGA through the refetch). National users subscribe to a `national.incidents` channel.
- Events: `IncidentSubmitted`, `IncidentStatusChanged`, `IncidentEscalated`, `IncidentUpdated`. Payload: **only** incident ULID and event name (CLAUDE.md). The client refetches through normal scoped routes, so classification and jurisdiction apply.
- An open incident page refreshes its data when its incident changes.

## Step 5C.7 — Verification deadlines

`config/buffalo.php` → `incidents.verify_within_minutes`: critical 60, high 360, medium 1440, low 2880 (env overrides).

- A job every 5 minutes finds submitted, unverified incidents past their deadline and sends one reminder to the SD and DSD. At twice the deadline, it notifies the DG and DDGs covering the state (once).
- Track sent reminders so each is sent once.
- The state dashboard (5D) highlights overdue incidents.

## Step 5C.8 — Duplicate detection

On submission, look for possible duplicates **within the submitter's visible incidents**: same incident type, `occurred_at` within ±24 hours, and either the same community or locations within 2 km (PostGIS `ST_DWithin`), excluding invalid incidents.

- If found, the submitter sees them (reference, title, time, status) and chooses "Submit anyway" or cancels. Submitting anyway is allowed; the potential duplicates are recorded in the submission activity event.
- The verifier sees "Possible duplicates" on the incident page, from the same query, and can invalidate as duplicate with one action.

## Step 5C.9 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `incidents.submit` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `incidents.verify` (verify, return) | | | ✓ | ✓ | |
| `incidents.investigate` (start, resolve) | ✓ | ✓ | ✓ | ✓ | |
| `incidents.close` (close, reopen) | ✓ | ✓ | ✓ | | |
| `incidents.invalidate` | ✓ | ✓ | ✓ | | |
| `incidents.escalate` | | | ✓ | ✓ | |
| `incidents.acknowledge` | ✓ | ✓ | | | |
| `incidents.amend` (edit after verification) | ✓ | ✓ | ✓ | ✓ | |

Always combined with jurisdiction, classification and the "who" rules in 5C.3. Update the matrix, drift tests and `auth.ts`.

## Step 5C.10 — UI

- Incident page header: reference number, status badge, severity, escalation badge (with "Acknowledged by …" when acknowledged), classification badge, and action buttons available to the viewer, each opening a dialog for its required fields.
- Submission dialog: shows missing optional sections as a checklist ("No casualties recorded", "No evidence") without blocking, plus possible duplicates.
- Amendment reason prompt when saving after verification.
- **Timeline** tab (5C.4).
- A bold banner on closed and invalid incidents; invalid incidents link to the incident they duplicate.

Run `make wayfinder` after route changes.

## Step 5C.11 — Tests

- Every transition: allowed and denied per role, per status, per jurisdiction; the "who" rules (e.g. DSD cannot close or invalidate); required fields.
- Reference numbers: format, gap-free and sequential under concurrent submissions (test with parallel transactions), per state per year, stable on resubmission and on LGA change.
- Auto-escalation of critical incidents at submission; manual escalation; acknowledgement by covering DDG only.
- Closed and invalid incidents are locked (policies and trigger), including evidence upload and follow-ups.
- Amendment reason required after verification; LGA change re-syncs child rows and keeps the reference.
- Follow-ups: append-only trigger; classification not below the incident's; absent from timelines of users without clearance.
- Status history append-only.
- Notifications: each event reaches exactly the right recipients; no recipient without visibility; payloads contain no personal details.
- Broadcast channel authorisation per role; payloads contain only ULID and event name.
- Verification deadline reminders, once each, and national escalation at twice the deadline.
- Duplicate detection: type, time window, community or 2 km, visibility-limited, invalid excluded.

## Acceptance checklist

- [ ] An LGA chief submits a critical kidnapping with only the core fields; within seconds the State Director and DG get live critical notifications, and it shows `BUF/<STATE>/<LGA>/2026/000001`.
- [ ] The DG acknowledges the escalation; the chief adds casualty details and two follow-ups; the history shows the figures being revised.
- [ ] The State Director verifies, starts investigation, resolves with a summary, and closes; the incident is then locked.
- [ ] A second report of the same kidnapping is flagged as a possible duplicate and invalidated as such.
- [ ] An unverified high-severity incident triggers a reminder after 6 hours (time travel in tests; shortened config in the browser).
- [ ] `make test` and `make lint` pass.

## Out of scope

Incident list, search, map, dashboards (5D); flashpoints (Phase 6); analytics and exports (Phase 7).

## Handover

Write `docs/handover/phase-5c.md` per CLAUDE.md section 9 and print it.
