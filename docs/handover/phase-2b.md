# Handover — Phase 2B: LGA profiles and communities

Date: 2026-09-26

## Summary

BUFFALO has its first intelligence content:

- LGA profiles, with completeness and review reminders;
- communities, with locations, fuzzy search, soft delete with a reason, and history;
- a community CSV import with preview;
- markers and a point picker on the map;
- dashboard content for every intelligence role.

All of it is jurisdiction-scoped with the Phase 1 patterns: `HasJurisdiction`, policies with `JurisdictionGate`, audit logging, and 404 outside the jurisdiction.

`make test` (603 Pest tests with 3445 assertions, plus 13 Vitest tests) and `make lint` pass. All acceptance items passed in the browser.

Commits:

| Step | Commit |
|---|---|
| 2B.5 (map) | `feat(map): add markers and a point picker to BuffaloMap` |
| 2B.1–2B.4, 2B.6, 2B.7 | `feat(local-government): add LGA profiles, communities and community import` |
| — | `docs: add phase 2b handover` (this file, README, prompt) |

Why one commit for most steps: the new permissions, the navigation entries, the drift tests that keep the frontend permission list and the navigation test in step with the seeder, and the pages depend on each other. Splitting them would have left commits that don't pass on their own. The map extension stands alone, so it has its own commit.

## Acceptance checklist

| Item | Result |
|---|---|
| As an LGA chief (demo LGA), complete the profile, add communities with map locations, and see them on the map | ✅ In Chrome as the Lagos LGA chief of "Demo LGA LA-1-1":<br>- **Dashboard:** showed the profile card at 0%, with the missing items and "Complete the profile".<br>- **Profile:** filled headquarters, area, population, terrain (urban, coastal), history, economy, roads and borders, then saved. Completeness became **83%** (only "at least one community" missing).<br>- **First community:** added "Ajegunle Waterside" (other name "Ajegunle", type waterside). Clicking the map placed a marker and filled the coordinates; typing new coordinates moved the marker.<br>- **Second community:** added "Olodi Apapa". Completeness became **100%**.<br>- **Map:** the Communities tab lists both, and the map fits both markers. A marker popup shows the linked name and type.<br>- **Permissions:** the community page has no Delete button for the chief. |
| As the State Director, import a 20-row CSV with 2 near-duplicates and 1 bad row; the preview flags them correctly | ✅ The Kano State Director uploaded a 20-row file for "Demo LGA KN-1-2", where "Sabon Gari" and "Kofar Mata" already existed.<br>- **Preview:** 17 new, 2 possible duplicates ("Sabon Garri" and "Kofar Matta", each 77% similar, with links to the matches) and 1 error ("Bad Row": "The selected type is invalid.").<br>- **Import:** ticked "Kofar Matta" and imported 18. The database has 20 communities in the LGA (17 with locations): no "Bad Row", and the unticked "Sabon Garri" was not imported. |
| A Kano user cannot see Lagos communities in search or by URL | ✅ As the Kano State Director:<br>- `/communities?q=Ajegunle` → "No communities found";<br>- `/communities/{Ajegunle Waterside}` → 404 page;<br>- `/lgas/{Demo LGA LA-1-1}` → 404 page. |
| `make test` and `make lint` pass | ✅ |

## What was built

### 2B.1 — Permissions

| Permission | DG | DDG | State Dir. | Deputy | LGA Chief | Super Admin |
|---|---|---|---|---|---|---|
| `lgas.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `lga_profiles.update` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.manage` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `communities.import` | ✅ | | ✅ | ✅ | | |
| `communities.delete` (added, see deviations) | ✅ | ✅ | ✅ | | | |

- The seeder uses an `INTELLIGENCE_ROLES` constant (every role but the super admin).
- The permissions are in `App\Enums\Permission` and `PERMISSIONS` in `types/auth.ts`.
- Two policies:
  - **`LgaPolicy`:** `viewAny`, `view`, `updateProfile`, `addCommunity`, `importAnyCommunities` and `importCommunities`. Each pairs the permission with `JurisdictionOptions::coversLga()`.
  - **`CommunityPolicy`:** `viewAny`, `view`, `update` and `delete`. Each pairs the permission with `JurisdictionGate::canAccess()`.

### 2B.2 — LGA profiles

