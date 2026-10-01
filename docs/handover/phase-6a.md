# Phase 6A handover — Boundaries, flashpoints and blackspots

Date: 2026-10-01

## What was built

### 6A.0 Boundary import

Committed as `88e0aea feat(geo): add boundary import and demo boundaries`.

**`buffalo:import-boundaries {file} --level=lga|state`**

- **Input:** an owner-supplied GeoJSON FeatureCollection. Nothing is fetched from the internet.
- **Matching:**
  - by `--code-property` (with `--state-property` for LGAs, matched case-insensitively to our codes); or
  - by a mapping CSV (`--mapping`, `feature_id,state_code,lga_code`; `--id-property` names the id property when a feature has no `id`).
- **Validation, before anything is written:**
  - Polygon or MultiPolygon only, stored as MultiPolygon;
  - SRID 4326 (a file declaring another `crs` is refused);
  - `ST_IsValid`, repaired with `ST_MakeValid` + `ST_CollectionExtract`, each repair listed;
  - inside Nigeria's bounding box (0.05° slack);
  - each place matched at most once.
- **Reports:** unmatched features and places left without a boundary are listed but are not errors. Any error writes nothing.
- **One transaction:**
  - boundary, and centroid via `ST_PointOnSurface`;
  - a `boundary_sources` row (level, source name, attribution, synthetic flag, feature count, `imported_by` via `--user=<email>`, `imported_at`);
  - state boundaries derived from the union of their LGAs, until a state-level import exists;
  - simplified display geometries rebuilt in `boundary_simplified` (national 0.02°, state 0.005°, LGA 0.001°).
- `--dry-run` validates and reports only.
- **Post-import check:** lists communities, religious institutions, formations, organization sites, incidents and persons of interest (last known location) that lie outside their LGA's new boundary.
  - Written to `storage/app/private/reports/boundary-check-<time>.csv` with a summary by table.
  - The file holds table, ULID, LGA name and coordinates only, never names.
  - Nothing is changed and the import does not fail.
- **Maps:**
  - `MapView` now carries the place's simplified `outline` (GeoJSON), and `BuffaloMap` draws it on every LGA-centred map.
  - The latest attribution per level (cached, cleared after each import) is shared as `boundaryAttribution` and shown in each map's attribution.
  - The LGA overview tab shows the boundary and its centroid.
- **`buffalo:generate-demo-boundaries`:**
  - Local only.
  - Writes a grid of 0.15° squares per state around a rough centre.
  - Recorded as `synthetic`, with the attribution "SYNTHETIC demo boundaries (not real LGA shapes)".
  - Then runs the same post-import check.
- **Documentation:** the formats are in `database/data/README.md`.

### 6A.1–6A.4 Flashpoints

**`flashpoints` table**

- Columns: kind, name, generated `search_name` (trigram), threat type, concern level, place (composite FK; community via composite FK), location point (required), radius (100–50,000 m, CHECK), description, recommended action, status, classification, `last_assessed_*`, `review_due_at`, authors, `timestampsTz`, soft deletes.
- Traits: `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`; views above `restricted` are access-logged.
- The point must lie inside the LGA boundary: the existing trigger, plus request validation.
- GIST index on `location`. The threat type is registered with `ReferenceUsage`.

**`flashpoint_assessments`**

- Append-only (the existing `buffalo_append_only_follow_place` trigger; truncate rejected). The place follows the flashpoint through a cascading composite FK.
- `AssessFlashpoint` writes the row and updates the current fields in one transaction:
  - concern, status, recommended action, last assessed at/by;
  - `review_due_at` = today + `buffalo.flashpoints.review_days` (severe 30, high 60, moderate 90, low 180);
  - a named `assessed` event with old and new values.
- Creating a flashpoint records the first assessment. The edit form rejects (`prohibited`) concern, status, assessment and recommended action.

**`flashpoint_incidents` and `flashpoint_security_notes`**

- On the flashpoint's place, with a cascading composite FK.
- Columns: `link_note`, `linked_by`, `linked_at`, `unlinked_by`, soft delete.
- Unique per active pair (partial index), so a pair can be re-linked after unlinking.
- The models' global scope requires both the flashpoint and the linked record to be visible, so lists and counts never include hidden links.
- Linking needs `flashpoints.manage` on the flashpoint and `view` on the record. Drafts and invalid incidents are refused; closed and resolved incidents are allowed. The incident lock trigger does not apply to the link table.
- Link and unlink are named events on the flashpoint (ULIDs only).

