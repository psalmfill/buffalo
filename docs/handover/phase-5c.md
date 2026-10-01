# Handover — Phase 5C: Incident workflow, follow-ups and notifications

Date: 2026-10-01

## Summary

Incidents now move from the LGA to the state and national levels:

- **Submission** needs only the core facts and assigns a gap-free reference number (`BUF/<state>/<LGA>/<year>/<sequence>`).
- **The review workflow** runs return, verify, investigate, resolve, reopen, close and invalidate. Closed and invalid incidents are locked, in the policies and in the database.
- **Escalation** to national level is a flag, automatic for critical incidents, with acknowledgement.
- **Follow-ups** are append-only, shown in a Timeline tab.
- **Notifications** reach only people who can see the record.
- **Real-time refresh** uses broadcasts that carry the incident ULID only.
- **Verification-deadline reminders** go to the state, then to national level.
- **Duplicate detection** runs at submission and for reviewers.

Final checks:

- `make test` passes: 1207 Pest tests (7343 assertions) and the Vitest suites.
- `make lint` passes.
- `make ci` passes (1207 Pest tests in a clean copy).
- The browser acceptance checklist passes (below).

## Acceptance checklist

All on dev, in Demo LGA LA-1-1 (Lagos). Each actor works in their own session:

- the State Director in the browser;
- the LGA chief and the DG from a scripted HTTP client (`scratchpad/as.sh`), signing in with password and TOTP as the browser does.

Checklist:

- [x] **The chief submits a critical kidnapping with only the core fields** (type, title, description, severity, time, LGA).
  - It received `BUF/LA/D11/2026/000001` and was escalated automatically ("Critical severity").
  - Submitted at 07:37:17; the SD, DSD and DG notifications (`critical`) were created and broadcast by Horizon at 07:37:19.
  - The SD's open dashboard showed the live toast "Incident reported — BUF/LA/D11/2026/000001 — Kidnapping, Demo LGA LA-1-1 LGA", and the bell went from 3 to 4.
- [x] **The DG acknowledges; the chief adds detail and two follow-ups.**
  - The header shows "Escalated · acknowledged by Dev Director General".
  - The chief then added the type fields, the community and an abducted-civilians row (3 men), and later revised it to 3 men and 1 woman.
  - The History tab shows "adult_female 0 → 1" and "number_of_victims 3 → 4".
  - Two follow-ups (follow-up, action taken) appear on the Timeline.
- [x] **The SD verifies, starts the investigation, resolves with a summary and closes**, each through its dialog in the browser.
  - The incident shows the bold lock banner and no edit or action buttons.
  - A follow-up, an edit and an evidence upload are refused (403).
  - A direct `UPDATE` is rejected by the `buffalo_incident_locked` trigger.
- [x] **A second report of the same kidnapping is flagged and invalidated.**
  - Same type, same community, 30 minutes apart.
  - Submitting without confirmation returned the duplicate warning; "Submit anyway" gave `…/000002`.
  - The SD saw "Possible duplicates: BUF/LA/D11/2026/000001", used "Invalidate as duplicate of this" (the dialog pre-filled the original) and confirmed.
  - The banner reads "marked invalid (duplicate of another incident) … It duplicates BUF/LA/D11/2026/000001" with a link.
- [x] **An unverified high-severity incident triggers a reminder.**
  - `…/000003` was submitted.
  - About 70 seconds later, `buffalo:incident-verification-reminders` ran with `BUFFALO_VERIFY_HIGH_MINUTES=1`. It sent one reminder ("Incident awaiting verification") to the SD and DSD.
  - A second run sent nothing. The reminder shows in the SD's bell.
  - The 6-hour and 12-hour behaviour is covered by tests (time travel).
- [x] **`make test` and `make lint` pass.**

## What was built

### 5C.1 — Columns

**Migration `2026_10_08_000001_add_incident_workflow`**

- The prompt's workflow and escalation columns (`timestamptz`), with CHECKs:
  - `invalid_reason` is one of the allowed values;
  - a duplicate needs an original, which is not the incident itself;
  - a reference only after submission.
- A case-insensitive unique index on `lower(reference_no)`.
- Tables:
  - `incident_reference_counters` (`state_id`, `year`, `last_value`);
  - `incident_status_histories`;
  - `incident_updates`;
  - `incident_reminders`.

**Triggers**

- `buffalo_append_only_follow_place()`, on status histories and follow-ups: rows cannot be deleted or changed, except their place following the incident (composite FK, ON UPDATE CASCADE). `TRUNCATE` is refused too.
- `buffalo_incident_locked()` on `incidents`, and `buffalo_incident_child_locked()` on the five 5A tables, evidence (inserts, edits, removal; the queued verification may still finish) and new follow-ups. Both refuse changes to closed and invalid incidents.
- `buffalo_update_not_below_incident()`: a follow-up is never written below its incident.

