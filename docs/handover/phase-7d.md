# Phase 7D handover — Reports and exports

Date: 2026-10-02

## What was built

### 7D.1 Infrastructure (committed earlier in `340b579`)

- **Gotenberg** `gotenberg/gotenberg:8.37.0-chromium` on a new internal network `reports` (no published port, no egress). The app and Horizon join it.
  - Flags: a Chromium allow list of `file:///tmp/` only and a deny list for everything else; download-from, webhooks, PDF-engine routes and Prometheus disabled; a 60 s API timeout.
  - Tested: an external `<img>` is not fetched; `/forms/chromium/convert/url` returns 403; merge and LibreOffice routes return 404.
- **OpenSpout 5.12** (MIT) for streaming XLSX and CSV.
- **Private `reports` disk** on the `reports_data` volume (`/var/lib/buffalo/reports`, mode 0750, `serve: false`).
- **Report assets** are sent with every conversion and nothing is fetched (`resources/reports/assets`):
  - `report.css`;
  - the Inter font;
  - `chart.umd.min.js` (Chart.js 4.5.1, the same version as the app).
- **`SafeCell`:** text starting with `= + - @`, tab or CR is prefixed with `'`. Numbers stay numbers.
- **`SpreadsheetWriter`:** marking rows, a blank row, then the data. XLSX document properties carry the markings.
- **`poppler-utils`** (`pdftotext`) is installed in the dev and CI images only, for tests.

### 7D.2 Report runs and schedules

**Tables** (`2026_10_16_000001_create_report_tables.php`)
- `report_runs`, as specified. CHECKs on type, format, status and classification. A completed run must have `file_path`, `sha256` and `expires_at`.
- `report_schedules`:
  - CHECKs: type is SITREP, weekly or monthly; `HH:MM` time; `weekday` 1–7 exactly when weekly; `day_of_month` 1–28 exactly when monthly;
  - every change is audited (`LogsBuffaloActivity`).

**`RequestReport` action**
1. Checks the format against the type.
2. Validates the parameters with `ReportParameters`, in the requester's context:
   - places must be within reach;
   - single records must pass `view`;
   - weekly reports need a past end date;
   - monthly reports need this month or earlier.
3. For incident lists, applies the row limit and the confirmation threshold.
4. Creates the run and dispatches `GenerateReport`.

**`GenerateReport` job** (connection `redis-reports`, queue `reports`, 1 try, 300 s)
- Fails a run whose requester is deactivated, deleted or locked.
- Otherwise builds inside `JurisdictionContext::actingAs($requester)` and stores `runs/{ulid}.{ext}` with SHA-256, size, rows or pages, and the classification computed by the builder.
- Sets `expires_at` to completion + 30 days.
- On failure:
  - the user sees a generic message;
  - the log carries only the run ULID, type, exception class and file:line, never parameters or messages.
- Broadcasts `report.updated` on `users.{ulid}` with `{run, status}` only.
- Scheduled runs notify the owner (`ReportReady`).

**Downloads** (`GET /reports/runs/{run}/download`)
- Requester only: `ReportPolicy::download`; the DG gets 403 on another user's run.
- `410` if the run expired or the file is missing.
- The file's SHA-256 is recomputed. A mismatch returns `409` and is logged as critical.
- Each successful download is logged in `record_access_logs` (action `export`, context the report type, subject the run).
- The response carries `Cache-Control: no-store, private` and `nosniff`.

**`buffalo:expire-reports`** (hourly) deletes expired files, marks the runs `expired` and clears `file_path`. The run records stay.

### 7D.3 Report types (`app/Services/Reports/Builders`)

**Markings (`ReportMarkings`, `PdfRenderer`)**
- Chromium's repeating header and footer put the classification banner at the top and bottom of every page, plus:
  - "Generated for [name], [role], [jurisdiction] on [j M Y H:i WAT]";
  - `RPT-<ULID>`;
  - "Page X of Y".
- A fixed, faint diagonal watermark with the requester's name and the reference repeats on each page.
- XLSX and CSV: the same three lines in the first rows, and in the XLSX document properties.
- The level is tracked by `LevelTracker`: the highest classification among the records a builder actually includes, at least `restricted`.