**Suggestions**

- Incidents: visible, not draft or invalid, not linked, inside the radius, within the last 3, 6, 12 (default, configurable) or 24 months, optionally limited to the threat type's category.
- Security notes: visible and unresolved, about communities of the flashpoint's LGA or inside the area (in-area first).

**Near-flashpoint alerts** (`FlashpointAlerts`, hooked into `SubmitIncident`)

- **Matching:** `FlashpointProximity` is a sanctioned scope bypass in `App\Support\Jurisdiction`; ids and counts only. It finds active or monitoring flashpoints whose area contains the incident.
- **Recipients:** each (flashpoint, incident) pair is alerted once (`flashpoint_incident_alerts`) to the SD, DSD and LGA chief of the flashpoint's place, filtered to users who can see both.
- **Incident page:** shows linked flashpoints and visible areas that contain the incident ("Near flashpoint"), with a Link button where permitted. Viewers who cannot see a flashpoint get nothing.
- **Blackspot reassessment:**
  - Trigger: at least `escalation_incident_count` (3) linked or nearby incidents, not draft or invalid, that occurred within `escalation_window_days` (30).
  - Checked on submission and when linking.
  - The SD and DSD who can see it are notified, at most once per window (`escalation_notified_at`, claimed atomically).

**Review reminders:** `buffalo:flashpoint-review-reminders` runs daily at 07:10. It notifies the LGA chief, SD and DSD, through `NotifyRole` with a per-recipient visibility filter, once per due date (`review_reminded_for`). Resolved flashpoints are skipped.

**Permissions**

| Permission | Holders |
|---|---|
| `flashpoints.view`, `flashpoints.manage`, `flashpoints.assess` | all five intelligence roles |
| `flashpoints.delete` | DG, DDG, SD |

The matrix, `auth.ts` and both drift tests are updated.

### 6A.5 User interface

- **Navigation:** a "Flashpoints" section with "Flashpoints & blackspots".
- **`/flashpoints`:** filters (name, kind, threat type, concern, status, state, LGA, review overdue), a map of radius circles coloured by concern (dashed when dormant or resolved) with a legend, and a table.
- **Create/edit form:**
  - LGA choice (editable; assessments and links follow);
  - the flashpoint/blackspot definitions as help text;
  - map picker with the LGA outline, and a radius slider plus number input drawn live;
  - the first-assessment fields with the resulting review interval.
- **Detail page tabs:**
  - Overview: facts, current assessment, and a map with the area, LGA outline and linked incidents as markers;
  - Assessments: form and timeline;
  - Incidents: linked list with notes and unlink, plus suggestions with period and category controls and one-click link;
  - Security notes: linked, plus a link list;
  - History.
- **Incident page:** a "Near flashpoint" or "Linked to a flashpoint" badge, a Flashpoints section, and areas drawn on the map.
- **LGA page:** a Flashpoints tab with a map and list.

## Acceptance (browser, dev)

- [x] Demo boundaries generated: 24 LGA squares and 2 derived states.
  - The LGA page shows the square, its centroid and the "SYNTHETIC" attribution.
  - A community at 6.6, 3.5 in Demo LGA LA-1-1 is rejected: "The location is outside the boundary of Demo LGA LA-1-1 LGA."
- [x] "Toll gate kidnapping corridor" was recorded: a Kidnapping blackspot, 5 km radius at the LA-1-1 centroid, high concern, review 30 Nov 2026.
  - Three incidents were linked from the same-category suggestions: DEMO/…/0005996 (closed kidnapping), 0015116 (verified) and 0005247 (resolved).
  - The third link triggered the reassessment notice to the SD and DSD (3 incidents in 30 days).
- [x] BUF/LA/D11/2026/000005 was submitted by chief.lagos inside the radius.
  - The SD, DSD and both LA-1-1 chiefs received "Incident reported inside a flashpoint area".
  - The incident page shows "Near flashpoint", the Flashpoints section and the area on the map.
