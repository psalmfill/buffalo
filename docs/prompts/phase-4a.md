# BUFFALO — Phase 4A: Phase 3 Carry-overs, Security Agency Formations and Coverage Reports

Read `CLAUDE.md` before starting (it has been updated for Phase 4: see access rule 9 and the decisions log). Read `docs/handover/phase-3-summary.md` and `docs/handover/phase-3c.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phases 1–3 are complete: hierarchy, users, jurisdiction and classification, reference data, LGA and community information, persons, POIs, political offices, criminal groups, and cross-jurisdiction hits, requests and grants.

Phase 4 covers the brief's "security agencies", "security coverage" and "companies/organizations". It is split into:

- **4A (this step):** three small Phase 3 carry-overs, agency formations, and security coverage reports.
- **4B:** organizations, their sites and contacts.

---

## Step 4A.0 — Phase 3 carry-overs

1. **Optional date of birth in person search.** Add a date-of-birth field to the persons search form (`/persons/search`), passed to `PersonMatchService` with the name so name + DOB can produce `strong` matches. Help text: "Add a date of birth to check other states' records by name." Name alone still never produces a cross-jurisdiction hit. Tests: name + matching DOB in another state produces a `strong` hit; name alone does not.
2. **Immediate end of access.** When a grant is revoked or expires, broadcast an `AccessGrantEnded` event on the holder's `users.{ulid}` channel (payload: grant ULID only). A person page open under that grant listens for it and replaces the page with "Your temporary access to this record has ended." Tests: the event is broadcast on revocation and on expiry, with no person details in the payload.
3. **Oversight pagination.** Replace the capped oversight lists (requests, grants, match events) with server-side pagination (25 per page) and keep the existing filters.

**Commit:** `feat(cross-jurisdiction): dob search, live grant end, oversight pagination`

## Step 4A.1 — Agency formations

The `security_agencies` reference table (2A) lists agencies. This step records their **physical presence**: commands, divisions, stations, bases, checkpoints.

`agency_formations` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `security_agency_id` | FK. If the agency has a `state_id` (a state outfit), the formation must be in that state |
| `parent_formation_id` | nullable self-FK (e.g. station → division → command); parent must be the same agency; no cycles |
| `name` | required, e.g. "Ikeja Division" |
| `formation_type` | enum: `headquarters`, `command`, `division`, `area_command`, `station`, `post`, `base`, `barracks`, `checkpoint`, `outpost`, `office`, `other` |
| `state_id`, `lga_id` | required; composite FK pattern |
| `community_id` | nullable; composite FK to `communities(id, lga_id)` |
| `address` | nullable text |
| `location` | nullable point (must fall in LGA when boundaries exist; reuse the Phase 2 trigger function pattern) |
| `official_phone` | nullable (the formation's official line, not a person's number; plain text) |
| `commander_title` | nullable string, e.g. "DPO", "Commanding Officer" |
| `commander_name`, `commander_rank` | nullable strings (the current holder; a free-text official role, not a `persons` record) |
| `personnel_strength` | nullable integer ≥ 0 |
| `is_temporary` | boolean; required `active_from` / optional `active_to` when true (e.g. a temporary checkpoint) |
| `status` | enum: `active`, `inactive`, `closed` |
| `classification` | default `restricted` |
| `notes` | nullable text |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`.
- Register `agency_formations.security_agency_id` with `ReferenceUsage`.
- Trigram index on name for search.
- A scheduled daily job sets temporary formations past `active_to` to `inactive` (system context, logged).

## Step 4A.2 — Coverage reports

A coverage report is an LGA's statement, for a period, of which agencies are deployed where, what they cover, and where the gaps are.

**`coverage_reports`**

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id` | required; composite FK |
| `period_type` | enum: `weekly`, `monthly`, `quarterly`, `ad_hoc` |
| `period_start`, `period_end` | dates; end ≥ start; for non-ad-hoc types they must match the period boundaries (Monday–Sunday, calendar month, calendar quarter) |
| `overall_rating` | enum: `adequate`, `partial`, `inadequate`, `critical` |
| `summary` | text, required on submit |
| `gaps_identified` | text, required on submit when rating is not `adequate` |
| `recommendations` | text, nullable |
| `status` | enum: `draft`, `submitted`, `returned`, `reviewed` |
| `submitted_by`, `submitted_at` | |
| `reviewed_by`, `reviewed_at`, `review_comment` | |
| `returned_by`, `returned_at`, `return_reason` | reason required |
| `classification` | default `restricted` |
| `created_by`, `updated_by`, timestamps, soft deletes | |

Partial unique index: one non-deleted report per (`lga_id`, `period_type`, `period_start`) for non-`ad_hoc` types.

**`coverage_report_deployments`**

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `coverage_report_id` | FK |
| `state_id`, `lga_id` | copied from the report (access rule 9); composite FK |
| `security_agency_id` | FK (register with `ReferenceUsage`) |
| `agency_formation_id` | nullable; must belong to the same agency and be in the same state |
| `personnel_count` | integer ≥ 0 |
| `vehicles_count` | integer ≥ 0, nullable |
| `patrol_frequency` | enum: `continuous`, `daily`, `several_weekly`, `weekly`, `occasional`, `none` |
| `areas_covered` | text, nullable |
| `remarks` | text, nullable |

**`coverage_deployment_communities`**: `coverage_report_deployment_id`, `community_id` (must be in the report's LGA), unique pair. Records which communities each deployment covers.

**Computed on the report (not stored):**
- Total personnel and vehicles, by agency.
- **Uncovered communities:** communities in the LGA not covered by any deployment in the report.
- Comparison with the previous report of the same period type: personnel change, rating change, newly uncovered communities.

**Workflow:**

| Transition | Who | Rules |
|---|---|---|
| create draft | LGA chief (own LGA); SD or DSD (any LGA in their state, e.g. when the chief post is vacant) | |
| edit draft / returned | the creator, or SD/DSD of the state | |
| submit | same as edit | validation as above; at least one deployment, unless rating is `critical` |
| return | SD, DSD | reason required; status `returned`; editable again |
| mark reviewed | SD, DSD | optional comment; status `reviewed`; **locked** — no further edits by anyone |
| discard | the creator, drafts only | soft delete; submitted reports are never deleted |

- Every transition is a named activity event. Submitted and reviewed versions are reconstructable from the activity log.
- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`.

