# Phase 7C handover — Analytics

Date: 2026-10-02

## What was built

### 7C.1 Analytics service and filters (`app/Services/Analytics`)

**`AnalyticsFilters`**
- **Period presets:** 7, 30 or 90 days, this or last month, this or last quarter, this year, or custom (at most about 3 years). Periods are whole days in Africa/Lagos, start inclusive and end exclusive.
- **Comparison:**
  - none;
  - previous period: the previous calendar unit for month, quarter and year presets (a partial "this month" or "this quarter" compares with the same days of the previous one), or the same number of days just before for rolling and custom periods;
  - the same period last year.
- **Granularity:** day for up to 31 days, week for up to 183, month for up to 731, otherwise quarter. It can be overridden, but is coarsened beyond 400 buckets. Buckets use `date_trunc` in the session time zone (Africa/Lagos).
- **Geography:** zone (national users), state or LGA, within the viewer's reach (`JurisdictionOptions`). The breakdown is one level below the selection: national or a zone by state; a state, a state-level viewer or an LGA by LGA.
- **Incident filters:** types, category and severities, validated by `IncidentFilters`.
- **Incident query:** `IncidentFilters` with `MapFilters::COUNTED`, no drafts or invalid reports (statistics rule), through the viewer's scopes.
- **URL and cache key:** `values()` is the canonical URL query. The cache key adds the resolved dates, so rolling periods follow the calendar.

