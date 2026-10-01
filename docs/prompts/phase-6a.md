# BUFFALO — Phase 6A: Boundaries, Flashpoints and Blackspots

Read `CLAUDE.md` before starting (updated after Phase 5: see the decisions log). Read `docs/handover/phase-5-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phases 1–5 are complete, including incidents with full workflow. Phase 6 turns incident history into location intelligence. It is split into:

- **6A (this step):** boundary import, flashpoints and blackspots, their assessments, and links to incidents and security notes.
- **6B:** threat reports (early warnings).
- **6C:** the operational map with server-side aggregated layers, blackspot suggestions, and dashboard maps.

**Definitions** (show these as help text in the UI):

- **Flashpoint:** a place where tension could erupt into violence or a serious security incident (e.g. a disputed boundary, a contested market, a site of recurring communal clashes).
- **Blackspot:** a place where incidents repeatedly happen (e.g. a kidnapping corridor, an accident-prone stretch of road, a piracy-prone creek).

---

## Step 6A.0 — Boundary import

LGA boundaries have been pending since Phase 1A. Map choropleths (6C) and the existing location-in-LGA triggers need them.

1. `php artisan buffalo:import-boundaries {file} --level=lga|state`
   - Reads a GeoJSON `FeatureCollection` supplied by the project owner (for example the Nigeria administrative boundaries published by GRID3 or OCHA on HDX). Never fetches anything from the internet.
   - Matches features to LGAs by a property given with `--code-property` (matching our `lga_code` within `--state-property`), or by a mapping CSV (`feature_id,state_code,lga_code`) given with `--mapping`. Report the expected formats in `database/data/README.md`.
   - Validates everything before writing: geometry type (Polygon or MultiPolygon, converted to MultiPolygon), SRID 4326, `ST_IsValid` (repair with `ST_MakeValid` and report each repair), within Nigeria's bounding box, each LGA matched at most once, unmatched features and LGAs without a boundary listed.
   - Writes in one transaction; computes `centroid` with `ST_PointOnSurface` (always inside the shape, unlike `ST_Centroid`).
   - `--dry-run` validates and reports only.
   - When `--level=state` is not run, state boundaries and centroids are derived from the union of their LGAs.
   - Stores an attribution string (`--attribution="..."`) in a `boundary_sources` table (`level`, `source_name`, `attribution`, `imported_at`, `imported_by`, `feature_count`), shown on maps.
2. **Post-import check:** list existing records (communities, institutions, formations, sites, incidents, persons with locations) whose location falls outside their LGA's new boundary. Do not change them, and do not fail the import. Write the list to a report file and show a summary, so officers can correct them.
3. **Simplified geometries for maps:** a `boundary_simplified` column (or a materialised table) with geometries simplified for display at 3 tolerance levels (national, state, LGA zoom), generated after import.
4. **Development only:** `buffalo:generate-demo-boundaries` creates synthetic, clearly labelled square-ish polygons and centroids for the demo LGAs (refuses outside `local`), so map features can be tested before real data arrives.
5. Tests: matching by property and by mapping CSV; invalid geometry repaired and reported; outside-bbox rejected; dry run writes nothing; centroids inside shapes; derived state boundaries; post-import report lists out-of-boundary records; existing location triggers now reject a point outside the boundary; demo generator refuses outside `local`.

**Commit:** `feat(geo): add boundary import and demo boundaries`

## Step 6A.1 — Flashpoints

`flashpoints` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `kind` | enum `flashpoint`, `blackspot` |
| `name` | required, e.g. "Abuja–Kaduna highway, km 40–55" |
| `search_name` | generated, trigram index |
| `threat_type_id` | FK (register with `ReferenceUsage`) |
| `concern_level` | enum `low`, `moderate`, `high`, `severe` |
| `state_id`, `lga_id` | required; composite FK |
| `community_id` | nullable; composite FK |
| `location` | point, required (a flashpoint is a place) |
| `radius_meters` | integer, default 1000, between 100 and 50,000 (area of concern around the point) |
| `description` | text, required |
| `recommended_action` | text, nullable |
| `status` | enum `active`, `monitoring`, `dormant`, `resolved` |
| `classification` | default `restricted` |
| `last_assessed_at`, `last_assessed_by` | |
| `review_due_at` | date; set from concern level: severe 30 days, high 60, moderate 90, low 180 (`config/buffalo.php`) |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` above `restricted`.
- The point must be within the LGA boundary when one exists (existing trigger pattern). The radius may extend beyond the LGA.
- GIST index on `location`; queries use `ST_DWithin(location, other, radius_meters)` on geography.

## Step 6A.2 — Assessments

`flashpoint_assessments` table, **append-only** (trigger): `ulid`, `flashpoint_id`, `state_id`, `lga_id` (composite FK), `concern_level`, `status`, `assessment` (text, required), `recommended_action` (text, nullable), `assessed_by`, `assessed_at` (`timestampTz`).