**Notifications** (`BuffaloNotification`, no report content in payloads):
- Submitted → State Director and Deputy of the state (`NotifyRole`).
- Returned / reviewed → the submitter.
- A report rated `critical` on submission → also the DG and the DDGs covering the state, severity `critical`.

**Reminders:** `config/buffalo.php` → `coverage.expected_period` (default `monthly`, env `BUFFALO_COVERAGE_PERIOD`) and `coverage.grace_days` (default 5). A daily job notifies LGA chiefs with no submitted report for the last completed period once the grace days pass, and sends each State Director a list of LGAs missing that report (once per period).

## Step 4A.3 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `formations.view`, `formations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `formations.delete` | ✓ | ✓ | ✓ | | |
| `coverage.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `coverage.create` | | | ✓ | ✓ | ✓ |
| `coverage.review` | | | ✓ | ✓ | |

Super admin: none. All subject to jurisdiction and classification. Update `RolesAndPermissionsSeeder::matrix()`, the drift tests and `resources/js/types/auth.ts`.

## Step 4A.4 — UI

Navigation: new section **"Security"** with "Agency formations" (`formations.view`) and "Coverage reports" (`coverage.view`), the latter with a badge for reports awaiting the user's review.

**`/formations`**: list with filters (agency, type, status, state, LGA, temporary), search by name, and a map with markers styled per agency. **`/formations/{ulid}`**: details, parent and child formations, map, history. Add a **"Security presence"** tab on the LGA page listing formations in the LGA.

**`/coverage-reports`**: list with filters (LGA, period type, period, status, rating). **Create/edit** as one page: header fields, a deployments table (add rows: agency, formation filtered to that agency, counts, patrol frequency, community multi-select from the LGA, areas, remarks), then summary, gaps and recommendations. A panel shows uncovered communities live as deployments change. **Detail page:** everything read-only, the computed totals, uncovered communities (with a map of those that have locations), comparison with the previous report, the workflow timeline, and action buttons per role.

Dashboards (replace any remaining placeholders):
- **LGA chief:** current period report status and due date; last rating; uncovered communities count.
- **State roles:** a table of all LGAs in the state for the current period: status (missing / draft / submitted / returned / reviewed) and rating; reports awaiting review.
- **National:** submission compliance by state for the last completed period (percentage of LGAs reviewed or submitted); LGAs rated `inadequate` or `critical`, grouped by state.

Run `make wayfinder` after route changes.

## Step 4A.5 — Tests

- Carry-overs as listed in 4A.0.
- Formations: scope and classification matrix; state-agency formations only in their state; parent must share the agency; no cycles; temporary formation expiry job; location-in-LGA check.
- Coverage: scope and classification matrix for reports and deployments (deployments carry their own `state_id`/`lga_id`); period boundary validation; uniqueness per LGA and period; community must be in the LGA; formation must match the agency.
- Every workflow transition per role, including: LGA chief cannot review; reviewed reports reject all edits via HTTP; only drafts can be discarded; SD can create for a vacant LGA.
- Uncovered communities and previous-report comparison calculations.
- Notifications and reminders reach exactly the right users; critical rating alerts national.
- Dashboard figures respect jurisdiction and classification.

## Acceptance checklist

- [ ] Record a police division with two stations and a temporary checkpoint in a demo LGA; they appear on the formations map and the LGA's "Security presence" tab.
- [ ] The LGA chief submits a monthly coverage report with three deployments; the uncovered communities panel updates as communities are ticked.
- [ ] The State Director returns it with a reason, the chief fixes and resubmits, the State Director marks it reviewed, and editing is then impossible.
- [ ] A `critical` report notifies the DG live.
- [ ] The state dashboard shows every LGA's report status for the month.
- [ ] `make test` and `make lint` pass.

## Out of scope

Organizations (4B). Linking incidents to formations or reports (Phase 5).

## Handover

Write `docs/handover/phase-4a.md` per CLAUDE.md section 9 and print it.
