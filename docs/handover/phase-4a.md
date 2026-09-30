# Handover — Phase 4A: Phase 3 carry-overs, agency formations, coverage reports

Date: 2026-09-30

## Summary

Phase 4A delivers:

- **Three Phase 3 carry-overs:**
  - an optional date of birth in the persons search, so name plus date of birth gives a *Likely match* across jurisdictions;
  - a live end of access when a grant is revoked or expires;
  - server-side pagination of the oversight lists.
- **Agency formations:** the physical presence of security agencies (commands, divisions, stations, bases, checkpoints). They are jurisdiction-scoped and classified, with a parent hierarchy, a map, and a "Security presence" tab on the LGA page.
- **Security coverage reports:** per LGA and period, with agency deployments and the communities they cover. The workflow is draft → submitted → returned/resubmitted → reviewed (locked). Uncovered communities and a comparison with the previous report are computed. Notifications include critical alerts and overdue reminders, and all three dashboards show coverage figures.

Checks:

- `make test`: 979 Pest tests (5833 assertions), 70 of them new, plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- The acceptance checklist passed in the browser (below).

Commits:

| Step | Commit |
|---|---|
| 4A.0 | `e20250e feat(cross-jurisdiction): dob search, live grant end, oversight pagination` |
| 4A.1–4A.5 | `b9e9bba feat(security): add agency formations and security coverage reports` |
| — | `docs: add phase 4a handover` (this file, README, prompt) |

## Acceptance checklist

| Item | Result |
|---|---|
| Record a police division with two stations and a temporary checkpoint in a demo LGA; they appear on the formations map and the LGA's "Security presence" tab | ✅ As `chief.lagos` (Demo LGA LA-1-1; the LGA is preselected for a single-LGA user):<br>- **Apapa Division** (NPF, division, community Olodi Apapa, Area Commander, 180 personnel, location set), recorded through the form;<br>- **Apapa Police Station** and **Ajegunle Police Station** (stations under the division), posted to the same endpoint from the page;<br>- **Mile 2 Temporary Checkpoint** (checkpoint under the division, temporary 28 Sep – 14 Oct 2026), through the form.<br>`/formations` shows the four markers (legend: NPF) and the list. The LGA page's **Security presence** tab shows the same map and the four rows, with the checkpoint marked "temporary". |
| The LGA chief submits a monthly coverage report with three deployments; the uncovered communities panel updates as communities are ticked | ✅ "New coverage report": the LGA and **August 2026** (last completed month) were preselected. Three deployments: NPF (formation Apapa Division, 60 personnel, 6 vehicles), NSCDC (25) and Nigerian Navy (12). The panel went from "Uncovered communities: 3 of 3" to "1 of 3 (Tolu Estate)" as the communities were ticked. The report was submitted rated Partial, with a summary and gaps. Detail page: 97 personnel, 6 vehicles, 1 of 3 uncovered, totals by agency, timeline. |
| The State Director returns it with a reason, the chief fixes and resubmits, the State Director marks it reviewed, and editing is then impossible | ✅ `sd.lagos` saw the sidebar badge **1** on "Coverage reports", a notification and the review panel, then returned the report: "Add the vehicles for Civil Defence and plan cover for Tolu Estate." The chief's edit page showed the reason; they added 3 vehicles and ticked Tolu Estate (panel: "0 of 3"), then resubmitted. The timeline read: Draft created · Submitted · Returned for changes (Bola Lagos Director, with the reason) · Resubmitted. The SD marked it reviewed: "Reviewed by Bola Lagos Director on 30/09/2026, 14:22:23. This report is locked." No action buttons remain, and `/edit` is **403**. The tests cover every other write route and the database lock. |
| A `critical` report notifies the DG live | ✅ With the DG's dashboard open, a critical ad hoc report was submitted as the chief (through the actions, in Tinker). A toast "Critical security coverage reported — Demo LGA LA-1-1 LGA, Lagos State rated its security coverage for 28 Sep 2026 – 30 Sep 2026 as critical." appeared without a reload, and the bell went from 1 to 2. |
| The state dashboard shows every LGA's report status for the month | ✅ `sd.lagos` dashboard, "Coverage reports: September 2026 · Due 5 Oct 2026": all 12 Lagos LGAs listed, LA-1-2 *Draft* (created by the SD for this LGA, which has no chief), the rest *Missing*. The national dashboard shows compliance for August: Lagos 1/12 (8%), Kano 0/12. |
| `make test` and `make lint` pass | ✅ (and `make ci`) |

