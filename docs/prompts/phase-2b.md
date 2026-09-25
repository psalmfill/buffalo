# BUFFALO — Phase 2B: LGA Profiles and Communities

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1-summary.md` and `docs/handover/phase-2a.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 2A delivered reference data and the dynamic fields system. This step builds the first **intelligence** content: LGA profiles and communities, with map support. It is also the first time jurisdiction scoping is applied to real operational data, so follow the `HasJurisdiction`, policy and audit patterns from Phase 1 exactly.

---

## Step 2B.1 — Permissions

| Permission | DG | DDG | State Dir. | Deputy State Dir. | LGA Chief | Super Admin |
|---|---|---|---|---|---|---|
| `lgas.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `lga_profiles.update` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.manage` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.import` | ✅ | | ✅ | ✅ | | |

All actions are additionally limited to the user's jurisdiction. Super admin has none of these.

## Step 2B.2 — LGA profiles

`lga_profiles` table (one per LGA, created on first edit):

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `lga_id` | unique FK |
| `state_id` | denormalised, for scoping |
| `history` | text, nullable |
| `economic_activities` | text, nullable |
| `terrain` | jsonb array from fixed values: `urban`, `rural`, `riverine`, `coastal`, `forest`, `savannah`, `hilly`, `desert_fringe` |
| `major_roads` | text, nullable |
| `waterways` | text, nullable |
| `borders` | text, nullable (neighbouring LGAs, states, countries) |
| `general_notes` | text, nullable |
| `last_reviewed_at`, `last_reviewed_by` | set when an officer confirms the profile is current |
| `created_by`, `updated_by`, timestamps, soft deletes | |

Also add editable basic fields to the LGA itself for users with `lga_profiles.update`: `headquarters`, `area_km2`, `population_estimate`. Names, codes and parent relationships stay admin/import-only.

- `LgaProfile` uses `HasJurisdiction` and `LogsBuffaloActivity`.
- Profile **completeness**: a computed percentage based on which fields are filled (history, economic activities, terrain, roads, borders, plus at least one community). Shown on lists and dashboards.
- **Review reminder:** a scheduled weekly job notifies each LGA chief whose profile has not been reviewed in 180 days (configurable `BUFFALO_PROFILE_REVIEW_DAYS`). Use `BuffaloNotification`.

## Step 2B.3 — Communities

`communities` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id` | required |
| `ward_id` | nullable FK; must belong to the LGA |
| `name` | required; unique per LGA (case-insensitive, ignoring soft-deleted rows) via a partial unique index on `lower(name)` |
| `other_names` | jsonb array of strings (alternative spellings, former names) |
| `type` | enum: `village`, `town`, `settlement`, `camp`, `estate`, `waterside`, `other` |
| `location` | `geography(Point,4326)`, nullable |
| `population_estimate` | integer, nullable |
| `notes` | text, nullable |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- Trigram GIN index on `name` for fuzzy search.
- Do **not** add `traditional_ruler_id` yet; it references `persons` (Phase 3), which will add the column.
- `Community` uses `HasJurisdiction` and `LogsBuffaloActivity`.
- If the LGA has a `boundary`, a community's `location` must fall inside it (PostGIS `ST_Covers`); if no boundary exists yet, skip the check.
- Deleting a community is a soft delete that requires a reason (logged). Only State Director and above may delete; LGA chiefs may not.

## Step 2B.4 — Community CSV import

For bulk onboarding. Permission `communities.import`, and only for LGAs within the user's jurisdiction.

1. Upload page: choose LGA, upload CSV with columns `name,type,ward_code,latitude,longitude,population_estimate,other_names,notes` (`other_names` separated by `|`). Provide a downloadable template with the header only.
2. **Preview before import:** parse and validate every row, then show a table with each row marked `new`, `possible duplicate` (trigram similarity ≥ 0.6 to an existing community in the LGA, showing the match) or `error` (with reason).
3. User confirms; only `new` rows plus any `possible duplicate` rows the user explicitly ticks are imported, in one transaction, as a queued job for large files (> 200 rows).
4. The uploaded file is processed and discarded; it is not stored.
5. Result summary, and a notification when a queued import finishes.

## Step 2B.5 — Map picker

Extend `BuffaloMap` (do not duplicate it):

- `MapPointPicker` mode: click to set a point, drag the marker to adjust, clear button, and latitude/longitude inputs kept in sync. Validates coordinates within Nigeria's bounds client-side (server re-validates).
- `markers` prop to display a set of points with popups (used for communities now, other layers later). Use marker clustering only if it can be done without a new package; otherwise plain markers are fine at this stage and report it.
- If the LGA has a centroid, centre on it; otherwise centre on the state centroid, otherwise national.

## Step 2B.6 — UI

Navigation: new section **"Local Government"** with items "LGAs" (`lgas.view`) and "Communities" (`communities.view`).

**`/lgas`** — LGAs within the user's jurisdiction (an LGA chief is redirected straight to their own LGA). Columns: name, state, senatorial district, headquarters, communities count, profile completeness, last reviewed. Filters: state (national users), district. Search by name.

**`/lgas/{ulid}`** — tabs:
- **Overview:** basic LGA facts, current LGA chief (from officer postings), completeness, last reviewed, "Mark as reviewed" button.
- **Profile:** view and edit the profile.
- **Communities:** list with search and type filter, map of communities with locations, add/edit via drawer or page with the map picker, import button (if permitted).
- Leave room for tabs added in 2C (religious institutions, vulnerable population, security notes) and later phases.

**`/communities`** — cross-LGA community search within jurisdiction: fuzzy name search (matches `name` and `other_names`, using `pg_trgm` and `unaccent`), filters for state, LGA, type. Results link to the community detail.

**`/communities/{ulid}`** — details, location map, audit history tab (activity entries for this record).

Dashboard updates (replace placeholder content from 1E where relevant):
- LGA chief: profile completeness with a link to finish it; communities count.
- State roles: table of the state's LGAs with completeness and last reviewed, sorted by lowest completeness.
- DG/DDG: completeness by state (average), as a simple table.

Run `make wayfinder` after route changes.

## Step 2B.7 — Tests

- Scope matrix for `LgaProfile` and `Community` (reuse the Phase 1B matrix helpers): own LGA, other LGA same state, other state, other zone, for every role including zoned DDG and super admin (sees nothing).
- Out-of-jurisdiction LGA or community URL returns 404.
- LGA chief cannot delete a community; State Director can, with reason logged.
- Unique name per LGA, case-insensitive, allowing reuse after soft delete.
- Location outside the LGA boundary rejected when a boundary exists (use a fixture polygon); accepted when none exists.
- Ward must belong to the LGA.
- CSV import: template headers, preview classification (new / duplicate / error), only confirmed rows imported, transaction rollback on failure, jurisdiction enforced on the chosen LGA, file not persisted.
- Fuzzy search finds misspellings and `other_names` matches, and never returns out-of-jurisdiction rows.
- Review reminder job notifies the right chiefs only.
- Completeness calculation.

## Acceptance checklist

- [ ] As an LGA chief (demo LGA), complete the profile, add communities with map locations, and see them on the map.
- [ ] As the State Director, import a 20-row CSV with 2 near-duplicates and 1 bad row; preview flags them correctly.
- [ ] A Kano user cannot see Lagos communities in search or by URL.
- [ ] `make test` and `make lint` pass.

## Out of scope

Religious institutions, vulnerable population statistics, community security notes, `ClassificationGate` (2C). Traditional rulers and persons (Phase 3). Boundary GeoJSON import (later).

## Handover

Write `docs/handover/phase-2b.md` per CLAUDE.md section 9 and print it.
