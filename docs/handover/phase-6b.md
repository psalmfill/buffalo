# Phase 6B handover — Threat reports (early warnings)

Date: 2026-10-01

## What was built

### 6B.1 Threat reports

**`threat_reports` table**

- **Columns:** every column in the prompt, plus `expiry_warned_for` (bookkeeping for the 24-hour warning).
- **Database checks:**
  - CHECK constraints for every enum;
  - a radius (100–50,000 m) only with a point;
  - `expected_until >= expected_from`;
  - title and description present;
  - **any status other than `active` needs a `status_note`**.
- **Place:**
  - composite FKs `(lga_id, state_id)` and `(community_id, lga_id)`;
  - GIST index on `location`;
  - the existing location-in-LGA trigger.
- **Model:** `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`; views above `restricted` are access-logged.
- **References:** `THR/<STATE>/<YEAR>/<5 digits>`, from `threat_report_counters` with the same atomic-upsert, gap-free approach as incidents (`ThreatReferenceAllocator`), assigned in the creating transaction.
- **Threat type:** registered with `ReferenceUsage`.
- **Named events.** Credibility, urgency, status, status note and expiry are excluded from the generic "updated" entry and logged as:
  - `credibility_changed`, `urgency_changed` (old, new, the required comment);
  - `status_changed` (from, to, note, incident);
  - `extended`, `reactivated` (old and new expiry, comment);
  - `expired` (no causer);
  - `area_changed`.
- **Expiry (creation):**
  - given, or `expected_until`, or `default_expiry_days` (14) from now;
  - always in the future and at most `max_expiry_days` (90) ahead.
  - Afterwards it changes only through Extend or Reactivate, each also within 90 days from that moment.

### 6B.2 Links

**Tables:** `threat_report_flashpoints`, `threat_report_criminal_groups` (`role`: suspected / named in report) and `threat_report_incidents` (`relation`: materialised as / related).

- **Place:** on the threat report's place, with a cascading composite FK.
- **Columns:** `linked_by`, `linked_at`, `unlinked_by`, soft delete; one active link per pair.
- **Visibility:** each model's global scope requires both sides visible, so lists and counts never include hidden links.
- **Rules:**
  - Linking needs `update` on the report and `view` on the record.
  - Drafts and invalid incidents are refused.
  - Re-linking an incident with another relation updates the relation (logged).
  - Link and unlink are named events on the report (ULIDs only).

**Incident submission hook (`SubmitIncident`)**

- **Matcher:** `ThreatMatching`, a sanctioned scope bypass in `App\Support\Jurisdiction`; ids only.
- **What it does:** records in `threat_report_incident_matches` every active threat report that meets all three conditions below.
- **Category:** the threat type's category equals the incident type's.
- **Area:**
  - a point: the incident lies within its radius (the incident needs a location);
  - else a community: the same community;
  - else the LGA: the same LGA.
- **Time:** from the earlier of the expected start and the report's creation, until the later of the expected end and the expiry.

The incident page shows:
- linked threat reports, and matched ones not yet linked ("Possibly related threat report: THR/…" in the header and a section), only those the viewer can see;
- **Mark as materialised** where the viewer may manage the report. It opens a dialog with a prefilled note, links the incident as "materialised as" and sets the status in one transaction.

### 6B.3 Expiry and lifecycle

- **`buffalo:expire-threat-reports`** (every 15 minutes, system context):
  - expires active reports past `expires_at` (note "Expired automatically.", `expired` event, broadcast);
  - warns the creator and SD/DSD once per expiry time, 24 hours ahead (`expiry_warning_hours`).
- **Extend:** active reports only, `update` permission, new expiry plus a comment.
- **Reactivate:** expired, mitigated or withdrawn reports (not materialised), `threats.reactivate` (DG, DDG, SD), new expiry plus a comment.
- **Status actions:** only from `active`, to mitigated, materialised or withdrawn, with a required note.

### 6B.4 Notifications

All go through `ThreatNotifier`, filtered per recipient by `view`; the acting officer is left out.

**Payload:** reference, threat type, urgency, LGA name and link. A test asserts that the description, target and source notes are absent.