- **Table:** migration `2026_09_29_000001_create_lga_profiles_and_communities` creates `lga_profiles` with the prompt's columns:
  - `lga_id` is unique;
  - `state_id` is denormalised;
  - a composite FK `(lga_id, state_id)` → `lgas(id, state_id)` keeps the two consistent;
  - `terrain` is a jsonb array (CHECK constraint);
  - `last_reviewed_at` and `last_reviewed_by`;
  - authors, timestamps and soft deletes.
- **Model:** `LgaProfile` uses `HasJurisdiction` and `LogsBuffaloActivity`. The profile is created on first edit (`UpdateLgaProfile`), which also saves the LGA's `headquarters`, `area_km2` and `population_estimate` (the `Lga` model is audited). Names, codes and parents stay admin/import-only. `MarkLgaProfileReviewed` records who and when.
- **Completeness:** `ProfileCompleteness` gives the percentage of six items: history, economic activities, terrain, major roads, borders and at least one community (whitespace counts as empty). It also lists the missing items. `forLgas()` computes many LGAs in three queries, all through the jurisdiction scope.
- **Review reminder:** `buffalo:profile-review-reminders` is scheduled weekly (Mondays 07:00, `routes/console.php`). It sends `LgaProfileReviewDue` (a `BuffaloNotification`, linking to the profile tab) through `NotifyRole` to the active LGA chiefs of every active LGA whose profile hasn't been reviewed within `BUFFALO_PROFILE_REVIEW_DAYS` (default 180, `config/buffalo.php`). An LGA with no profile counts as never reviewed.

### 2B.3 — Communities

- **Table:** `communities` with the prompt's columns, plus these database guarantees:
  - composite FKs `(lga_id, state_id)` → `lgas` and `(ward_id, lga_id)` → `wards` (a new `UNIQUE (id, lga_id)` on wards), so a ward must belong to the LGA;
  - CHECK constraints on type, the `other_names` array and a non-negative population;
  - the partial unique index `communities_lga_name_unique` on `(lga_id, lower(name)) WHERE deleted_at IS NULL`: case-insensitive, and names can be reused after a soft delete;
  - a trigram GIN index on `name`;
  - a generated column `search_names` = `lower(buffalo_unaccent(name || ' ' || other_names::text))` with a trigram GIN index. `buffalo_unaccent` is an IMMUTABLE wrapper around `unaccent`, which is needed for generated columns and indexes;
  - the trigger `communities_location_in_lga`: when the LGA has a `boundary`, the location must satisfy `ST_Covers`; with no boundary, there is no check.
- **Model:** `Community` uses `HasJurisdiction` and `LogsBuffaloActivity`, and has soft deletes.
  - `pointFrom()` writes locations; the `withCoordinates()` scope reads latitude and longitude.
  - `search()` is the fuzzy search: a substring match or `word_similarity` ≥ 0.4 on `search_names`, best matches first.
  - `location` and `search_names` are excluded from the automatic audit log. Location changes are logged as a named `location_changed` event with old and new coordinates.