**Model changes:** casts, `isLocked()`, `isEscalated()`, `wasSubmitted()`, `requiresAmendmentReason()`; the relations `duplicateOf`, `statusHistories` and `updates`. New enums `InvalidReason` and `IncidentUpdateType`.

### 5C.2 — Reference numbers

- **`IncidentReferenceAllocator`:** a single `INSERT … ON CONFLICT DO UPDATE … RETURNING` on the counter row, inside the submission transaction.
  - It row-locks the counter until commit; a rollback undoes the increment, so there are no gaps.
  - The year is the Africa/Lagos year of `submitted_at`.
  - Assigned only when `reference_no` is null, so it never changes on resubmission or LGA correction (`lga_changed` is logged instead).
- **Concurrency test:** 4 forked processes, each on its own connection, run the allocator's statement 30 times each, with every sixth transaction rolled back. The committed values are exactly 1..100.
- **Search:** exact and case-insensitive (`lower(reference_no) = lower(?)`, index-backed). The incident picker searches the same way.

### 5C.3 — Transitions

- **Actions, one per step:** `SubmitIncident`, `ReturnIncident`, `VerifyIncident`, `StartInvestigation`, `ResolveIncident`, `ReopenIncident`, `CloseIncident`, `InvalidateIncident`, `EscalateIncident`, `AcknowledgeEscalation`, `AddIncidentUpdate`.
- **`IncidentWorkflow`** holds the shared steps, in one transaction:
  - lock the incident row;
  - set the columns;
  - write the status history;
  - log the named activity event (`submitted`, `returned`, `verified`, …);
  - broadcast after commit.
- **`IncidentPolicy`** has one ability per step: permission, status, own jurisdiction, classification, and the "who" rules. For example, the DSD cannot close, reopen or invalidate; the DG cannot submit someone else's report.
- **Submission:**
  - checks a description of at least 20 characters (the other core fields are already required for any draft);
  - possible duplicates must be confirmed and are recorded in the activity event;
  - critical incidents are escalated automatically (`escalated_by` null, "Critical severity").
- **Editing (`update`):**
  - draft, submitted or returned: the creator, the reporting officer, or the SD/DSD of the state;
  - verified to resolved: `incidents.amend`, with a required `amendment_reason` (activity event `amended`);
  - closed or invalid: nobody.
- **LGA correction after submission:** `changeLga` allows the SD, DG and DDG. Related rows move through the cascading FKs, and the reference is kept.
- **Casualty and person changes** are logged per row, so the History tab shows how figures changed.

### 5C.4 — Follow-ups and timeline

- **`IncidentUpdate`:**
  - scoped on its own place and on its incident being visible;
  - classified, with reads above `restricted` access-logged;
  - append-only;
  - its own new `IncidentUpdatePolicy`.
- **Adding** needs `addUpdate`: `incidents.update` plus view, at any status except closed and invalid.
- **`IncidentTimeline`** merges, in time order: status changes, follow-ups, escalation and acknowledgement, and evidence uploads. Each source goes through its scopes, so items above clearance are absent.

### 5C.5 — Notifications

