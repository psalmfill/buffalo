# Phase 11A — Person role types, state lens and theme colours

Prompt: `docs/prompts/phase-11a.md`.

## What was built

### 11A.1 Person role types (commit 3e46f91)

- `person_role_types` lookup in the Phase 2A reference pattern plus `is_system`. It is seeded with the five former enum values as system types, codes unchanged: `political_leader`, `traditional_ruler`, `religious_leader`, `community_leader`, `person_of_interest`. Three non-system examples are also seeded: `youth_leader` (Youth leader), `women_leader` (Women leader) and `market_leader` (Market or trade association leader).
- System types: administrators may change the label, description and order. The code and `is_system` are fixed by a trigger (`buffalo_person_role_types_fixed`). A CHECK constraint, a model guard and the reference-data action stop a system type from being deactivated. The UI hides **Deactivate** for system types. Deletes and truncates are refused, as for all reference tables.
- `person_roles.role` (enum string) became `person_role_type_id`, backfilled by code. The new unique key is `(person_id, person_role_type_id)`. `App\Enums\PersonRoleType` is gone; code uses the constants on `App\Models\PersonRoleType` (`POLITICAL_LEADER`, …).
- Converted every use: POI actions, political office actions, community and religious institution requests (`PersonWithRole`), the person form and request, person search and picker filters, `PersonPresenter`, leadership pages, person merge (`mergeRoles`), `ReferenceUsage` registration, factories and seeders.
- A deactivated type stays on persons who hold it and still shows (marked "no longer in use"), but it cannot be newly assigned or switched back on once dropped. The person-of-interest type is never offered in forms.
- The LGA Leadership tab (`leadersByRole`) and the senatorial district page (`prominentByRole`) group community-level leaders by role type, including new types.

### 11A.2 State lens and state grid

- **State grid** (`App\Support\Dashboard\StateGrid`) on the national dashboard. It shows one card per state within reach: incidents in the last 7 and 30 days with change, critical open, escalations unacknowledged, active immediate threats, overdue verifications, killed and abducted in 30 days, and coverage compliance for the last period. Each figure reuses the query the state dashboard runs (AnalyticsFilters for the KPI figures, the IncidentFigures rules, `IncidentFilters::overdue`, `CoverageFigures::national`), grouped by state. Everything goes through the viewer's scopes and follows the statistics rule.
- **Attention score:** a weighted sum, with weights and level thresholds in `config/buffalo.php` → `dashboard.attention_weights` / `attention_levels` (`BUFFALO_ATTENTION_*`). Escalations (5), critical open (4) and immediate threats (4) weigh most. The card border shows the level. A toggle switches between attention order, A–Z and grouping by zone.
- **`/dashboard/states/{state}`** renders `dashboard/state`, the same builder and components as a State Director's dashboard. It runs inside a new `JurisdictionContext::focus($stateIds)`, which adds `state_id IN (…)` to every jurisdiction-scoped query. The focus only narrows: it is applied on top of the user's own scope in every mode, and nested focuses intersect. Actions follow the national user's own permissions. **`/dashboard/zones/{zone}`** renders the national dashboard for a zone's states. `StatePolicy::viewDashboard` and `GeopoliticalZonePolicy::viewDashboard` allow only states and zones within the user's reach, so a zoned DDG gets 403 outside their zones.
- **State lens** (`App\Support\Lens\StateLens`, session key `buffalo.lens`): a header picker for national users only ("Viewing: All of Nigeria / zone / state"). `PUT /lens` checks the same policies; `DELETE /lens` is gated by `useLens`. A lens that is no longer within reach is ignored.
  - Dashboards: `/dashboard` redirects to the lens state or zone; `/dashboard?lens=off` is the national view (used by "All of Nigeria").
  - Lists: the new `lens` middleware (`ApplyStateLens`) covers incidents, the incident map, threats, flashpoints, persons, POIs, coverage reports, formations, organizations and analytics. It redirects a visit that has no place filter (`state`, `zone`, `lga`, `lens`) to the same URL with `state=` or `zone=` set to the lens. Any explicit place filter wins. When a lens user clears every place filter, the frontend sends `lens=off`.
  - A shared `zone` filter (`App\Support\Lens\PlaceFilter`) was added to every list. POIs and organizations also gained a `state` filter; for organizations it means "has a site visible to the user in that state". `IncidentFilters` gained `zone`.
  - The operational map and the incident map open on the lens state, or on a zone's mean centroid at zoom 7 (`MapView::zone`, `MapView::forFilter`).
  - Global search: `SearchQuery::preferring($stateIds)`; every provider puts lens-state results first (an `ORDER BY` prepended by `BaseProvider::preferStates`), then its usual ranking. Nothing is added or removed.
  - A chip under the header shows the lens on every page with **Clear lens**. The filter bars show a "Place: …" chip for a `state`/`zone` filter with one-click removal.