| Event | Recipients | Severity |
|---|---|---|
| Created, immediate | SD, DSD, chief(s) of the LGA, DG, DDGs covering the state | critical |
| Created, priority | SD, DSD, chief(s) of the LGA | warning |
| Created, routine | nobody now; `buffalo:threat-digest` at 07:00 sends each SD/DSD the routine reports of the last 24 hours that *they* can see (up to 20 references listed, with the total) | info |
| Urgency raised to immediate, or credibility raised to confirmed | as immediate | critical |
| Materialised | creator, SD, DSD, plus DG and covering DDGs if the report was immediate | warning |
| Expiring within 24 hours | creator, SD, DSD | info |

**Real-time:**
- `ThreatReportCreated` (`threat.created`) and `ThreatReportChanged` (`threat.changed`) are broadcast on **parallel channels**: `states.{state_ulid}.threats` and `national.threats`.
- They are gated on `threats.view` and on the same coverage rule as the incident channels.
- I chose parallel channels because the incident channels are gated on `incidents.view`.
- Payload: `{threat, event}` only.
- The report page and the dashboards reload through their scoped routes.

### 6B.5 Permissions

| Permission | Holders |
|---|---|
| `threats.view`, `threats.create`, `threats.manage` | all five intelligence roles |
| `threats.reactivate`, `threats.delete` | DG, DDG, SD |

- LGA chiefs manage (edit, status, links, extend) only the reports they created; this is enforced in `ThreatReportPolicy::update`.
- Super admin has none.
- The matrix, `auth.ts`, the PHP drift test and the navigation test are updated.

### 6B.6 User interface

- **Navigation:** the section is renamed **"Threats & flashpoints"**, with "Threat reports" (badge: active immediate threats in reach, via the shared `badges.immediateThreats`) and "Flashpoints & blackspots".
- **`/threats`:**
  - active only by default ("Any status" available), sorted by urgency, then expected window, then expiry;
  - filters: threat type, credibility, urgency, status, state, LGA, expiring within 48 hours;
  - a map with points and radius circles coloured by urgency.
- **Form:**
  - all fields;
  - a credibility and urgency explanation panel that highlights the chosen levels;
  - source notes help text warning against identifying informants;
  - an optional map area with point and radius;
  - on creation, flashpoint and criminal group pickers (with role);
  - a change comment appears when credibility or urgency is edited.
- **`/threats/{ulid}`:**
  - Overview: live countdown to expiry, expected window, details, map with the area and linked flashpoints;
  - status actions with required notes (Mark mitigated, Mark materialised with an incident picker, Withdraw), Extend, Reactivate, Edit, Delete;
  - Links tab: flashpoints, groups and incidents, with pickers and unlink;
  - Timeline of named changes with comments;
  - History;
  - the page reloads live on `threat.changed`.
- **Incident page:** header badge and a "Threat reports" section.
- **Flashpoint page:** a "Threat reports" tab.
- **Dashboards** (live via the threat channels):
  - LGA: active threats in my LGA;
  - State: active by urgency, expiring within 48 hours, materialised in the last 14 days;
  - National: active immediate threats at the top; active threats by state and type.
- **Pickers:** new `POST /pickers/flashpoints` endpoint.

## Acceptance (browser, dev)

- [x] **Immediate threat:** chief.lagos recorded THR/LA/2026/00001 through the form: "Planned attack on Olodi weekly market this weekend", Banditry, **immediate**, **probable**, community Olodi Apapa, expected Sat 3 Oct 06:00 – Sun 4 Oct 20:00.
  - The expiry defaulted to the expected end.
  - The SD, DSD, DG and the second LA-1-1 chief received **critical** notifications.
  - Live delivery was confirmed with the SD's dashboard open: a second immediate report (THR/LA/2026/00002) raised the "Active · Immediate" count from 1 to 2 and showed the "Immediate threat reported" toast without a reload.
- [x] **Matching incident:** BUF/LA/D11/2026/000006 (Banditry, Olodi Apapa) was submitted.
  - Its page shows "Possibly related threat report: THR/LA/2026/00001 (+1)".
  - **Mark as materialised** linked it as "materialised as" and set the report to Materialised.
  - The DG (national, because the report was immediate), the creator and the DSD were notified.