| Type | Builder | Content |
|---|---|---|
| SITREP | `SitrepBuilder` | The last 24 hours, or since `parameters.since` for scheduled runs. New incidents with casualty summaries and totals; escalations and acknowledgements; immediate and priority threats created, updated or disseminated in the window (through the scopes, so shared threats are included); flashpoints assessed high or severe; disseminations received; overdue verifications; coverage reports awaiting review. |
| Weekly / monthly | `SummaryBuilder` | Built on the 7C services and `DashboardKpis::forFilters` (new). Weekly: KPI tiles, trend, by type, top 10 places, notable critical and high incidents, threat outcomes, flashpoint concern changes, coverage compliance and ratings. Monthly: previous month plus the same month last year; by category, type, place (by state for national users) and severity; casualties; response; threat outcomes and materialisation. |
| Incident list | `IncidentListBuilder` | `IncidentFilters` through the scopes, drafts and invalid excluded, `chunkById(1000)`. Columns as specified: casualty counts per outcome, person counts per involvement, agencies, escalation, the reporting officer (`OfficerNames`), and the date of each workflow step. |
| Incident | `IncidentReportBuilder` | The 5D print layout: the same presenters and child queries as the page, evidence titles only, the timeline. Persons appear exactly as `IncidentPresenter::person` shows them to the viewer. Logged as `print` (context `report`). |
| LGA profile | `LgaProfileBuilder` | Facts and profile, communities and wards count, religious institutions by religion (counts), latest LGA-wide vulnerable figures, formations (no commanders), current chairman, vice chairman and councillors, flashpoints, and the 90-day incident summary by type. |
| Coverage report | `CoverageReportBuilder` | Report text, deployments (hidden formations are not named), totals by agency, uncovered community names (no coordinates), workflow history. Logged as `view` (context `report`). |
| Analytics | `AnalyticsReportBuilder` | The chosen 7C area with its filters and every chart and table (the same cached results as the page). |

Every section appears only when the requester holds that module's view permission. Empty sections say "No records in this period".

### 7D.4 Generation UI

- **`/reports`:**
  - shows only the types the user may generate;
  - type-specific parameters: place within reach, week ending, month, LGA, analytics area;
  - **Generate**;
  - "My reports": the last 50 runs, live through Echo `report.updated` (refetches `runs` only), with status, pages or rows, size, expiry, classification and a download button.
- **Export buttons:**
  - incident list (current filters);
  - incident page (not drafts);
  - LGA page;
  - coverage report (not drafts);
  - analytics (tab and filters).

  Each opens `/reports?type=…` pre-filled. The server re-resolves records through the viewer's scopes.
- **Incident list exports:** `POST /reports/estimate` returns the row count.
  - Above 10,000 rows, a checkbox states the count and that the export is logged in the user's name, and Generate stays disabled until it is ticked. The server enforces this too (`confirmed`).
  - Above 50,000 rows, the export is refused.
- **Rate limit:** `reports` (20 requests a minute per user) on generate and estimate.

### 7D.5 Scheduled reports

- **`/reports/schedules`:**
  - the user's own schedules, with pause/resume and remove;
  - a form for type, cadence, weekday or day, time and place;
  - one-click defaults: daily SITREP at 07:00, weekly summary on Monday at 08:00, monthly summary on the 1st at 08:00 (DG, DDG, SD and DSD only).
- **`buffalo:run-report-schedules`** runs every 5 minutes, without overlapping. For each due schedule:
  - an owner who is inactive, deleted, locked or no longer allowed to schedule that type causes the schedule to be **paused**;
  - otherwise a run is requested as the owner, in their context. A SITREP gets `since` = the previous run;
  - then `last_run_at` and `next_run_at` are updated. All times are Africa/Lagos.
- **`ReportReady` notification:** "Your daily situation report is ready", body "Sign in to download it.", link `/reports?run=<ULID>`.
  - Mail is allowed when mail is configured.
  - Nothing is attached; this is tested on `toMail()`.

### 7D.6 Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `reports.generate` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reports.export_data` | ✓ | ✓ | ✓ | ✓ | |
| `reports.schedule` | ✓ | ✓ | ✓ | ✓ | ✓ |

- **`ReportPolicy`:**
  - `generate` needs the type's permission plus its module permission:
    - incidents for SITREP, weekly, monthly, incident and incident list;
    - `lgas.view` for the LGA profile;
    - `coverage.view` for coverage reports;
    - `analytics.view` for analytics reports.
  - Monthly summaries are for DG, DDG, SD and DSD only.
  - `download`, `view` and `manageSchedule` are owner only.
  - `schedule` covers schedulable types only.