## What was built

### 4A.0 — Phase 3 carry-overs

1. **Date of birth in the persons search.**
   - `/persons/search` takes an optional `dob` (`Y-m-d`, not in the future). `MatchCriteria::fromSearchTerm($term, $dob)` passes it with the name, so name plus date of birth can reach `strong`. A name alone still never produces a hit.
   - The field appears only for users who get hits, with the help text "Add a date of birth to check other states' records by name."
2. **Immediate end of access.**
   - `App\Events\AccessGrantEnded` (`ShouldBroadcast`, dispatched after commit) goes to the holder's `users.{ulid}` channel as `access-grant.ended`, with payload `{grant: ULID}` only.
   - It is dispatched by `RevokeGrant` and by `buffalo:expire-access-grants`, once per grant.
   - The person page carries `grant.ulid`. `GrantEndedWatcher` listens for the event, and also fires at `expiresAt` (unless that is more than about 24.8 days away, the `setTimeout` limit). It then replaces the page with "Your temporary access to this record has ended."
3. **Oversight pagination.**
   - Requests, grants and match events are paginated at 25 per page, each with its own page parameter (`requests_page`, `grants_page`, `events_page`), keeping the filters (`withQueryString`).
   - The per-officer table is still capped at 50 rows.

### 4A.1 — Agency formations

- **Migration `2026_10_04_000001_create_agency_formations_table`:** the prompt's columns, plus a generated `search_name` with a trigram GIN index. Database guarantees:
  - composite FKs `(lga_id, state_id)` → `lgas` and `(community_id, lga_id)` → `communities`;
  - CHECKs on type, status, classification and strength ≥ 0; a temporary formation needs `active_from`; `active_to` is not before `active_from`; a formation is not its own parent;
  - trigger `buffalo_agency_formation_rules`: a state agency's formation must be in its state; a parent must be of the same agency; no cycles (walks up, with a depth guard of 50);
  - the location is inside the LGA boundary, reusing the Phase 2 trigger function `buffalo_community_location_in_lga()`.
- **`AgencyFormation` model:** `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` (each view logged with context `tab:<name>`).
  - `pointFrom` / `withCoordinates`.
  - `search()`: substring `LIKE` or the `<%` word-similarity operator, both served by the trigram index.
- **`AgencyFormationPolicy`:** permission plus own jurisdiction (`canAccessOwn`; formations are never grantable) plus classification.
- **`AgencyFormationRequest`:**
  - The agency and LGA are chosen once. An active agency is required, and a state agency must match the LGA's state.
  - The parent must be visible to the user, of the same agency, and neither the formation itself nor a descendant.
  - The community must be in the LGA, and the location in Nigeria and in the LGA boundary.
  - The official phone accepts digits and `+()- ` only.
  - The classification must be assignable by the user.
  - `parent` absent on edit means unchanged, so a parent the user cannot see is kept.
- **`SaveAgencyFormation`:** location changes are a `location_changed` event with coordinates.
- **`buffalo:expire-temporary-formations`** runs daily at 00:30. It sets active temporary formations whose `active_to` has passed to `inactive`, and logs `formation_expired`.
- **Reference usage:** `security_agencies` in use by `agency_formations` and `coverage_report_deployments` (`App\Support\Security\ReferenceUsageChecks`).
- **UI:**
  - `/formations`: filters (agency, type, status, state, LGA, temporary), name search, and a map with one colour per agency plus a legend. The `MapMarker.color` option in `BuffaloMap` draws a coloured dot.
  - `/formations/{ulid}`: details, a parent that is "A formation you cannot view" when hidden, sub-formations, map, history.
  - The create/edit form uses the map point picker.
  - The LGA page has a new **Security presence** tab.

### 4A.2 — Coverage reports