- [x] **Routine threat:** THR/LA/2026/00003 produced no individual notification. `buffalo:threat-digest` sent the SD and DSD "1 routine threat report in the last 24 hours: THR/LA/2026/00003 — Armed robbery, Demo LGA LA-1-1 LGA".
- [x] **Expiry and reactivation:** THR/LA/2026/00002 was left unattended; its expiry was backdated on dev to simulate time passing.
  - The expiry job expired it ("Expired automatically", by System).
  - The SD reactivated it until 8 Oct with a comment; the timeline shows all three steps.
- [x] **Checks:** `make lint` passes; `make test` passes (1285 Pest tests, 8823 assertions, plus Vitest); `make ci` was run (result below).

## Files

### Created

- **Enums:** `app/Enums/{ThreatCredibility, ThreatUrgency, ThreatSourceType, ThreatStatus, ThreatGroupRole, ThreatIncidentRelation}.php`
- **Models:** `app/Models/{ThreatReport, ThreatReportFlashpoint, ThreatReportCriminalGroup, ThreatReportIncident}.php`
- **Actions:** `app/Actions/Threats/{CreateThreatReport, ThreatReportAttributes, UpdateThreatReport, ChangeThreatStatus, SetThreatExpiry, LinkToThreatReport, UnlinkFromThreatReport, ExpireThreatReports}.php`
- **Support:**
  - `app/Support/Threats/{ThreatReferenceAllocator, ThreatNotifier, ThreatLinks, ReferenceUsageChecks}.php`
  - `app/Support/Jurisdiction/ThreatMatching.php`
  - `app/Support/Dashboard/ThreatReportFigures.php`
- **Events and notifications:**
  - `app/Events/Threats/{ThreatReportBroadcast, ThreatReportCreated, ThreatReportChanged}.php`
  - `app/Notifications/{ThreatNotification, ThreatDigest}.php`
- **Policy and commands:**
  - `app/Policies/ThreatReportPolicy.php`
  - `app/Console/Commands/{ExpireThreatReports, SendThreatDigest}.php`
- **HTTP:**
  - `app/Http/Controllers/Threats/{ThreatReportController, ThreatLifecycleController, ThreatLinkController}.php`
  - `app/Http/Requests/Threats/{ThreatReportRequest, ThreatStatusRequest, ThreatExpiryRequest, ThreatLinkRequest}.php`
  - `app/Http/Presenters/ThreatReportPresenter.php`
  - `routes/threats.php`
- **Database:**
  - `database/migrations/2026_10_11_000001_create_threat_report_tables.php`
  - `database/factories/ThreatReportFactory.php`
- **Frontend:**
  - `resources/js/pages/threats/{index, form, show}.tsx`
  - `resources/js/components/threats/{threat-display, expiry-countdown, action-dialog, status-dialogs, incident-threats}.tsx`
  - `resources/js/components/dashboard/threat-report-figures.tsx`
  - `resources/js/types/threats.ts`
- **Tests:**
  - `tests/Feature/Threats/{ThreatReportTest, ThreatReferenceConcurrencyTest, ThreatLinksAndNotificationsTest}.php`
  - `tests/Support/Threats/ThreatFixtures.php`
- **Docs:** `docs/prompts/phase-6b.md`

### Changed

- **Back end:**
  - `SubmitIncident` (matching hook)
  - `IncidentController` (threats prop)
  - `FlashpointController` (Threat reports tab)
  - `BuildLgaDashboard`, `BuildStateDashboard`, `BuildNationalDashboard`
  - `HandleInertiaRequests` (`immediateThreats` badge)
  - `PickerController` and `routes/pickers.php` (flashpoints)
  - `routes/channels.php`, `routes/console.php`, `routes/web.php`
  - `Permission`, `RolesAndPermissionsSeeder`, `AppServiceProvider`
  - `config/buffalo.php` (`threats`)
- **Frontend:**
  - `navigation.ts`, `nav-main.tsx` (badge labels)
  - `auth.ts`, `global.d.ts`, `types/dashboard.ts`
  - `incidents/show.tsx`, `flashpoints/show.tsx`, the three dashboard pages
- **Tests:** `RolesAndPermissionsTest`, `navigation.test.ts`
- **Docs:** `README.md`

## Deviations and decisions