- Super admin has none of these.
- Updated together: the matrix (`'reports'`), `auth.ts`, `RolesAndPermissionsTest` and `navigation.test.ts`.
- Navigation: "Reports" under Overview (`reports.generate`).

### 7D.7 Tests

`tests/Feature/Reports/ReportsTest.php`: 20 tests, plus the 3 infrastructure tests. They cover:
- types per role;
- the SITREP equal to each role's scoped incidents (drafts, invalid and above-clearance excluded);
- the run classification;
- the cross-state dissemination appearing in the recipient's SITREP;
- markings and the watermark on every page (`pdftotext`, multi-page);
- empty reports;
- weekly and monthly summaries;
- incident list scoping, markings, no person names or descriptions, and formula neutralisation (XLSX and CSV);
- the row cap, confirmation and estimate;
- the LGA profile naming only current public office holders;
- single-record `view` checks;
- an inactive requester's run failing;
- requester-only downloads, logging and the SHA-256 check;
- expiry;
- broadcast payloads;
- "My reports" isolation;
- schedules: created when due, generated as the owner, `since`, no attachments, and paused for inactive or locked owners;
- schedule ownership and the LGA chief's defaults.

## Acceptance checklist (browser, dev)

- [x] **Lagos threat reaches a Kano LGA chief's SITREP.**
  - As sd.lagos, THR/LA/2026/00002 "Gang massing near Ajegunle jetty" (immediate) was shared with Demo LGA KN-1-1 from the threat page.
  - musa.bello's next SITREP, the scheduled one below, lists it under "Immediate and priority threats, new or changed (1)" and under "Shared with us (1) — Threat report THR/LA/2026/00002, Lagos State, 2 Oct 13:03".
- [x] **Global search** (re-checked on this code):
  - `THR/LA/2026/00002` is detected as a reference and returns that threat;
  - "attendant fled on motorcycles", a phrase from its description, returns incident BUF/LA/D11/2026/000003.
- [x] **DG quarterly analytics:** last quarter (1 Jul – 30 Sep 2026) against the previous one, by state: Kano 9,557 vs 5,389; Lagos 9,554 vs 5,159. The materialisation rate by credibility is listed (Probable: 1 final, 1 materialised, 100%).
- [x] **Weekly summary PDF by a State Director.** As sd.lagos, Reports → Weekly summary → Generate:
  - the run appeared as "Running" and changed to "Completed — 6 pages · 392 KB" live, without a reload; classification Secret.
  - The download was fetched in the page rather than saved to disk: `application/pdf`, 401,331 bytes, `%PDF-`, `Cache-Control: no-store, private`.
  - `pdftotext`, page by page (all 6): SECRET twice, "Generated for Bola Lagos Director, State Director, Lagos State on 2 Oct 2026 12:41 WAT", "Page n of 6", and the name a second time in the rotated watermark.
  - The charts are embedded (rendered canvas image) under "Incidents over time", "By type", "Top LGAs", "Threat report outcomes" and "Coverage ratings".
  - `record_access_logs`: `export | weekly`, user = the requester.
- [x] **20,000-row incident export.** For the test, the latest Lagos incident's title was set to `=HYPERLINK("http://example.invalid","Click me")`, then restored afterwards.
  - As the DG: incident list filtered from 1 Apr 2026 → **Export**. `/reports` opened pre-filled with "21,541 rows" and the confirmation, and Generate stayed disabled until it was ticked.
  - It completed with 21,541 rows (2.4 MB XLSX). Downloaded in the page: the correct MIME type, a `PK` zip.
  - Excel is not available in this environment, so the file was checked structurally:
    - the zip opens; there are zero `<f>` (formula) elements;
    - the title cell is an `inlineStr` with the value `'=HYPERLINK(…)`, so a spreadsheet shows it as text;
    - the document properties carry SECRET and "Generated for Dev Director General…";
    - OpenSpout reads 21,545 rows: 3 marking rows, the header and 21,541 data rows;
    - the 2 persons linked to incidents in the window do not appear anywhere in the sheet.
  - The download is logged (`export | incident_list`).
- [x] **Scheduled daily SITREP at 07:00.**
  - As musa.bello, `/reports/schedules` offered "Daily SITREP at 07:00" and "Weekly summary on Monday at 08:00", with no monthly default for an LGA chief. Adding the first showed "Next run 3 Oct 2026, 07:00".
  - To simulate 07:00 arriving, its `next_run_at` was set to today 07:00. The **scheduler container** ran `buffalo:run-report-schedules` at 13:10:03; the run completed (3 pages, Restricted) and `next_run_at` moved to 3 Oct 07:00.
  - The bell shows "Your daily situation report is ready". Clicking it opens `/reports?run=01m3y8b1h0fvbptvb3bs0848bg`, with the run highlighted, marked "Scheduled" and downloadable.