- [x] A reassessment to severe moved the next review to 31 Oct 2026 (30 days).
- [x] `make lint` and `make ci` pass: 1258 Pest tests (8449 assertions) plus Vitest.

## EXPLAIN: area matching uses the GIST index

A per-row distance (`ST_DWithin(f.location, i.location, f.radius_meters)`) cannot use the index on `flashpoints.location`. Matching therefore filters on the constant maximum radius first, then checks each row's own radius (`Flashpoint::containsSql`).

**Incident to flashpoints.** On dev, with 2,000 synthetic flashpoints inserted inside a rolled-back transaction:

```
Nested Loop  (actual time=53.609..54.315 rows=1)
  ->  Index Scan using incidents_pkey on incidents i
  ->  Index Scan using flashpoints_location_spatialindex on flashpoints f
        Index Cond: (location && _st_expand(i.location, '50000'::double precision))
        Filter: ((deleted_at IS NULL) AND (status = ANY ('{active,monitoring}')) AND st_dwithin(location, i.location, 50000) AND st_dwithin(location, i.location, radius_meters))
        Rows Removed by Filter: 11
Execution Time: 54.474 ms   (first call; mostly PostGIS warm-up)
```

**Flashpoint to incidents** (suggestions; 50,005 incidents on dev):

```
Index Scan using incidents_location_spatialindex on incidents
  Index Cond: (location && _st_expand((InitPlan 1).col1, '5000'::double precision))
  Filter: ((deleted_at IS NULL) AND st_dwithin(location, (InitPlan 1).col1, 5000))
Execution Time: 12.750 ms
```

The test `area matching uses ST_DWithin on the GIST index` asserts that the plan uses `flashpoints_location_spatialindex`.

## Files

### Created

- **Commands:**
  - `app/Console/Commands/{ImportBoundaries, GenerateDemoBoundaries, SendFlashpointReviewReminders}.php`
- **Support:**
  - `app/Support/Geo/{BoundaryImporter, BoundaryAttribution}.php`
  - `app/Support/Flashpoints/{FlashpointAlerts, FlashpointLinks, ReferenceUsageChecks}.php`
  - `app/Support/Jurisdiction/FlashpointProximity.php`
- **Enums:** `app/Enums/{FlashpointKind, ConcernLevel, FlashpointStatus}.php`
- **Models:** `app/Models/{Flashpoint, FlashpointAssessment, FlashpointIncident, FlashpointSecurityNote}.php`
- **Policy and notification:** `app/Policies/FlashpointPolicy.php`, `app/Notifications/FlashpointNotification.php`
- **Actions:** `app/Actions/Flashpoints/{SaveFlashpoint, AssessFlashpoint, LinkToFlashpoint, UnlinkFromFlashpoint}.php`
- **HTTP:**
  - `app/Http/Controllers/Flashpoints/{FlashpointController, FlashpointAssessmentController, FlashpointLinkController}.php`
  - `app/Http/Requests/Flashpoints/{FlashpointRequest, AssessmentRequest, LinkRequest}.php`
  - `app/Http/Presenters/FlashpointPresenter.php`
  - `routes/flashpoints.php`
- **Database:**
  - `database/migrations/2026_10_10_000001_create_boundary_tables.php`, `2026_10_10_000002_create_flashpoint_tables.php`
  - `database/factories/FlashpointFactory.php`
- **Frontend:**
  - `resources/js/pages/flashpoints/{index, form, show}.tsx`
  - `resources/js/components/flashpoints/{flashpoint-areas.ts, concern-badge, link-rows, incident-flashpoints, lga-flashpoints-panel}.tsx`
  - `resources/js/types/flashpoints.ts`
- **Tests:**
  - `tests/Feature/Geo/BoundaryImportTest.php`
  - `tests/Feature/Flashpoints/{FlashpointTest, FlashpointLinksAndAlertsTest}.php`
  - `tests/Support/Flashpoints/FlashpointFixtures.php`
- **Docs:** `docs/prompts/phase-6a.md`

### Changed

