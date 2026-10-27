# Phase 11F — States Section

Prompt: `docs/prompts/phase-11f.md`. Date: 2026-10-27.

## Summary

States now have pages of their own, working like the LGA pages:

- **The side menu** has a **States & LGAs** section: States, LGAs, Senatorial districts, Communities.
- **`/states`** lists the states within reach, by zone or alphabetically. It opens on the state lens.
- **`/states/{ulid}`** is the state's reference profile, with up to nine tabs.
- **The state dashboard (11A)** and the state page link to each other.

Also added: state profiles with completeness and weekly review reminders, a `states` list exporter, a state profile PDF, states in global search, and state names linked across the app.

RESULTS_PLACEHOLDER

## What was built

### 11F.1 State profiles

- **Table** `state_profiles` (migration `2026_11_21_000001_create_state_profiles`), mirroring `lga_profiles`:
  - `state_id` is unique;
  - the six rich text fields come with their `_text` plain copies from the start (11B);
  - `terrain` is a jsonb array with a CHECK constraint;
  - `last_reviewed_at` and `last_reviewed_by`;
  - authors, `timestampsTz` and soft deletes (`softDeletesTz`).

  The same migration adds `state_profile` to the `report_runs.report_type` CHECK.
- **Model** `StateProfile`:
  - `HasJurisdiction` on `state_id`, with no LGA column (a state-level record);
  - `HasRichText` and `LogsBuffaloActivity`.
- **The LGA chief's rule:** the model implements `LgaVisibilityRule`. An LGA chief sees the profile whose `state_id` is their LGA's state, in SQL (`applyLgaVisibility`) and for one record (`visibleToLga`). Both directions are tested: the chief reads their own state's profile, and other states' profiles are invisible to them.
- **Editing** (`StatePolicy::updateProfile`):
  - needs `state_profiles.update` **and** whole-state jurisdiction (`JurisdictionOptions::coversWholeState`). National users within reach and the state's own SD/DSD qualify; an LGA chief never does, even if the permission were granted;
  - without the permission the answer is 403; outside reach it is 404.
- **Actions:**
  - `UpdateStateProfile` creates the profile on first edit and saves the **capital**. The `State` model is audited, so capital changes are logged.
  - `MarkStateProfileReviewed` records who reviewed it and when.
  - Names, codes and zones are ignored if sent.
- **Completeness** (`StateProfileCompleteness`) counts six items: history, economic activities, terrain, major roads, borders, and at least one of the state's LGA profiles reviewed. `forStates()` computes many states in two queries.
- **Computed facts** (`StateFacts`):
  - area and population are the sums of the active LGAs' `area_km2` and `population_estimate`;
  - each comes with the number of LGAs missing that figure, shown as "(2 LGAs without a figure)";
  - also the number of LGAs and senatorial districts.
- **Review reminder:** the existing weekly `buffalo:profile-review-reminders` (Mondays 07:00) now also sends `StateProfileReviewDue` to the active State Directors and Deputies of every active state whose profile has not been reviewed within `BUFFALO_PROFILE_REVIEW_DAYS`. A missing profile counts as never reviewed. The link opens the Profile tab.

### 11F.2 Pages