- [x] **Checks:** `make test`, `make lint` and `make ci` pass: 1380 Pest tests (10077 assertions) plus 13 Vitest.
  - The first `make ci` attempt failed on a network reset during `npm ci`; it was re-run.
  - The second exposed a fragile assertion: for names like "Mr. Armando O'Kon II", `pdftotext` drops a space in the rotated watermark. The watermark check now ignores whitespace and passed 8 repeated runs. The third run passed.

## Performance (dev: 46,537 counted incidents)

Generation times on the dev stack, measured in the job (rendering included):

| Report | Requester | Result | Time |
|---|---|---|---|
| SITREP | Lagos SD | 7 pages | 2.4 s (first, cold Chromium); about 1 s after |
| Weekly summary | Lagos SD | 6 pages | 1.3 s |
| Monthly summary | Lagos SD | 7 pages | 1.4 s |
| Incident list XLSX | Lagos SD | 16,434 rows, 1.9 MB | 3.9 s |
| Incident list CSV | Lagos SD | 16,434 rows, 4.2 MB | 2.4 s |
| Incident list XLSX | DG | 21,541 rows, 2.4 MB | under 8 s (observed in the UI) |
| Incident report | Lagos SD | 1 page | 0.6 s |
| LGA profile | Lagos SD | 2 pages | 0.4 s |
| Coverage report | Lagos SD | 1 page | 0.3 s |
| Analytics report | Lagos SD | 3 pages | 0.8 s |

The incident list streams in chunks of 1,000, with one grouped query per child table per chunk, so memory stays flat. The `reports` supervisor has 512 MB and a 330 s timeout.

## Files

### Created

- **Database:** `database/migrations/2026_10_16_000001_create_report_tables.php`
- **Enums:** `app/Enums/{ReportType, ReportFormat, ReportRunStatus}.php`
- **Models:** `app/Models/{ReportRun, ReportSchedule}.php`
- **Policy:** `app/Policies/ReportPolicy.php`
- **Services:**
  - `app/Services/Reports/{ReportBuilder, ReportBuilders, ReportOutput, ReportMarkings, ReportParameters, LevelTracker, PdfRenderer}.php`
  - `app/Services/Reports/Builders/{Places, SitrepBuilder, SummaryBuilder, IncidentListBuilder, IncidentReportBuilder, LgaProfileBuilder, CoverageReportBuilder, AnalyticsReportBuilder}.php`
- **Infrastructure support (7D.1):** `app/Support/Reports/{GotenbergClient, SafeCell, SpreadsheetWriter}.php`
- **Actions, job and commands:**
  - `app/Actions/Reports/RequestReport.php`
  - `app/Jobs/GenerateReport.php`
  - `app/Console/Commands/{RunReportSchedules, ExpireReports}.php`
- **Events and notifications:** `app/Events/ReportRunUpdated.php`, `app/Notifications/ReportReady.php`
- **HTTP:**
  - `app/Http/Controllers/Reports/{ReportController, ReportScheduleController}.php`
  - `app/Http/Requests/Reports/{GenerateReportRequest, ReportScheduleRequest}.php`
  - `app/Http/Presenters/ReportRunPresenter.php`
  - `routes/reports.php`
- **Views:** `resources/views/reports/{layout, header, footer, sitrep, summary, incident, lga-profile, coverage, analytics}.blade.php`, `resources/views/reports/partials/chart.blade.php`
- **Frontend:**
  - `resources/js/pages/reports/{index, schedules}.tsx`
  - `resources/js/components/reports/{place-fields, export-report-button}.tsx`
  - `resources/js/types/reports.ts`
- **Tests:** `tests/Feature/Reports/ReportsTest.php`
- **Docs:** `docs/prompts/phase-7d.md`

### Changed

- **Back end:**
  - `Permission`, `RolesAndPermissionsSeeder`
  - `AppServiceProvider` (policies, the `reports` rate limiter)
  - `DashboardKpis` (`forFilters`)
  - `CoverageAnalytics::reports` made public
  - `CoverageReportPresenter::timeline` moved from `CoverageReportController`
  - `RecordAccessLogger` (falls back to the `JurisdictionContext` user in queued jobs)