- **Actions:** `SaveCommunity` creates or updates a community; its LGA is fixed. `DeleteCommunity` soft-deletes it and logs a `community_deleted` event with the reason.
- **Validation:** `CommunityRules` holds the checks shared by the form and the import: same name, similar name, ward of the LGA, and location (inside Nigeria's box, then inside the LGA boundary when there is one).
- **Not added:** `traditional_ruler_id`; Phase 3 adds it.

### 2B.4 — Community CSV import

- **File format:** `CommunityCsv` defines the template (header only). The header must be exactly the 8 columns, in any order. UTF-8 with an optional BOM, up to 5000 rows, and `other_names` separated by `|`.
- **Classification:** `ClassifyCommunityRows` marks each row as one of:
  - **error**, with reasons: invalid values, an unknown ward code, a location outside Nigeria or the boundary, a name that already exists in the LGA (case-insensitively), or a name repeated earlier in the file;
  - **duplicate:** valid, but trigram similarity ≥ 0.6 to an existing community's name or other names, with the match returned;
  - **new:** otherwise.
- **Flow** (`CommunityImportController`):
  1. **Upload page:** choose an LGA (only LGAs in the jurisdiction) and a file, with a template download.
  2. **Preview:** `POST /communities/import/preview` reads the upload from PHP's temporary file and renders every classified row. The file is never stored.
  3. **Confirm:** `POST /communities/import` re-sends the previewed rows and the ticked duplicate lines. The server classifies them again, so a tampered "new" status has no effect.
  4. **Import:** `ImportCommunities` imports the new rows plus the ticked duplicates in **one transaction**. Up to 200 rows run synchronously, with a summary toast.
  5. **Large files:** more than 200 rows run in `ImportCommunitiesJob`:
     - it runs in the importer's jurisdiction context;
     - its payload is encrypted (`ShouldBeEncrypted`);
     - it makes one attempt (all or nothing);
     - it notifies the importer with `CommunityImportFinished`, on success or failure.
- **LGA check:** the chosen LGA must be in the importer's jurisdiction and pass `importCommunities`; otherwise it's a validation error.

### 2B.5 — Map

- **`BuffaloMap`** (extended, not duplicated) gained:
  - `markers` (id, position, title, plain-text popup, link), with popups built from DOM text nodes so names cannot inject HTML;
  - `fitMarkers`;
  - `picker` (click to set, draggable marker).
- **`MapPointPicker`:** the map in picker mode plus latitude and longitude inputs kept in sync both ways, a Clear button, and a Nigeria-bounds check in the browser. The server re-validates with `CommunityRequest` and `CommunityRules`, and the database trigger checks the boundary.
- **Map centre:** `MapView::for(Lga)` now falls back to the state's centroid, then to the national view.
- **No clustering:** it would need a new package (for example `leaflet.markercluster`, MIT), so it is not included. Plain markers are shown, capped at 1000 per LGA page.

### 2B.6 — UI

- **Navigation:** a "Local Government" section with LGAs (`lgas.view`) and Communities (`communities.view`).
- **`/lgas`** (`LgaController`):
  - lists LGAs in the jurisdiction with name, state, district, headquarters, community count, completeness and last review;
  - filters for state (national users), district and name search; 25 per page;
  - an LGA chief is redirected to their own LGA.
- **`/lgas/{lga}`:**
  - **Overview:** facts, the current chief from open postings (or "Vacant"), completeness with missing items, last review, and "Mark as reviewed".
  - **Profile:** the edit form, or read-only without `lga_profiles.update`.
  - **Communities:** search and type filter, list, map with markers, and Add and Import buttons (by permission).
  - **Tabs** come from `LgaController::TABS` (and `TAB_LABELS` in `lgas/show.tsx`), so 2C adds its tabs there.
- **`{lga}` binding:** every `{lga}` route parameter is bound with `JurisdictionOptions::lgaQuery($user)` (`routes/local-government.php`), so an LGA outside the jurisdiction is **404**, like scoped models.
- **Community pages:**
  - `/communities`: cross-LGA fuzzy search with state, LGA and type filters.
  - `/communities/{community}`: details, a map, and a History tab (the record's activity entries, including named events).
  - Create at `/lgas/{lga}/communities/create`; edit at `/communities/{community}/edit`. Both use the map picker.
  - Delete takes a required reason (`ConfirmActionDialog` gained a `method` prop).
- **Dashboards:**
  - **LGA chief:** a profile card (completeness, missing items, last review, community count, "Complete the profile").
  - **State roles:** the LGA table gained Profile and Last reviewed columns, least complete first.
  - **DG/DDG:** a table of average LGA completeness by state (lowest first), limited to the DDG's zones.
- **`make wayfinder`** was run.

### 2B.7 — Tests

| File | Covers |
|---|---|
| `tests/Feature/LocalGovernment/ScopeMatrixTest.php` | `Community` and `LgaProfile` for every Phase 1B world user (15, including zoned, empty-zone and blocked users), with the LGA-level records (own LGA, same state, same zone, other zone). The super admin sees nothing. |
| `LgaPagesTest.php` | List scoping per role; the chief redirect; 403 for super admin and no role; 404 for every LGA route outside the jurisdiction; tabs; profile save (creation, state, terrain order and validation, basics saved, names and codes ignored, audit); review; permission revoked → 403; the completeness calculation; list statistics. |
| `CommunityTest.php` | Create with location and audit events; case-insensitive uniqueness, and reuse after delete (HTTP and database); edit rules for the name; ward in another LGA (HTTP and database); boundary rejection only when a fixture polygon exists, plus Nigeria bounds and half-coordinates (HTTP and trigger); delete (LGA chief and deputy 403; State Director needs a reason, which is logged); out-of-jurisdiction 404s; detail, map and history; fuzzy search (misspelling, accents, other names, never out of jurisdiction, super admin none). |
| `CommunityImportTest.php` | Template; who may import; preview classification of all nine statuses, with matches and reasons; nothing stored (`Storage::fake`); no ids sent; header errors; confirm imports new rows plus ticked duplicates only, and a tampered status is ignored; transaction rollback on failure; jurisdiction on the chosen LGA; queued path (encrypted, user context, notification on success and failure). |
| `ProfileReviewReminderTest.php` | The right chiefs only; configurable days; the weekly schedule. |
| `tests/Feature/Shell/DashboardTest.php` | New dashboard data for all three dashboard types. |

## Deviations and conflicts

- **`communities.delete` added.** The permission table has no delete entry, but "only State Director and above may delete; LGA chiefs may not". A permission keeps role checks out of policies. It is granted to the DG, DDG and State Director. **The Deputy State Director is excluded**, reading "above" literally; please confirm.
- **`Lga` stays unscoped.** The prompt applies jurisdiction to LGA pages, but LGAs are hierarchy reference data used everywhere (user management, options, imports). Adding `HasJurisdiction` to `Lga` would break Phase 1. Instead, `JurisdictionOptions` gained `lgaQuery()` and `coversLga()`, which implement the same `JurisdictionRules`, and the `{lga}` route binding returns 404 outside the jurisdiction.
- **A community's LGA is fixed once created**, because moving it would change who can see it.
- **Import behaviour:**
  - Exact (case-insensitive) name matches are **errors**, not possible duplicates, because the unique index would reject them.
  - Names repeated within the file are errors.
  - Duplicate similarity uses the best of the name and each other name.
- **Review and import notifications:** the prompt asks for a notification when a queued import finishes; `CommunityImportFinished` is also sent when it fails.
- **The community History tab** is visible to anyone who can view the community (not gated by `audit.view`). It shows only that record's changes.
- **Other additions not in the prompt:**
  - the database trigger for the boundary rule;
  - the generated `search_names` column;
  - the `buffalo_unaccent()` function;
  - `UNIQUE (id, lga_id)` on `wards`.

## New packages

None.

## Known issues and notes

- **No marker clustering** (see 2B.5). LGA pages show at most 1000 markers.
- **Boundaries are still empty**, so the boundary check is dormant until the boundary import. It is tested with a fixture polygon.
- **Search threshold:** fuzzy search uses `word_similarity` ≥ 0.4 without the `<%` operator, so it scans the jurisdiction's communities rather than using the trigram index. This is fine at LGA and state scale; revisit it for national searches with very many communities (for example `SET pg_trgm.word_similarity_threshold` and `<%`).
- **Import payload size:** previewed rows travel through the browser and back (up to 5000 rows of JSON). This is the price of never storing the upload.
- **The location field is a single point.** Community areas (polygons) are not modelled.
- **Dev database changes:**
  - a Lagos LGA chief `chief.lagos@buffalo.local` (password `Chief#Lagos2026x`, 2FA set via Tinker);
  - Demo LGA LA-1-1 has a complete profile and 2 communities;
  - Demo LGA KN-1-2 has 20 communities (2 seeded via Tinker, 18 imported);
  - `make fresh` resets all of this.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next step (2C)

- **LGA tabs:** add them to `LgaController::TABS` and `TAB_LABELS` in `resources/js/pages/lgas/show.tsx`, and load each tab's data only when that tab is selected (see `show()`).
- **New LGA-level intelligence models** (religious institutions, vulnerable population counts, security notes) should follow `Community`:
  - `HasJurisdiction` + `LogsBuffaloActivity`, soft deletes, authors;
  - composite FKs to `lgas(id, state_id)`;
  - scope-matrix tests reusing `LGA_PLACES` from `ScopeMatrixTest`;
  - routes nested under `/lgas/{lga}` (bound within the jurisdiction).
- **Community links:** for records linked to a community, validate that the community belongs to the same LGA (the composite FK pattern) and pick it with `Community::query()->search()`.
- **Completeness:** `ProfileCompleteness::ITEMS` is the single definition, if 2C should count new sections.