- **Navigation:** the section is renamed **States & LGAs**, with States, LGAs, Senatorial districts, Communities (`resources/js/config/navigation.ts`; the navigation test was updated).
- **`/states`** (`StateController::index`, `lens` middleware):
  - **National users** see the states within reach (`JurisdictionOptions::stateQuery`; a zoned DDG sees their zones' states). Filters: search (name or code), zone and state. The lens pre-sets the zone or state filter, `lens=off` clears it, and an explicit filter wins.
  - **Grouping:** by zone or alphabetical, a client-side toggle.
  - **Columns** (`StateListFigures`, shared with the export):
    - state, zone, capital, LGAs;
    - State Director, from open officer postings ("Vacant" if none);
    - completeness and last reviewed;
    - incidents in the last 30 days (the KPI tiles' `AnalyticsFilters` rule, so drafts and invalid reports are excluded);
    - active `immediate` threats (own records only, as in threat analytics).

    The incident and threat columns appear only with `incidents.view` and `threats.view`.
  - **SD, DSD and LGA chief** are redirected to their own state's page.
- **`/states/{ulid}`** (`StateController::show`, `resources/js/pages/states/show.tsx`):
  - `{state}` is bound by ULID. `StatePolicy::view` needs `states.view` and that the state is within reach (`coversState`; an LGA chief: their LGA's state). Any other state is **404**, like an LGA outside the jurisdiction.
  - **Tabs** are computed on the server and hidden, never empty:

    | Tab | Who | Content |
    |---|---|---|
    | Overview | all | Code, zone, capital, number of LGAs and districts. Whole-state viewers also see computed area and population with missing counts, communities, and the current State Director and Deputies. Completeness, last reviewed, **Mark as reviewed** (editors), a map of the state outline with its LGA outlines (`MapView::lgaOutlines`, tooltips with names), **Operational map** (`/map?lat=…&lng=…&z=…`, with `map.view`) and **Open state dashboard**. |
    | Profile | all | Editable with `updateProfile`, read-only otherwise. The borders field is labelled "neighbouring states, international borders, border crossings". |
    | LGAs | all | The state's LGAs within reach: district, headquarters, LGA chief, completeness, incidents in 30 days. An LGA chief sees only their own row. |
    | Senatorial districts | all | Districts with their LGAs and current senators (senators need `political.view`). |
    | Leadership | `political.view` | Current state-level office holders by office (governor, deputy governor, senators, House of Representatives, State Assembly, commissioners, special advisers, party officials); past holders are collapsed. LGA chiefs get public offices only (no party officials). |
    | Security presence | whole-state viewers | Formations grouped by agency with commands first (headquarters, command, area command, division, then the rest), closed ones left out. Active flashpoints and blackspots by concern level (severe first). Active threat reports by urgency (immediate first). Active disseminations received from other states whose record the viewer can see. Each part only with its module's view permission. |
    | Organizations | whole-state viewers | High and critical-infrastructure sites, grouped by LGA. |
    | Incidents | whole-state viewers | The last 90 days by type and by LGA (`IncidentLinks::stateSummary`), with links to `/incidents?state=…` and `?lga=…`. |
    | History | whole-state viewers | Activity of the state profile and of the state record (capital and reference changes). |

  - **Open state dashboard:** national users go to `/dashboard/states/{ulid}` (when `StatePolicy::viewDashboard` allows); the state's own SD/DSD go to `/dashboard`, which is their state dashboard. The state dashboard gained a **State profile** link back (`data-test="state-profile-link"`).
  - Every tab reads through the viewer's own scopes and clearance (tested for formations, flashpoints including a secret one, threats, disseminations, sites, incidents including a secret one, and LGAs).
- **State names link to the state page** for users who may open it: `StateLink` (`components/local-government/state-link.tsx`) and a shared `stateLinks` prop (ULIDs of the openable states). Linked so far:
  - the incident, LGA, threat, flashpoint, formation, organization site, coverage report, criminal group, arrest and person pages;
  - the senatorial district list;
  - the lens filter chip;
  - the national dashboard's state grid. The card's name now opens the state page and its figures the state dashboard; it was a single link, and links cannot nest.

### 11F.3 Exports, printing and search

- **List export:** `StatesExporter` (key `states`, page `states.index`, not sensitive). It is registered in `ListExporters` and shares the page's filters, query and figures. `ListExporterCoverageTest` passes, and an export parity test checks the export against the page.
- **State profile PDF** (`ReportType::StateProfile`, `StateProfileBuilder`, `reports/state-profile.blade.php`):
  - **Contents:** the profile; the computed facts; an LGA summary (name, district, headquarters, completeness); the districts with senators; current **public office holders** (no party officials, no past holders).
  - **Operational sections** (whole-state viewers only, each with its module's permission): a security presence summary as **formation counts per agency** (no names or commanders), active flashpoints and blackspots by concern level, and the 90-day incident summary by type and LGA.
  - **Never included:** persons of interest, criminal group members or other persons.
  - **Same pipeline and markings as the LGA profile PDF:**
    - the file's level is the highest classification it holds (formations, flashpoints, incidents);
    - "Generated for" line, logged on download, quotas, expiry;
    - needs `reports.generate` and `states.view`, and the state must be within reach when requested and when built.
  - **Where it is requested:** **Export PDF** on the state page opens `/reports` pre-filled. The reports page offers "State profile" with a state picker (`options.profileStates`).
- **Global search:** `PlaceProvider` (now "States, LGAs & senatorial districts") also returns states within reach, for users with `states.view`:
  - matched by name (substring or word similarity on `buffalo_search_text`) or exact code;
  - 37 rows, so no index is needed;
  - a state ranks just above an equally scored LGA of the same name;
  - the lens's states rank first, as for the other places.

### 11F.4 Permissions

| Permission | DG | DDG | SD | DSD | LGA | SA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `states.view` | ✓ | ✓ | ✓ | ✓ | ✓ (own state, reference tabs) | |
| `state_profiles.update` | ✓ | ✓ | ✓ | ✓ | | |

The permissions were updated in:

- `App\Enums\Permission`;
- the `states` module of `RolesAndPermissionsSeeder::matrix()`;
- `resources/js/types/auth.ts`;
- `RolesAndPermissionsTest`;
- the navigation test's `ROLE_PERMISSIONS`;
- `JurisdictionMatrixTest`'s `ROUTE_PERMISSIONS` (`states.show`, which is in the generated matrix because `State` is a matrix record);
- `docs/security/route-inventory.md` (378 routes).

The seeder must be re-run on existing installations (`php artisan db:seed --class=RolesAndPermissionsSeeder --force`; idempotent).

### 11F.5 Tests

| File | Covers |
|---|---|
| `tests/Feature/LocalGovernment/StatePagesTest.php` (new, 35 tests) | **Visibility:** the profile matrix for all 15 world users, and the single-record gate in both directions for the chief. **Editing:** SD, DSD and zone DDG may; chief and super admin 403; outside reach 404; name and code ignored; audit. **Review**, **completeness**, computed area and population with missing counts, and **reminders**. **The list:** per role, zoned DDG limits, the filter refused outside reach, redirects, the lens default with `lens=off` and explicit filters, and the figures (State Director, completeness, incidents excluding drafts and old ones, immediate threats). **Tabs:** chief tabs and hidden operational tabs, the chief's LGAs row, districts with senators, leadership (current, past, state-level only, chief without party officials), security presence (scopes, clearance, commands first), disseminations received, organizations (high and critical only), incidents (types, LGAs, clearance, statistics rule), history, and `stateLinks` per role. |
| `tests/Feature/Reports/ReportsTest.php` | State profile PDF: content, SECRET marking for the SD and RESTRICTED for the DSD, "Generated for", public office holders only (no party official, past holder or POI), the chief's reference-only version, refused outside reach and for super admins. The `/reports` type list includes `state_profile`. |
| `tests/Feature/Search/GlobalSearchTest.php` | States are found by name or code within reach, with links to the state page. |
| `tests/Feature/Exports/ListExportsTest.php`, `ListExporterCoverageTest.php` | The `states` exporter: generic markings and row tests, SD versus DG scope, page and export parity, and the registry mapping. |
| `tests/Feature/RichText/RichTextFieldsTest.php` | State profile rich fields sanitised over HTTP, with plain copies. |
| `tests/Feature/LocalGovernment/ProfileReviewReminderTest.php` | Now counts LGA reminders only (state reminders are in `StatePagesTest`). |
| `tests/browser/specs/09-states.spec.ts` (new) | The browser check for the DG, the Lagos SD and a Lagos LGA chief (see Acceptance). |

## Deviations and conflicts with earlier phases

1. **LGA chiefs do not see the governor, commissioners or other state-wide office holders, or the State Director and Deputy.** The prompt shows a chief the Leadership tab with public office holders and the Overview with the SD and Deputy. As built since Phase 3B, though:
   - political offices are visible only when their **person** is visible;
   - a chief sees only persons of their own LGA;
   - `PoliticalOffice`'s LGA rule shows a chief only their LGA's offices and their district's senator;
   - officer postings (like users) are scoped, so a chief cannot see the SD's posting.

   Per the prompt's instruction, I **followed the existing scopes** rather than adding a new scope bypass:
   - a chief's Leadership tab shows only the public offices their scopes reveal (typically their senator, and members tied to their LGA);
   - the Overview leaves out the SD/Deputy facts for chiefs, rather than wrongly saying "Vacant".

   Showing state-wide public office holders to chiefs would need a deliberate, documented exception, for example a names-only bypass in `App\Support\Jurisdiction` like `OfficerNames`. Please decide.
2. **"Figures outside their LGA" for chiefs** was read as the state-wide figures: computed area and population, and the communities count, plus the operational tabs. A chief's Overview keeps the structure: zone, capital, numbers of LGAs and districts, the map, completeness and last review.
3. **Completeness for a chief** counts the "LGA profile reviewed" item through their scope, so it counts only their own LGA's profile and may read lower than the SD's figure. The same holds for the PDF. This mirrors the LGA profile, which counts communities through the scope.
4. **A chief may print the state profile PDF** ("same permissions as the LGA profile PDF"). It holds only the reference sections their page shows: no security presence, flashpoints or incidents.
5. **History** also includes the state record's own activity (capital and admin changes), not only the profile's, because the capital is edited on the profile form.
6. **The state lens** pre-sets the `state` (or `zone`) filter, so with a state lens `/states` opens filtered to that one state. Clearing the chip (`lens=off`) shows all.
7. **The state grid card** was restructured (see 11F.2). The browser check's 11A spec still clicks the card body, which opens the dashboard.
8. **Organizations tab:** the page shows sites only; site photos and logos are not repeated there. Each site links to its own page.

## New packages

None.

## Known issues and notes

- **Boundaries:** the overview map shows LGA outlines only where boundaries have been imported (Phase 6A). The development data has two states with outlines.
- **The `stateLinks` shared prop** costs one small query per page for users with `states.view`.
- **Re-seed permissions** on every environment (see 11F.4) before using the pages.
- Old test volumes from the edge-mode work are still listed in `docs/handover/edge-mode.md`.

## For the next step

- **New state-level reference content** should go on the state page: add the tab to `StateController::TABS` (and to `REFERENCE_TABS` if LGA chiefs may see it) and to `TAB_LABELS` in `states/show.tsx`, and load its data only when the tab is selected.
- **New places where a state name is shown** should use `StateLink`.
- **`StateProfileCompleteness::ITEMS`** is the single definition of state completeness.