- **`IncidentNotification`** (one class, an `event` per row of the prompt's table). The payload is the reference, type, LGA and link; nothing else.
- **`IncidentNotifier`** resolves recipients:
  - SD and DSD of the state;
  - DG and covering DDGs;
  - officers.

  It filters each recipient with `can('view', $record)`, the record being the incident or the follow-up.
- **Severity:**
  - submission follows the incident: critical, warning (high) or info;
  - escalation is warning, or critical for a critical incident;
  - everything else is info.

### 5C.6 — Real-time

- **Events:** `IncidentSubmitted`, `IncidentStatusChanged`, `IncidentEscalated` and `IncidentUpdated`, after commit.
- **Channels:** `private-states.{state_ulid}.incidents` and `private-national.incidents`.
- **Payload:** `{incident, event}` only.
- **Channel authorisation** (`routes/channels.php`):
  - the state channel is for users with `incidents.view` whose jurisdiction covers the state (LGA chiefs of the state included), through the new `JurisdictionOptions::coversState()`;
  - the national channel is for national users.
- **`IncidentLiveRefresh`** reloads the open incident page through its scoped route when its incident changes. Edits made after submission broadcast `IncidentUpdated`.

### 5C.7 — Verification deadlines

- **Config:** `buffalo.incidents.verify_within_minutes` (critical 60, high 360, medium 1440, low 2880; `BUFFALO_VERIFY_*_MINUTES`).
- **Command:** `buffalo:incident-verification-reminders`, every 5 minutes, without overlapping.
  - Past the deadline, the SD and DSD get a warning.
  - At twice the deadline, the DG and covering DDGs get a warning.
  - Each reminder is sent once per submission (`incident_reminders`, unique per incident, kind and submission time).

### 5C.8 — Duplicates

**`PossibleDuplicates`**, scoped to what the user can see:

- same type;
- `occurred_at` within ±24 hours;
- the same community, or `ST_DWithin` 2,000 m on the geography locations;
- excludes invalid incidents and the incident itself; at most 20.

It is used by `GET /incidents/{ulid}/duplicates` (the submission dialog), by the submission check, and on the page for reviewers (`verify` or `invalidate`), with "Invalidate as duplicate of this".

### 5C.9 — Permissions

`incidents.submit`, `verify`, `investigate`, `close`, `invalidate`, `escalate`, `acknowledge` and `amend`, as in the prompt's table.

- Updated together: the seeder matrix, `RolesAndPermissionsTest`, `types/auth.ts` and the navigation test.
- Re-seeded on dev (65 permissions).

### 5C.10 — UI

- **Incident page header:**
  - reference, status, severity, escalation badge ("acknowledged by …", or "awaiting acknowledgement"), classification;
  - an Edit button (labelled "Edit draft" for drafts);
  - the workflow buttons the viewer may use, each with a dialog.
- **Submission dialog:**
  - a non-blocking checklist (casualties, persons, agencies, map location, evidence);
  - possible duplicates, with "Submit anyway".
- **Mark invalid dialog:** reason; note (required for "other"); original incident, through the possible-duplicates list or the new `pickers/incidents` endpoint (visible, submitted, not invalid).
- **Banners and panels:**
  - a bold lock banner on closed and invalid incidents, linking to the original of a duplicate;
  - the return reason and resolution summary;
  - the Possible duplicates panel for reviewers.
- **Tabs:** Overview, **Timeline** (with an "Add an update" form) and History.
- **Form:**
  - the amendment reason field after verification;
  - the LGA select is disabled when the user cannot correct it;
  - **"Save and submit"** saves, then opens the submission dialog on the incident page (`?submit=1`).

## Deviations and decisions

1. **One notification for an automatic escalation.** At a critical submission, the DG and DDGs get the critical "Critical incident reported (escalated)" notification rather than a separate "escalated" one.
2. **Follow-up classification after the incident is raised:** follow-ups are append-only and cannot be raised with it. They are checked against the incident's level when written; afterwards, a follow-up is visible only to those who can see the incident too (the incident scope), so it is never effectively below the incident.
3. **Status histories and follow-ups follow the incident's place** when the LGA is corrected. The append-only trigger allows only that change.
4. **Duplicate detection** has nothing to compare when an incident has neither a community nor a location, so it finds no duplicates. Drafts are included; only invalid incidents are excluded, per the prompt.
5. **Acknowledgement** notifies the escalating officer, or the submitter for an automatic escalation.
6. **Every new follow-up** notifies the reporting officer and the SD/DSD, excluding the author. Intelligence and action taken on an escalated incident also reach national level.
7. **"SD and above" for LGA correction** is the SD, DDG and DG (not the DSD), as for deletions.
8. **Reopening** keeps the previous resolution fields until the next resolution; the history records both.
9. **Invalid incidents and statistics:** no statistics exist yet. 5D's lists and dashboards must exclude `invalid`.

## Bugs found and fixed

- **Notifications about follow-ups were never sent:** `IncidentUpdate` had no policy, so `can('view')` was false for everyone. `IncidentUpdatePolicy` was added, and the notification tests now cover it.
- **The incident History tab showed "System"** for officers outside the viewer's jurisdiction (e.g. the DG acknowledging a state incident), because the causer is loaded through user scopes. Names are now filled through the sanctioned `OfficerNames` lookup.
- **A 5A test expected submitted incidents to be read-only.** Since 5C they are editable until verified; the test now asserts the new rule and the lock on closed incidents.

## New packages

None.

## Known issues and notes for 5D

- **Incidents list:** "Incidents" still lists the user's own drafts. The full list, search, map and dashboards are 5D. The state dashboard should highlight incidents past their verification deadline (`incident_reminders` or the deadline config).
- **Live refresh** is wired on the incident page only. 5D lists can subscribe to the same channels.
- **Single session:** a user signing in elsewhere signs out the older session; this happened during acceptance. Expected behaviour.
- **Dev data:**
  - `BUF/LA/D11/2026/000001`: closed;
  - `…/000002`: invalid, duplicate of 000001;
  - `…/000003`: submitted, high, reminder sent;
  - the 5A draft kidnapping is still a draft.
- **Tooling:** `make test` now takes about 7–8 minutes. Running `make fix`, `make lint` and `make test` in one foreground command exceeds the 10-minute tool limit, so run them separately.