- **Migration `2026_10_04_000002_create_coverage_report_tables`:**
  - **`coverage_reports`** (the prompt's columns). CHECKs:
    - period ordering and **period boundaries** (ISO Monday–Sunday, calendar month, calendar quarter);
    - anything past draft has `submitted_at`, a rating and a summary; reviewed has `reviewed_at`; returned has a non-blank reason.

    A partial unique index allows one non-deleted report per LGA, type and start for non-ad-hoc types.
  - **`coverage_report_deployments`:** composite FK `(coverage_report_id, lga_id, state_id)` → the report, so a deployment's place always equals its report's; soft deletes; CHECKs.
  - **`coverage_deployment_communities`:** composite FKs keep the community and the deployment in the same LGA; unique pair.
  - **Triggers:**
    - `buffalo_coverage_deployment_formation`: the formation belongs to the deployment's agency and state.
    - `buffalo_coverage_report_locked`: every change to a reviewed report, its deployments or their communities is rejected.
  - **`coverage_reminders`:** reminders already sent, once per scope (lga/state), place, period type and start.
- **Models:**
  - `CoverageReport`: `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads`.
  - `CoverageReportDeployment`: its own `HasJurisdiction` (access rule 9), **and** a global scope requiring its report to be visible, which adds the report's classification.
- **`CoverageReportPolicy`:**
  - `create` / `createFor(Lga)`: `coverage.create` and the LGA within reach.
  - `update` / `submit`: draft or returned; the creator, or the State Director or Deputy of the state.
  - `review` (return and mark reviewed): `coverage.review` and status submitted.
  - `delete` (discard): the creator, drafts only.
- **`CoverageReportRequest`** (one form for save and submit):
  - The LGA and period are chosen once, with boundaries and uniqueness checked.
  - Deployments are the full list: a `ulid` updates an existing row, no `ulid` makes a new one, and rows left out are soft-deleted.
  - Each deployment needs an active agency. The formation must be of that agency and the report's state; `formation` absent means unchanged. Covered communities must be in the LGA, and several deployments may cover the same community.
  - Rules on submit: a rating and a summary; the gaps unless the rating is adequate; at least one deployment unless the rating is critical.
- **Actions:**
  - `SaveCoverageReport`;
  - `SubmitCoverageReport` (notifies SD and DSD; a critical rating also notifies the DG and covering DDGs with severity `critical`);
  - `ReturnCoverageReport` (reason required; notifies the submitter);
  - `ReviewCoverageReport` (optional comment; locks the report; notifies the submitter);
  - `DiscardCoverageReport`.

  Each transition is a named activity event. `coverage_report_submitted` and `coverage_report_reviewed` carry a complete **`version` snapshot** (`CoverageSnapshot`: header, deployments with agency and formation ULIDs, counts, and covered community ULIDs), so every submitted and reviewed version can be reconstructed.
- **Computed (`CoverageAnalysis`):**
  - totals by agency;
  - uncovered communities (the LGA's communities minus those covered);
  - comparison with the latest earlier submitted or reviewed report of the same LGA and period type: personnel change, rating change (improved / worsened / unchanged) and newly uncovered communities.
- **Reminders:**
  - `CoverageSchedule` reads `coverage.expected_period` (`BUFFALO_COVERAGE_PERIOD`, default `monthly`) and `coverage.grace_days` (`BUFFALO_COVERAGE_GRACE_DAYS`, default 5).
  - `buffalo:coverage-reminders` runs daily at 08:00. After the grace days following the last completed period, it tells the LGA chiefs of each LGA without a submitted or reviewed report (`CoverageReportMissing`), and each State Director the list of their missing LGAs (`CoverageReportsMissingSummary`). Each goes out once per period.
- **UI:**
  - `/coverage-reports`: filters (state, LGA, period type, "period including" date, status, rating), with "Awaiting your review" marked.
  - **Form:**
    - the period picker: last completed and current period buttons, any day of another period, or from/to dates for ad hoc;
    - the deployments table: agency, formation filtered to the agency, counts, patrol frequency, a community checklist, areas and remarks;
    - the live **uncovered communities** panel;
    - rating, classification and narrative;
    - "Save draft" and "Submit for review" (with a confirmation).
  - **Detail page:** stat tiles, deployments, totals by agency, narrative, uncovered communities with a map of those with locations, the comparison, the timeline, and review and return forms for reviewers.

### 4A.3 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `formations.view`, `formations.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `formations.delete` | ✓ | ✓ | ✓ | | |
| `coverage.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `coverage.create` | | | ✓ | ✓ | ✓ |
| `coverage.review` | | | ✓ | ✓ | |

Super admin has none. Changes:

- the `Permission` enum;
- a `'security'` block in `RolesAndPermissionsSeeder::matrix()`;
- the drift tests;
- `types/auth.ts`;
- `navigation.test.ts`.

### 4A.4 — UI and dashboards

- **Navigation:** a **Security** section with "Agency formations" and "Coverage reports". The latter shows a badge counting submitted reports the user may review: shared prop `badges.coverageReview`, rendered with `SidebarMenuBadge`.
- **Dashboards** (`CoverageFigures`, all through the scopes, so a report the viewer cannot see counts as missing):
  - **LGA:** the current period's report status and due date (period end plus grace days), the last submitted rating, and the uncovered communities count.
  - **State:** every active LGA of the state for the current period, with status (missing / draft / submitted / returned / reviewed) and rating, plus the reports awaiting review.
  - **National:** compliance by state for the last completed period (submitted or reviewed ÷ active LGAs; states without LGAs are omitted), and LGAs rated inadequate or critical, grouped by state.
- **Placeholders:** the Phase 3 "Cross-state access requests" placeholder card is removed, since it was delivered in 3C. The incidents, flashpoints and escalations placeholders stay until Phases 5 and 6.

### 4A.5 — Tests (70 new)

- **Carry-overs** (6 in `tests/Feature/CrossJurisdiction/`):
  - name plus matching date of birth gives *Likely match*; name alone, a different date of birth, and a future date are handled;
  - `AccessGrantEnded` is broadcast on revocation and on expiry, once, on `private-users.{ulid}`, with payload `{grant}` only;
  - the grant ULID is on the person page;
  - oversight pagination keeps the filters.
- **`FormationsTest` (24):**
  - the jurisdiction matrix (15 roles) and classification;
  - a record, its view logging and its location event, with out-of-jurisdiction access refused;
  - a state agency's formations stay in its state (HTTP and database);
  - parent same-agency and no cycles (HTTP and database), and an absent parent is kept;
  - a hidden parent is never named;
  - location in the LGA boundary (HTTP and database);
  - temporary formations need dates, and the expiry job;
  - delete rights, and the agency counted as in use;
  - list, map, LGA tab and search.
- **`CoverageReportsTest` (37):**
  - the jurisdiction matrix for reports **and** deployments;
  - classification for both;
  - 7 period-boundary cases, plus the database CHECK;
  - uniqueness, and a discarded draft frees its period;
  - community-in-LGA and formation-matches-agency (HTTP and database);
  - submit rules;
  - who may create (including the SD for a vacant LGA) and who may edit;
  - the full workflow: the LGA chief cannot review, a reason is required, resubmission works, and after review all edits are refused over HTTP and by the database; plus the timeline and snapshots;
  - discard rules; deployment sync;
  - totals, uncovered communities and comparison;
  - notification recipients (exact, including critical to the DG and the right DDGs only);
  - the sidebar badge;
  - dashboards across jurisdiction and classification (the DSD sees a secret report as missing; a zone B DDG sees only zone B);
  - an LGA chief sees who at state level returned the report.
- **`CoverageRemindersTest` (3):** nothing within the grace days; exactly the right chiefs and State Directors, once per period, then again next period; configurable period and grace days.

## Deviations and conflicts

- **The agency and LGA of a formation, and the LGA and period of a report, are fixed once created.** The prompt does not say whether they may change. Changing them would move records across jurisdictions, and would break parent, deployment and uniqueness rules.
- **`overall_rating` and `summary` are nullable on drafts.** The prompt lists the rating without saying "nullable". A draft may be saved before rating; the database requires both once submitted.
- **Deployments carry authors, timestamps and soft deletes** (CLAUDE.md section 5 operational tables), which the prompt's table does not list. Removed deployments are soft-deleted.
- **Covered-community links are replaced per deployment and are not individually audited.** They are a pure link table (with a composite FK to the LGA). Every submitted and reviewed version, including covered community ULIDs, is snapshotted in the activity log, which meets "Submitted and reviewed versions are reconstructable"; draft-time changes to the ticks are not logged one by one.
- **Reviewed reports are locked in the database too** (triggers), not only in policies.
- **Reminder de-duplication uses a small table (`coverage_reminders`)** rather than the cache, so a Valkey flush cannot re-send. The LGA chief reminder is also once per period, since the prompt's "daily job notifies" did not say how often.
- **The workflow notifications to State Directors go to all active SDs and DSDs of the state** (`NotifyRole`), independent of delegation (a Phase 3C concept for access requests only).
- **A second sanctioned scope bypass: `App\Support\Jurisdiction\OfficerNames`.** It returns names only, used for the coverage timeline and the "returned by / reviewed by" names. Users are jurisdiction-scoped, so an LGA chief could not see the State Director who returned their report; the browser run showed "System" before the fix, and a test now covers it.
- **Timestamps:** the new workflow columns (`submitted_at`, `reviewed_at`, `returned_at`, `coverage_reminders.sent_at`) are plain `timestamp`, like the Phase 3C tables. `timestamptz` showed times an hour late: the Postgres session runs in UTC while the app writes Lagos wall-clock times. See Known issues for the older columns.
- **The parent-formation choices and the deployment formation lists are capped** (1,000 and 2,000 visible formations) and filtered in the browser.
- **Placeholders:** only the delivered Phase 3 access-request placeholder was removed. "Replace any remaining placeholders" cannot apply to incidents and flashpoints (Phases 5 and 6).

## New packages

None.

## Known issues and notes

- **Pre-existing `timestamptz` columns show times one hour late.**
  - Affected: `lga_profiles.last_reviewed_at` (2B), and `persons_of_interest.last_sighted_at` and `last_reviewed_at` (3B).
  - The Postgres session time zone is UTC while Laravel writes Africa/Lagos wall-clock values without an offset.
  - Fix, a small cross-cutting change: set `'timezone' => config('app.timezone')` on the `pgsql` connection, and convert existing data. Left out of this step as it touches earlier phases' data.
- **Formations of other LGAs cannot be chosen as a parent by an LGA chief** (they are not visible to them). A State Director can set such parents.
- **The uncovered-communities map** shows only communities with a recorded location. The demo LGA's communities have none, so the map was not visible in the browser run.
- **National compliance** counts a report the viewer cannot see (above their clearance) as missing. The DG and DDGs see secret reports, so this affects nobody today.
- **Dev database changes:**
  - community "Tolu Estate" in Demo LGA LA-1-1 (fictional; dev data only);
  - formations Apapa Division, Apapa and Ajegunle Police Stations, Mile 2 Temporary Checkpoint;
  - coverage reports: LA-1-1 August 2026 (reviewed), LA-1-1 ad hoc 28–30 Sep (critical, submitted), LA-1-2 September (draft);
  - test password and TOTP set on `sd.lagos`, `chief.lagos` and `dg`;
  - permissions seeded; Horizon and the scheduler restarted.

  `make fresh` resets the data.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next step (4B)

- **Organizations:** CLAUDE.md now lists contact phone and email of organization contacts as encrypted with blind indexes. Follow the persons pattern (`UsesCipherSweet`, CHECKs rejecting plaintext).
- **Sites** should follow the formations pattern: composite FKs, the location trigger function, `HasJurisdiction` plus `HasClassification`, `LogsReads`, and access rule 9 for any child rows.
- **Workflow records that cross levels** (who acted) should use `OfficerNames`, not the user relation.
- **Tabs on the LGA page:** add to `LgaController::TABS` and `TAB_LABELS` in `pages/lgas/show.tsx`, and update `LgaPagesTest`.
- **Sidebar badges:** extend `badges` in `HandleInertiaRequests` and `NavItem.badge`.