- Recording an assessment updates the flashpoint's current `concern_level`, `status`, `recommended_action`, `last_assessed_*` and `review_due_at`, in one transaction, with a named activity event.
- The flashpoint's current fields can only change through an assessment (not by plain edit). Name, description, location, radius and classification are edited normally.
- Creating a flashpoint records the first assessment automatically.
- **Review reminders:** a daily job notifies the LGA chief and SD/DSD of the flashpoint's place when `review_due_at` has passed (once per due date), via `NotifyRole`.

## Step 6A.3 — Links

**`flashpoint_incidents`**: `flashpoint_id`, `incident_id`, `state_id`, `lga_id` (the **flashpoint's** place, composite FK with cascade, per the CLAUDE.md decision), `linked_by`, `linked_at`, `link_note` (nullable). Unique pair.

- Visible only when the viewer can see both the flashpoint and the incident; the flashpoint page lists only visible linked incidents and never reveals a count of hidden ones.
- Linking requires `flashpoints.manage` on the flashpoint and `view` on the incident. Closed and resolved incidents **can** be linked (recording past incidents is the purpose). Invalid incidents cannot.
- Not affected by the incident lock trigger (the link belongs to the flashpoint). Unlinking is allowed (logged) while the flashpoint is not soft-deleted.

**`flashpoint_security_notes`**: links to community security notes (Phase 2C), same visibility rule, same pattern.

**Suggestions** (shown on the flashpoint page to users who can manage it):
- Incidents visible to the user, not invalid, not yet linked, located within `radius_meters`, in the last 12 months (adjustable in the UI), optionally matching the flashpoint's threat type's category. One-click link.

**Incident near a flashpoint:**
- On incident submission (hook into the 5C submission action), find active or monitoring flashpoints whose area contains the incident location (system-context query, IDs only).
- Show "Near flashpoint: [name]" on the incident page only to viewers who can see the flashpoint, with a link button for those who can manage it.
- Notify the SD/DSD of the **flashpoint's** state (and the LGA chief of its LGA) once per incident: "A new incident (reference) was reported within the area of a flashpoint in your jurisdiction." Only users who can see both receive it.
- If a blackspot accumulates `config('buffalo.flashpoints.escalation_incident_count')` (default 3) linked or nearby incidents within 30 days, notify the SD/DSD suggesting a reassessment (once per 30 days per flashpoint).

## Step 6A.4 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `flashpoints.view`, `flashpoints.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `flashpoints.assess` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `flashpoints.delete` | ✓ | ✓ | ✓ | | |

Super admin: none. Boundary import is an Artisan command (operators only), not a UI permission. Update the matrix, drift tests and `auth.ts`.

## Step 6A.5 — UI

Navigation: new section **"Flashpoints"** with "Flashpoints & blackspots" (`flashpoints.view`).

- **`/flashpoints`**: list with filters (kind, threat type, concern level, status, state, LGA, review overdue) and a map showing each as a point with its radius circle, coloured by concern level.
- **Create/edit:** map picker for the point, a radius slider drawn live on the map, threat type, first assessment fields.
- **`/flashpoints/{ulid}`**: overview with map (radius, linked incidents as markers), current assessment, **Assessments** timeline, **Incidents** (linked list plus suggestions with one-click link), **Security notes** (linked, plus link picker), History.
- **Incident page:** a "Flashpoints" section listing linked flashpoints and "near flashpoint" notices, with link actions where permitted.
- **LGA page:** a "Flashpoints" tab.

Run `make wayfinder` after route changes.

## Step 6A.6 — Tests

- Boundary import as listed in 6A.0.
- Scope and classification matrix for flashpoints, assessments and both link tables.
- Assessments append-only; current fields change only through an assessment; review dates per concern level; reminders once per due date.
- Link visibility requires both sides; no hidden counts; closed incidents linkable, invalid not; links unaffected by the incident lock.
- Suggestions: radius, time window, visibility, already-linked exclusion.
- Near-flashpoint detection on submission; notifications only to users who can see both; reassessment suggestion threshold and once-per-30-days rule.
- `ST_DWithin` queries use the GIST index (`EXPLAIN` in the handover).

## Acceptance checklist

- [ ] Generate demo boundaries; LGA pages show boundaries and centroids; a community placed outside its LGA is now rejected.
- [ ] Create a kidnapping blackspot on a demo road with a 5 km radius; link three past incidents from the suggestions, including a closed one.
- [ ] Submit a new incident inside the radius; the State Director is notified and the incident page shows "Near flashpoint".
- [ ] Record a reassessment raising concern to severe; the review date moves to 30 days.
- [ ] `make test` and `make lint` pass.

## Out of scope

Threat reports (6B); operational map layers, density, choropleths, blackspot suggestions from clustering (6C).

## Handover

Write `docs/handover/phase-6a.md` per CLAUDE.md section 9 and print it.