- **Config:**
  - `config/buffalo.php` (`reports.per_minute`, `reports.queue_connection`)
  - `config/queue.php` (`redis-reports`, retry after 360 s)
  - `config/horizon.php` (`supervisor-reports`)
  - `phpunit.xml` (sync reports queue in tests)
- **Routes:** `routes/web.php`, `routes/console.php`
- **Assets:** `resources/reports/assets/report.css` (`.pre`)
- **Lint config:** `eslint.config.js`, `.prettierignore` (ignore the vendored report assets)
- **Frontend:**
  - pages: `incidents/index.tsx`, `incidents/show.tsx`, `lgas/show.tsx`, `coverage-reports/show.tsx`, `analytics/index.tsx` (Export buttons)
  - `config/navigation.ts`, `types/auth.ts`
- **Tests:** `RolesAndPermissionsTest`, `navigation.test.ts`
- **Docs:** `README.md`

## Deviations and decisions

1. **No free-text descriptions in incident list exports.** The prompt allows descriptions "only as their scopes allow". Descriptions, location notes and action text are free text that can name persons, and bulk documents must not carry person names (CLAUDE.md). They are left out entirely; the incident report PDF carries them for a single record.
2. **No commanders' names** in the LGA profile's security presence. The decisions log allows only current public office holders as names in bulk documents.
3. **LGA profile office holders are LGA offices only:** chairman, vice chairman and councillors, all current. State-wide and federal offices, traditional rulers and religious leaders are left out; the latter two are persons and are not political offices.
4. **Reading and the access log during generation.**
   - The incident report logs the incident as `print` and the coverage report logs `view` (context `report`) when generated. Each download logs `export` on the run.
   - Queued jobs have no request user, so `RecordAccessLogger` now falls back to the user whose `JurisdictionContext` the job runs in. Before this, such rows would have had a null user.
5. **Dedicated queue connection.** The default Redis connection's `retry_after` is 90 s, which a long export could exceed. `redis-reports` (360 s) and the `supervisor-reports` Horizon supervisor (2 processes, 330 s, 512 MB) run report jobs. The connection is configurable (`BUFFALO_REPORTS_QUEUE_CONNECTION`); it is `sync` in tests.
6. **Formula neutralisation shows the apostrophe.** The `'` is stored as part of the text value (OWASP's recommendation for CSV and XLSX), so a spreadsheet shows `'=HYPERLINK(…)` as text, not a formula.
7. **Analytics report classification** is the highest level among the records behind the area's figures: counted incidents, own threats and flashpoints, or coverage reports. The cross-jurisdiction area uses the requester's clearance, as the 7C CSV does. The 7C chart exports still mark the viewer's clearance.
8. **Weekly and monthly schedules have no date parameters.** They always cover the last complete week (ending yesterday) or the previous month at run time. A scheduled SITREP stores `since` on the run.
9. **Schedules are paused, not deleted,** when the owner is blocked or loses the permission. Resuming re-checks `schedule`.
10. **Download of another user's run returns 403,** not 404. Run ULIDs never appear outside their owner's pages.

## New packages

None in this commit. 7D.1 (`340b579`) added `openspout/openspout` ^5.12 (MIT) and the Gotenberg image (MIT), both named in the prompt.

## Known issues and notes

- **Excel itself was not opened** (not available here). The XLSX was checked structurally; see the acceptance notes.
- **Watermark in extracted text:** the rotated watermark's letters can interleave with nearby text in `pdftotext -layout` output (for example "MTHR/…"). Tests read the watermark with `-raw` and compare without whitespace, because rotated text can also lose spaces ("Mr.Armando").
- **SITREP "since"** for scheduled runs is the schedule's previous `last_run_at`. If a run fails, the next one still starts from the failed run's time, so that window is not repeated. The user can generate a SITREP by hand.
- **Week ending** in the UI defaults to yesterday computed in the browser's UTC date. Between 00:00 and 01:00 WAT it can be the day before; the server validates the date in Africa/Lagos.
- **Dev data changes:**
  - THR/LA/2026/00002 shared with Demo LGA KN-1-1;
  - musa.bello has a daily SITREP schedule;
  - acceptance report runs exist for sd.lagos, dg and musa.bello;
  - the incident title change was reverted.
- **Phase 8:** see `phase-7-summary.md` → "Open issues for Phase 8".