### 11A.3 Theme colours

- **Administration → Theme** (`/system/theme`, `theme.manage`): brand primary and accent, an 8-colour chart palette, and key-figure tile colours (incidents, casualties, threats, verification, coverage), each for light and dark mode. The page has live contrast ratios with a "use the nearest passing colour" button, a live preview of tiles plus a bar and a line chart in both modes, **Discard changes** and **Reset to defaults**.
- **Contrast** (`App\Support\Theme\Contrast`, mirrored in `resources/js/lib/contrast.ts` for live checks): WCAG relative luminance. Each colour is checked against its mode's card background (`#ffffff` light, `#0a0a0a` dark): 4.5:1 for text colours (primary, tiles) and 3:1 for graphics (accent, chart palette). `ThemeRequest` rejects a failing colour with a message like (for `#ffe680`) "Chart colour 4 (light): contrast 1.24:1 against the light card background; at least 3:1 is needed. Nearest passing colour: #b39000."
- **Storage:** the `app_settings` table (new), key `ui_theme`, holds one validated JSON document; unknown keys are dropped. `SaveTheme` and `ResetTheme` actions log the named activity events `theme_updated` and `theme_reset` with `old` and `new`. The document is cached forever and cleared on change. A stored document that no longer validates is ignored in favour of the defaults.
- **Applied** by `Theme::css()` as CSS custom properties in `<style nonce="…" data-theme="buffalo">`, after the Vite CSS in `app.blade.php`. It sets `--primary`, `--primary-foreground`, `--brand-accent`, `--chart-palette-1..8` and `--kpi-*` for `:root:root` and `html.dark`. The values are only validated `#rrggbb` strings, so they cannot inject CSS. `app.css` carries the same defaults (kept in step by a test) and maps `--color-brand-accent` and `--color-kpi-*` for Tailwind.
- **Charts:** a new `useChartPalette()` hook reads the CSS variables and re-reads them when the mode switches. `AnalyticsChart` and `BarChart` use it; `BarChart`'s hard-coded colours became palette positions (`paletteIndex`). KPI tiles get a coloured left border and figure. **PDFs:** `reports/layout.blade.php` takes the light palette from `Theme::chartPalette('light')`.
- **Fixed colours** (`App\Support\Theme\FixedColours`): severity, classification banner and urgency colours are shown read-only with an explanation. A test checks they match the frontend constants, and no theme can carry them.

### 11A.4 Permissions

- `theme.manage`: SA ✓, DG ✓. Updated `Permission`, `RolesAndPermissionsSeeder::matrix()`, `RolesAndPermissionsTest`, `resources/js/types/auth.ts`, the navigation config and test (**Theme** under Administration), and the route inventory (`docs/security/route-inventory.md`, 351 routes). The lens needs no permission; it is gated by national jurisdiction (`useLens`).

## Files

New: `app/Models/PersonRoleType.php`, `database/factories/PersonRoleTypeFactory.php`, `database/migrations/2026_11_15_000001_create_person_role_types.php`, `database/migrations/2026_11_16_000001_create_app_settings_table.php`, `app/Support/Dashboard/StateGrid.php`, `app/Support/Lens/{StateLens,PlaceFilter}.php`, `app/Http/Middleware/ApplyStateLens.php`, `app/Http/Controllers/Dashboard/{PlaceDashboardController,LensController}.php`, `app/Policies/{GeopoliticalZonePolicy,AppSettingPolicy}.php`, `app/Support/Theme/{Theme,Contrast,FixedColours}.php`, `app/Models/AppSetting.php`, `app/Actions/Settings/{SaveTheme,ResetTheme}.php`, `app/Http/Requests/Settings/ThemeRequest.php`, `app/Http/Controllers/System/ThemeController.php`, `resources/js/pages/system/theme.tsx`, `resources/js/components/lens/lens-picker.tsx`, `resources/js/components/dashboard/{state-grid,back-to-national}.tsx`, `resources/js/hooks/{use-lens,use-chart-palette}.ts`, `resources/js/lib/contrast.ts`, `resources/js/types/{lens,theme}.ts`, tests `tests/Feature/Persons/PersonRoleTypesTest.php`, `tests/Feature/Shell/StateLensTest.php`, `tests/Feature/Settings/ThemeTest.php`.

