# Phase 10B handover: consolidating duplicate incidents, reassigning office holders

Invalidating an incident as a duplicate can now first consolidate its
useful content into the original, in one transaction. A political office
recorded against the wrong person can be corrected. Incidents are still
never merged: the duplicate stays an invalid, locked record with its own
reference and history.

## What was built (commit 1b705f3)

### 10B.1 Consolidation rules (`App\Support\Incidents\Consolidation`)

`plan()` lists every item on the duplicate with its outcome, as the user
may see it; `apply()` carries the plan out and returns the manifest.

| Item | Outcome |
|---|---|
| Evidence | **Moved**: `incident_id`, `state_id` and `lga_id` change. The file, SHA-256, ULID and custody history are unchanged, and `consolidated_from_incident_id` is set. Custody event `consolidated` ("Moved from BUF/… to BUF/…"). Evidence below the original's classification is raised to it, with the existing `classification_changed` custody event. |
| Follow-ups | **Copied** as new follow-ups on the original: same type, body and (raised if needed) classification; the consolidating officer is the creator; `copied_from_update_id` links the source. The timeline shows "Originally recorded on BUF/… by [name] at [time]". The originals stay on the duplicate. |
| Persons | **Added** unless the original already links the same person with the same involvement (**skip**, notes appended). Needs `linkToIncident`. Unidentified persons are always added, with " (from BUF/…)" appended. |
| Criminal groups / agencies / property | **Added** unless already present (same group; same agency and formation; same property type and site). Otherwise **skipped**, with notes (property: the description) appended and labelled `[From BUF/…]`. Groups, formations and sites must be visible to the user. |
| Casualties | **Never** moved or summed. The preview shows both tables side by side, with a link to amend the original. |
| Details, description, location, time | Unchanged. |
| Flashpoint and threat links | **Added** in the linked record's place where the user may link (`FlashpointPolicy::linkIncident`, `ThreatReportPolicy::linkIncident`), otherwise **left**. |
| Access requests citing the duplicate | **Repointed** (`record_access_requests.incident_id`). |

Items above the user's clearance, and linked records outside their view,
are listed as **not consolidated** ("above your clearance" or "not visible
to you") with no title or detail, and stay on the duplicate.

`incident_consolidations` holds `ulid`, both incident IDs (the duplicate is
unique), the original's place, the `manifest` (every item's outcome, new
row IDs, appended notes, classification changes), `consolidated_by` and
`consolidated_at`:

- append-only (`buffalo_append_only_follow_place`, as for
  `incident_updates`) and hash-chained (`AuditChain::TABLES`), with the
  place excluded from the hash;
- the place follows the original through a composite FK `ON UPDATE CASCADE`;
- the application role holds `SELECT, INSERT, UPDATE (state_id, lga_id)`
  (`DatabaseRoles::FOLLOW_PLACE`).

### 10B.2 Action and locks

`InvalidateIncident` takes `$consolidate` and `$amendmentReason`. In one
transaction, when the reason is a duplicate and consolidation is chosen:

1. Lock both incidents (`FOR UPDATE`, ID order) and re-authorise:
   `invalidate` on the duplicate, `update` on the original (5C rules:
   update until verified, `incidents.amend` afterwards). The original must
   not be closed or invalid, and an amendment reason of at least 10
   characters is required when the original is verified.
2. Consolidate (plan and apply), write `incident_consolidations`, and add
   the named events: `consolidated_from` on the original,
   `consolidated_into` on the duplicate, and `amended` (with the reason)
   when the original is verified. Broadcast `IncidentUpdated` for the
   original.
3. Invalidate the duplicate through the existing workflow, which locks it
   and records the consolidation ULID on the event.

Any failure rolls everything back; a test injects a failure at the record
insert.

**Notifications:** the duplicate's officers as for any invalidation, plus
the original's reporting officer: "Information added to your incident",
with the reference, type, LGA and link only.

The 5C "Possible duplicates" list already opened the invalidate dialog with
the original preset; consolidation is on by default there.

### 10B.3 Political office reassignment

- `ReassignPoliticalOffice`, with `political.reassign` (DG, DDG, SD) and
  `PoliticalOfficePolicy::reassign` (permission and `update` on the
  office).
- The new holder must be in the user's own jurisdiction (`ownJurisdiction`
  lookup plus `link`); otherwise 404 or 403. The reason must be at least 20
  characters, and the office cannot be reassigned to its current holder.
- The new holder gains `political_leader`. The old holder loses it unless
  they hold another current office (any office record, wherever it is).
- Named event `political_office_reassigned` (office, old and new holder
  ULIDs, reason) on the office and on both persons, so both histories show
  it. Route: `POST political-offices/{office}/reassign`.

### 10B.4 UI

- **Invalidate dialog:** with reason Duplicate and an original chosen,
  **Consolidate into the original** (on by default) loads the preview
  (`GET incidents/{incident}/consolidation?original=`, JSON):
  - each item on the duplicate with its outcome on the original;
  - the casualty tables side by side, with **Amend the original**;
  - the irreversibility notice;
  - an amendment reason field when the original is verified;
  - the button reads **Consolidate and mark invalid**.