- **Back end:**
  - `SubmitIncident` (alerts)
  - `IncidentController` (flashpoints prop)
  - `LgaController` (overview map, Flashpoints tab)
  - `HandleInertiaRequests` (`boundaryAttribution`)
  - `MapView` (outline)
  - `NotifyRole` (optional recipient filter)
  - `Permission`, `RolesAndPermissionsSeeder`, `AppServiceProvider` (reference usage)
  - `config/buffalo.php` (`flashpoints`)
  - `routes/console.php` (schedule), `routes/web.php`
- **Frontend:**
  - `BuffaloMap` (outline, circles, boundary attribution, `invalidateSize` before fitting)
  - `MapPointPicker` and the map wrappers (outline and circles pass-through)
  - every page using `MapView` (outline)
  - `incidents/show.tsx`, `lgas/show.tsx`
  - `navigation.ts`, `auth.ts`, `lib/time.ts` (`formatDate`)
- **Tests:** `navigation.test.ts`, `RolesAndPermissionsTest`, `LgaPagesTest`
- **Docs:** `database/data/README.md`, `README.md`

## Deviations and decisions

1. **Simplified geometries** live in a table, `boundary_simplified` (place type, place id, zoom), rebuilt after each import. The prompt allowed a column or a table.
2. **`--user=<email>`** records `imported_by`, because an Artisan run has no signed-in user.
3. **Demo boundaries** are written through `BoundaryImporter` directly, not through a generated GeoJSON file. They use the same validation, centroids, derivation and report.
4. **Post-import check:** "persons with locations" means persons of interest (`last_known_location`); the `persons` table has no location.
5. **Near-flashpoint notices are once per flashpoint and incident.** An incident inside two areas produces two notices. Resubmitting after a return sends none.
6. **Notification payloads name the kind, threat type and LGA**, never the flashpoint's name, which may be sensitive or later raised in classification.
7. **The reassessment notice goes to the SD and DSD only**, as the prompt says; the near-incident notice also reaches the LGA chief. Incidents are counted by `occurred_at` within the window, linked or inside the area. The count runs whatever the viewer may see and is never displayed.
8. **Review reminders are sent per flashpoint**, not as aggregated counts like the POI reminders, so classified flashpoints reach only users who can see them. They are sent on the due date itself (`review_due_at <= today`).
9. **Editing may move a flashpoint to another LGA** in the editor's jurisdiction. Assessments and links follow through the cascading FKs.
10. **The security notes "link picker"** is a list of linkable notes (the LGA's unresolved notes, in-area first, at most 50) with Link buttons. There is no free-text search picker.
11. **Area matching** uses a constant 50 km prefilter (`Flashpoint::MAX_RADIUS`) so the GIST index applies; see the EXPLAIN above.
12. **Link tables** have `linked_by`/`linked_at`/`unlinked_by` plus soft deletes, instead of `created_by`/`updated_by`/timestamps. Unlinking soft-deletes the row, so it stays on record.

## Conflicts with CLAUDE.md

- **Charts:** section 2 now says "Chart.js (MIT), via `react-chartjs-2` (MIT)". Phase 5D uses Chart.js through our own small wrapper (`components/charts/bar-chart.tsx`) and does not install `react-chartjs-2`. I kept the existing code. Either install `react-chartjs-2` in a later phase, or change CLAUDE.md to "Chart.js via our own wrapper".

## New packages

None.

## Known issues and notes for later steps

- **The boundary report is sensitive.** The CSV holds coordinates of classified records (for example formations). It sits in private storage; treat it as classified material and delete it once the records are corrected.
- **Records already outside their LGA's boundary** will fail the location trigger if their location or LGA is edited, until corrected. The report lists them. On dev, 37,814 synthetic demo incidents fall outside the demo squares; this is expected.
- **Alerts fire on submission only.** An incident whose location is edited into an area after submission is not alerted. A flashpoint created after incidents were submitted relies on suggestions.
- **Dev data:**
  - demo boundaries generated;
  - roles re-seeded with the new permissions;
  - the blackspot "Toll gate kidnapping corridor" (`01m3vvn224epc51afq6gs0ap4y`) with three links;
  - incident BUF/LA/D11/2026/000005.
- **6C:** reuse `boundary_simplified` for choropleths and `Flashpoint::containsSql` / the GIST index for area layers. Map payloads should stay aggregated on the server.