1. **Real-time:** parallel `states.{ulid}.threats` / `national.threats` channels (the prompt allowed either choice).
2. **Time-window rule.** Matching runs from the earlier of the expected start and the report's creation, until the later of the expected end and the expiry.
   - This reads "inside the expected window **or** before expiry" as: an incident after the report was recorded and before it expires matches even before the window opens.
   - Incidents that occurred before both the window and the report never match.
   - My first version required the window to have opened; the acceptance check showed that would miss an attack that comes early.
3. **Matches are recorded at submission** (`threat_report_incident_matches`). They stay on the incident page after the report expires, and only reports active at submission are considered.
4. **A point needs a radius** (validation). The database also allows a radius only with a point. The matcher defaults to 1,000 m if a point ever lacks one.
5. **Expiry** is set on creation only. Later changes go through Extend or Reactivate, each needing a comment; the edit form rejects `expires_at`.
6. **Links on the form:** flashpoints and groups can be linked while recording. After that, all links, including incidents, are managed on the Links tab; the edit form does not change links.
7. **Materialised** needs at least one linked incident of any relation. An incident given in the same request (picker or incident page) is linked as "materialised as". From the incident page, "one click" opens a confirmation dialog with a prefilled note, because the status note is mandatory.
8. **Status changes** start only from `active`; to leave another status, reactivate first. Reactivation clears `status_note` (kept in the event) and re-arms the expiry warning.
9. **Notifications leave out the acting officer.** When an LGA chief records an immediate threat, the chief is not notified, but any other chief of the same LGA is.
10. **Escalation** ("raised to immediate or confirmed") is detected on edit, comparing before and after; lowering never notifies.
11. **The digest** covers routine reports recorded in the last 24 hours whatever their current status. It is one notification per SD/DSD listing only what they can see; it is not sent to someone who can see none.
12. **Matching cost:** `ThreatMatching` uses each report's own radius without a constant prefilter. Unlike flashpoint matching, it filters on `status = 'active'` (indexed) first, and active threats are few, so the GIST index is not needed there.
13. **Placeholder card:** the "Active flashpoints" placeholder card on the dashboards is untouched; it belongs to the 6C dashboard work.

## Practical problems with threats near LGA or state borders

A threat report is visible only within its own jurisdiction, plus national level. In practice:

1. **Neighbouring LGA chiefs never see it.** A report recorded in LGA A about a target on the border, or an area whose radius reaches into LGA B, is invisible to B's chief. That chief gets no notification, no dashboard entry and no "possibly related" notice. An immediate warning about a market just across the boundary reaches A's chief and the SD, but not the officer who must act.
2. **Cross-state threats reach only national level.** A gang moving from Kano into a neighbouring state is visible to Kano and national users. The neighbouring SD learns of it only if national level passes it on by other means.
3. **Matches across the border are invisible to both sides.** An incident submitted in LGA B can be matched to a report in LGA A when the radius covers it, and the match is recorded. B's officers cannot see the report, and A's chief usually cannot see B's incident, so only the SD (within a state) or national level ever sees the connection. Nobody is told; the match only appears on the incident page.
4. **Only a point and radius can reach outside the recording LGA.** A report's area is its point and radius, its community, or its own LGA. A threat "along the highway through three LGAs" can only be recorded in one of them.
5. **Recording the same threat twice causes duplication.** Officers may record the same warning separately in each affected LGA. Credibility, urgency and status are then managed twice and can drift apart, and there is no merge.
6. **Notifications follow the report's place only.** A threat moved to another LGA by edit does not re-alert the new LGA's chief.

**Suggested approach for a later phase:** explicit **dissemination**. The SD or national level shares a report, read-only, with named LGAs or states. Each share is logged, time-limited by the report's expiry, and notifies the recipients at the report's urgency. Classification still applies, and shared reports join the recipients' dashboards and matching. This is a deliberate push, unlike the person hit/no-hit model, because warnings must reach those who need to act without their asking.

## New packages

None.

## Known issues and notes for 6C

- Report maps show points and circles only. Threats without a point (community or LGA areas) are not drawn; 6C's server-side layers could shade the community or LGA instead.
- The dashboard's live reload refetches `threatReports` and `badges`. Other pages refresh their badge on the next visit.
- **Dev data:**
  - THR/LA/2026/00001 (materialised as BUF/LA/D11/2026/000006);
  - 00002 (reactivated until 8 Oct);
  - 00003 (routine);
  - roles re-seeded with the new permissions.