- **Evidence tab:** moved items show "From BUF/…".
- **Timeline:** copied follow-ups show their original author and time, and
  an entry "Content consolidated from BUF/…".
- **Political offices tab:** **Reassign holder** (icon button) opens a
  dialog with an own-jurisdiction person picker and a reason; it explains
  when to end the office and record a new holder instead.
- **Help:** "Consolidating a duplicate" in the incident guide; "Correcting
  a political office's holder" in the merging guide.

### 10B.5 Permissions and inventory

- `political.reassign` added to the matrix, `auth.ts`, the navigation test
  and the drift test.
- Route inventory: 344 routes, all authorised.
- The jurisdiction matrix covers `incidents.consolidation` (permissions
  `incidents.view` + `incidents.invalidate`; an inside exception because
  the preview needs `?original=`) and the reassign route (outside: 404 or
  403).

## Verification

- `make test`: 1,693 Pest tests (13,554 assertions) and 14 Vitest pass.
  Phase 10B adds 20:
  - `tests/Feature/Incidents/ConsolidationTest.php` (9): every rule, the
    classification raise, items above clearance, all-or-nothing with an
    injected failure, authorisation (invalidate plus edit, closed
    original, amendment reason, another state), without consolidation,
    statistics, append-only, the evidence marker;
  - `tests/Feature/Political/OfficeReassignmentTest.php` (11, including 7
    role cases).
- `make lint`: Pint, Larastan level 7, ESLint, Prettier and tsc are clean.
- `make ci` passes. The first run hit an ordering flake in an existing test
  (`ReferenceDataHttpTest` read two activity rows without `orderBy`); the
  test now orders by ID, and the re-run passed.

### Phase 10 completion checklist

Run with scripts (scratchpad, not shipped) on a freshly created UAT
environment built from this tree: production images, `APP_ENV=uat`,
synthetic Lagos data, sign-in with password and TOTP.

| Item | Result |
|---|---|
| Two reports of the same kidnapping; the duplicate has a photo, a witness and a follow-up; the State Director consolidates | **Pass.** The preview listed the evidence (move), follow-up (copy) and witness (add). After consolidation the original has the photo with the same SHA-256 (marked "From BUF/LA/D11/2026/000002"), the witness, and the follow-up "Originally recorded on BUF/LA/D11/2026/000002 by UAT LGA Chief Lagos at 8 Oct 2026 05:45". The duplicate is invalid and locked, and the dashboard's awaiting count fell from 217 to 216, so the kidnapping counts once. |
| Items above the consolidator's clearance are left in place and listed | **Pass (tests).** `ConsolidationTest` gives a Deputy State Director (confidential clearance) the invalidate right. Secret evidence and a secret follow-up are listed as "not consolidated (above your clearance)" without titles, and stay on the duplicate. |
| A senator recorded against the wrong person is reassigned with a reason; both histories show it | **Pass.** |
| Person merging (10A) still passes its acceptance checks | **Pass: 9/9.** The first run failed one check because the Kano officer's "Temporary access revoked" notification was still in the queue a few seconds after the merge. The script now waits up to 30 seconds for it, and the re-run passed. |
| `make test`, `make lint`, `make ci` | See above. |

## Deviations and decisions

- **Copied follow-ups may be raised in classification.** The prompt says
  "same classification", but a Phase 5C trigger refuses a follow-up below
  its incident. A follow-up copied into a higher-classified original takes
  the original's level (recorded in the manifest).
- **Attribution is a link, not text in the body.** `copied_from_update_id`
  points to the source, and the timeline renders "Originally recorded on …
  by … at …" from it. The copied body is identical, and the source row
  (append-only, hash-chained) is untouched.
- **The duplicate's soft-deleted children are ignored**, as on the
  incident page.
- **"Possible duplicates" already linked to this flow** (5C opened the
  invalidate dialog with the original); only the consolidation step was
  added.
- **A closed or invalid original** gets a validation message ("cannot
  receive content") rather than a bare 403.
- **Skipped property rows** append to `description`: property rows have no
  notes column.
- **Flashpoint and threat links** are created in the flashpoint's or
  threat's place (links belong to them, a 6A/6B rule), with the note
  "Consolidated from BUF/…" on flashpoint links.
- **Fixed during development:** the first version of the 5C lock trigger
  checked only the new incident. That was fixed in 10A (migration
  `2026_11_10_000002`), which is what makes moving evidence off a duplicate
  safe: rows can never be moved off a locked incident.

## New packages

None.

## Known issues

- **No undo**, by design. The duplicate's original follow-ups and
  casualties remain on it, and its history stays reachable through its
  reference.
- **Clearance limits** are shown in tests only: every role that can
  invalidate today has secret clearance (as the prompt notes).
- **Moving evidence does not re-scan it** (the file is unchanged); its scan
  status moves with it.

## Anything the next step must know

- **A new child table of incidents** needs a consolidation rule in
  `Consolidation` (plan and apply) and a line in its docblock table.
- **Evidence ownership** can now change: code that caches an evidence
  item's incident must not assume it is permanent. `custody` explains the
  move.