Changed: see the commits (dashboard builders and KPIs, the list controllers above, `IncidentFilters`, `MapView`, search providers and `SearchQuery`, `JurisdictionContext`/`JurisdictionScope`, `HandleInertiaRequests`, routes, `app.css`, chart wrappers, KPI tiles, filter bars, dashboard pages, `config/buffalo.php`, README).

## Deviations and decisions

1. **Theme permission:** the prompt says the page has "permission `system.settings`" and also adds `theme.manage` for SA and DG. The page and its actions require `theme.manage`, so the DG (who lacks `system.settings`) can use it. Super admins hold both.
2. **"Accent"** is a new `--brand-accent` variable used for highlights and borders (the lens chip; available as `border-brand-accent`). shadcn's own `--accent` is the hover background and stays untouched, because a strong brand colour there would break hover states. Primary sets `--primary`, with `--primary-foreground` set to the card background (a primary that passes 4.5:1 against the card also passes with that text on it).
3. **How the lens pre-sets list filters:** a redirect that adds `state=`/`zone=` to the URL, rather than a hidden server default. The filter bar and shared URLs then show the place explicitly, and "an explicit filter or URL parameter always overrides the lens" falls out naturally. `lens=off` marks an explicitly cleared place filter.
4. **Zone filter UI:** the incident filters and persons search have a Zone select. The other lists show a zone or state set by the lens as a removable "Place" chip in the filter bar rather than a new select. Each server-side list accepts `zone=`.
5. **Arrests (11C)** do not exist yet. When 11C adds the arrests list, attach the `lens` middleware and `PlaceFilter`.
6. **Attention levels:** the prompt asks for a border colour "by attention level" without defining levels. They are configurable thresholds (`critical` ≥ 20, `high` ≥ 8, `elevated` ≥ 1).
7. **Search ranking:** lens-first ordering applies within each result section. The quick search's cross-section "top" list keeps its score ranking.
8. **PDFs** use the light-mode palette (printed on white).
9. **Coverage tile colour:** no coverage KPI tile exists today. The colour is configurable and mapped to a `coverage` tile key for when one is added.
10. Hit categories needed no change for role types (they come from the POI record, not roles).

## New packages

None.

## Known issues / notes for the next step

- Widget "Analyse" links (`AnalyticsLink`) on a state view opened without a lens point to national analytics. The period selector's "Open analytics", the KPI tiles and every link under a set lens carry the state.
- Chart axis text in the Theme page's light preview takes the page's text colour when the page itself is dark.
- The acceptance item "37 state cards" depends on the production state list; development seeds whatever `zones_states.csv` provides.
- Run `make wayfinder` after pulling (new routes: `dashboard.states.show`, `dashboard.zones.show`, `lens.*`, `system.theme.*`).
- Deploy notes: run migrations (two new) and the permissions seeder (`theme.manage`).
- Testing note: the suite must run serially (`make test`). An aborted run keeps going in its `docker compose run` container; remove it before rerunning.

## Gates

- `make test`: **passed**, 1,727 Pest tests (13,989 assertions) and 14 Vitest tests.
- `make lint`: **passed** (Pint, Larastan level 7, ESLint, Prettier, `tsc`).
- `make ci`: **not passed. It did not run to completion because outbound network access failed, not because of a code failure.** Four attempts each stopped while downloading build dependencies, before any test ran: Composer (`psr/container`, curl timeout), Docker Hub twice (`docker/dockerfile:1` metadata, deadline exceeded), and the Alpine CDN (`dl-cdn.alpinelinux.org` APKINDEX timeout while rebuilding the patched images). The compose hardening checks and the exceptions check passed on every attempt. **Rerun `make ci` when the network is stable**; it has to pass before 11A counts as finished under CLAUDE.md §8.