**One class per area**, each returning `ChartData` arrays (kind, labels, series, the aggregate table's columns and rows, a note, an optional link):
- `IncidentAnalytics`
- `CasualtyAnalytics`
- `ResponseAnalytics`
- `ThreatAnalytics`
- `CoverageAnalytics`
- `AccessAnalytics`

`Analytics` lists the tabs the viewer may open (`analytics.view` plus each module's permission). It caches the incident-based areas (incidents, casualties, response) with `LayerCache`: keyed by scope signature, filters and the incident data version, never shared across signatures.

**Performance and the rollup decision:**
- Direct queries on the existing indexes are enough, so **no rollup table** was built.
- The one slow query was "incidents at flashpoints versus elsewhere": 1.3 s as the DG over a year, using per-row `EXISTS`. It was rewritten to look outwards from each (few) flashpoint on the incident location GIST index, plus the linked IDs, intersected with the viewer's scoped incidents. It now takes 0.29 s.

### 7C.2 Areas (tabs on `/analytics`)

**Incidents**
- Trend (line) with the comparison overlaid, dashed.
- By category and by type (bar), with change and change %.
- By geography (sortable table): every place one level below, zeros included, with counts, comparison, change, population, and the rate per 100,000 **a year** (annualised, so periods compare). It links to the choropleth on the operational map with the same filters.
- Severity over time (stacked).
- Day × hour table, heat-coloured, for exact times only; the number excluded is shown.

**Casualties**
- People affected by outcome over time.
- By group.
- Security personnel by agency.
- Abducted versus rescued for kidnapping types (`buffalo.analytics.kidnapping_type_codes`, default `kidnapping`).
- Top types by people killed and by people abducted.

Casualty rows are counted only for the viewer's counted incidents (the scoped incident query as a subquery).

**Response**
- Reporting delay, verification, resolution and closure: count, median and 90th percentile in hours (`percentile_cont`). Date-only incidents are excluded from reporting delay, and the number is shown.
- Verification by severity against `buffalo.incidents.verify_within_minutes`: count, median, 90th percentile, number and % within the deadline.
- Performance by state or LGA (sortable): incidents, verified, % within deadline, median verification, median reporting delay.
- Escalation: escalated, acknowledged, not yet acknowledged, median and 90th percentile time to acknowledgement.

**Threats and flashpoints** (the viewer's own records; see deviations)
- Threat reports created in the period, by type, urgency and credibility; status outcomes.
- **Materialisation rate by credibility:** materialised ÷ reports that reached a final status (materialised, mitigated, expired, withdrawn). Active reports are shown separately and not counted.
- Flashpoints by concern level over time: each flashpoint at the concern level of its latest assessment by the end of each bucket (a LATERAL query over the assessments).
- Counted incidents inside or linked to a flashpoint, versus elsewhere.
- Blackspot suggestions generated, accepted, dismissed.
- Disseminations of the viewer's records: sent, acknowledged, revoked, median and 90th percentile time to acknowledge.

**Coverage** (expected period type `buffalo.coverage.expected_period`)
- Compliance per period: LGAs submitted ÷ active LGAs in reach.
- Compliance by state or LGA.
- Rating distribution per period.
- LGAs with 3 or more consecutive submitted reports rated inadequate or critical.
- Personnel, vehicles and daily or continuous patrols from deployments.

**Cross-jurisdiction** (national level and `access_requests.oversee`)
- Requests, approved, rejected, approval rate, escalated, time to decision, grants active now.
- Requests by requesting × owning state.
- Hits shown and suppressed by the searcher's state.
- Officers reaching the soft or hard daily hit limits (days at each, most in a day).

DDGs with zones see only rows whose requesting or owning state is in their zones.

### 7C.3 Dashboards

**Period selector:** 7, 30 (the default, as before) or 90 days (`?days=`).
- It applies to the incident figures, whose keys are renamed without "30": `byType`, `byLga`, `byState`, `byCategory`, `casualtiesByOutcome`, `casualtiesByGroup`, `securityCasualtiesByAgency`.
- It applies to the dashboard map as well.
- Panel titles follow the period.

**KPI tiles** (`DashboardKpis`, built on `AnalyticsFilters`):
- incidents, people killed and people abducted, each with change % against the previous period of the same length;
- verified within deadline (%);
- active immediate threat reports (now).

Each tile links to the matching analytics tab with exactly the same filters.

**Links:** every period-based incident and casualty widget links to analytics ("Analyse"), and the selector has "Open analytics".

### 7C.4 Sharing and export

- All filters and the tab are in the URL.
- **Download PNG:** the browser asks the server to log the export (`POST /analytics/exports/png`, which returns the markings). It then draws the chart canvas onto a larger canvas with the classification above and below, the title and period, and "Generated for [name] at [time]".
- **Download CSV** (`GET /analytics/{area}/{chart}/csv`): rebuilt on the server through the viewer's scopes from the chart's aggregate table.
  - It has the classification marking first and last, "Generated for …", and the period and comparison.
  - Text cells are neutralised against formula injection.
- **Logging:** each export creates an `analytics_exports` row (append-only: user, area, chart, format, filters; no figures). That row is the subject of the `record_access_logs` entry (action `export`, context `analytics`).
- **Classification marking:** the highest level the figures may include, which is the viewer's clearance ("SECRET — aggregate figures" for a State Director, "CONFIDENTIAL — …" for a Deputy).

### 7C.5 Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `analytics.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `analytics.export` | ✓ | ✓ | ✓ | ✓ | ✓ |

- Each tab also needs its module's view permission. Cross-jurisdiction needs national level and `access_requests.oversee`. Super admin gets 403.
- Gates: `viewAnalytics`, `exportAnalytics`.
- Updated together: the matrix (`'analytics'`), `auth.ts`, `RolesAndPermissionsTest` and `navigation.test.ts`.
- Navigation: "Analytics" under Overview.

## Acceptance checklist (browser, dev)

- [x] **DG compares kidnappings by state.** The DG opened Analytics with Period "This quarter", Compare "Previous period", Type "Kidnapping". The header reads "1 Oct 2026 – 2 Oct 2026 compared with 1 Jul 2026 – 2 Jul 2026", and the trend is overlaid. "By state" shows:

  | State | Kidnappings | Comparison | Change | Population | Per 100,000 a year |
  |---|---|---|---|---|---|
  | Lagos | 27 | 9 | +18 (+200%) | 2,569,763 | 191.88 |
  | Kano | 14 | 3 | +11 (+366.7%) | 3,558,018 | 71.86 |

  All other states show 0, with a link to the choropleth on the map.
- [x] **SD verification by LGA.** As the Lagos SD, the Response tab (last 90 days) shows verification against the deadlines by severity:
  - Critical (1 h): 398 verified, 10 within;
  - High (6 h): 1,146 verified, 131 within;
  - Medium (24 h): 1,967 verified, 906 within;
  - Low (48 h): 1,466 verified, 1,408 within.

  "Performance by LGA" shows, for each of the 12 Lagos LGAs, the share verified within the deadline (for example LA-1-1 47.5%) and the median verification and reporting delay.
- [x] **Materialisation rate.** The Threats & flashpoints tab shows "Materialisation rate by credibility" with its table (final, materialised, %, still active).
- [x] **PNG markings and the access log.** "PNG" on "By type" as the DG produced `buffalo-analytics-incidents-by_type.png`. To avoid writing a file to disk during acceptance, the download was intercepted in the page and the image displayed.
  - The image shows "SECRET — aggregate figures" above and below, the title, the period, and "Generated for Dev Director General at 2 Oct 2026 08:50 WAT".
  - `record_access_logs` has `export | analytics | dg@buffalo.local` with the `analytics_exports` row (`incidents / by_type / png`).
- [x] **Under one second at 50,000 incidents.** Timings are below; no rollups were needed.
- [x] **Checks:** `make test`, `make lint` and `make ci` pass: 1357 Pest tests (9847 assertions) plus Vitest. After the population fix (deviation 12), `make lint` and the map and analytics suites (37 tests) were re-run and pass.

## Performance (dev: 50,047 incidents)

**HTTP response times for a whole tab** (curl, including framework and session), uncached after a cache version bump:

| Tab | DG this year vs previous | DG 90 days vs last year | DG last quarter, kidnapping | SD Lagos this year |
|---|---|---|---|---|
| Incidents | **0.68 s** (cached 0.21) | 0.27 s | 0.20 s | 0.26 s |
| Casualties | 0.34 s | 0.35 s | 0.23 s | 0.26 s |
| Response | 0.62 s (cached 0.13) | 0.30 s | 0.19 s | 0.26 s |
| Threats & flashpoints | 0.34 s | 0.21 s | 0.22 s | 0.21 s |
| Coverage | 0.15 s | 0.13 s | 0.18 s | 0.14 s |
| Cross-jurisdiction | 0.15 s | 0.13 s | 0.13 s | — |

**Service only, uncached** (DG, this year with comparison):

| Area | Time |
|---|---|
| incidents | 424 ms |
| casualties | 260 ms |
| response | 719 ms (includes the first query's warm-up) |
| threats | 1,978 ms before the rewrite, about 350 ms after (the flashpoint proximity chart: 1,318 ms before, 285 ms after) |
| coverage | 20 ms |
| access | 13 ms |

## Files

### Created

- **Analytics service:** `app/Services/Analytics/{AnalyticsFilters, ChartData, AnalyticsArea, Analytics, IncidentAnalytics, CasualtyAnalytics, ResponseAnalytics, ThreatAnalytics, CoverageAnalytics, AccessAnalytics, ExportMarking}.php`
- **Dashboard KPIs:** `app/Support/Dashboard/DashboardKpis.php`
- **HTTP:** `app/Http/Controllers/Analytics/AnalyticsController.php`, `routes/analytics.php`
- **Export record:**
  - `app/Models/AnalyticsExport.php`
  - `database/migrations/2026_10_15_000001_create_analytics_exports_table.php`
- **Frontend:**
  - `resources/js/pages/analytics/index.tsx`
  - `resources/js/components/analytics/{chart-card.tsx, export-png.ts}`
  - `resources/js/components/charts/analytics-chart.tsx`
  - `resources/js/components/dashboard/kpi-tiles.tsx`
  - `resources/js/types/analytics.ts`
- **Tests:** `tests/Feature/Analytics/AnalyticsTest.php` (11 tests)
- **Docs:** `docs/prompts/phase-7c.md`

### Changed

- **Back end:**
  - `IncidentFigures` (period parameter, keys without "30", `days`)
  - the three dashboard builders and `DashboardController` (`?days=`, `period`, `kpis`)
  - `IncidentLayers` (population expression, see deviation 12)
  - `AppServiceProvider` (gates)
  - `Permission`, `RolesAndPermissionsSeeder`
  - `config/buffalo.php` (`analytics`)
  - `routes/web.php`
- **Frontend:**
  - `incident-figures.tsx` (period titles, "Analyse" links)
  - `dashboard-map.tsx` (`days`)
  - the dashboard pages (period selector, KPI tiles)
  - `navigation.ts`, `auth.ts`, `types/dashboard.ts`
- **Tests:** `RolesAndPermissionsTest`, `navigation.test.ts`, `IncidentViewsTest` (renamed keys)
- **Docs:** `README.md`

## Deviations and decisions

1. **No rollup table.** Every endpoint is under one second uncached at 50,000 incidents once the flashpoint proximity query was rewritten (prompt 7C.1: rollups only if needed).
2. **"Previous period" for partial calendar periods** compares like with like. "This quarter" on 2 October compares 1–2 October with 1–2 July, not with the whole of the last quarter. Choose "Last quarter" for complete quarters.
3. **Rates are per 100,000 people per year.** Population comes from `lgas.population_estimate`; state totals are the sum of their LGAs. A place without an estimate shows no rate.
4. **Threats and flashpoints analytics use the viewer's own records** (`ownJurisdiction`). Records disseminated to them (7A) belong to the owning area's figures and would otherwise be counted twice across states.
5. **Blackspot "generated"** counts the suggestions that still exist. Open suggestions are replaced on each nightly run (6C), so suggestions superseded before a decision are not kept and not counted.
6. **Coverage compliance** expects every LGA active now and in reach to submit one report of the expected period type per period. Ad hoc reports are not counted.
7. **Cross-jurisdiction oversight** is limited to national users with `access_requests.oversee`. For DDGs with zones, rows are those where the requesting or owning state is in their zones. Officers' names appear only in the limits table, for oversight.
8. **Export subject.** `record_access_logs` needs a subject, so each export creates an append-only `analytics_exports` row (the chart and filters, never figures) and logs against it.
9. **Classification marking** on exports is the viewer's clearance: aggregate figures may include records up to that level.
10. **Dashboard widgets:**
    - The period applies to period-based widgets (incident figures, casualties, the map), and each links to analytics.
    - Current-state widgets (awaiting verification, active threats, coverage status, shared records) keep linking to their own lists, because "the last 7 days" does not apply to them.
    - The 30-day keys were renamed (a breaking prop change, updated everywhere).
11. **UI filters:** type and severity are single choices in the filter bar; the URL and the service accept several.
12. **Found and fixed (6C):** `IncidentLayers` read the population as `(SELECT population_estimate FROM lga_profiles …)`. `lga_profiles` has no such column, so PostgreSQL silently resolved it to the outer `lgas.population_estimate`. The result was correct but the code was misleading. Both 6C and the new analytics now read `lgas.population_estimate` directly. The map tests pass unchanged.

## New packages

None. Chart.js line, point and legend elements are registered from the existing `chart.js` dependency.

## Known issues and notes

- **Dev data adjusted for acceptance:**
  - demo LGAs given population estimates (they had none);
  - demo incidents past submission given a random verification time of 0–50 hours (the demo seeder set statuses without `verified_at`); closed demo incidents are locked and were not changed.
- **Annualised rates** for very short periods (for example 2 days) are large by construction.
- **Caching:** the incident-based tabs are cached for `buffalo.map.cache_seconds` (5 minutes) and refreshed on incident submission or status change. Other changes (casualty edits, verification times) appear within the TTL. The threats, coverage and cross-jurisdiction tabs are live.
- **For 7D (reports and exports):** reuse `AnalyticsFilters`, the `ChartData` tables, `ExportMarking` and the `analytics_exports` / `record_access_logs` pattern.
