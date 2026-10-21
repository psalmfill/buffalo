# Phase 11E — Printing Flashpoints and Exports for Every List

Prompt: `docs/prompts/phase-11e.md`. This step completes Phase 11 (summary: `docs/handover/phase-11-summary.md`).

## What was built

### 11E.1 Flashpoint and blackspot documents

- **Flashpoint PDF** (report type `flashpoint`, `FlashpointReportBuilder`, `reports/flashpoint.blade.php`), for anyone who can view the flashpoint. It contains:
  - the header facts (kind, threat type, concern, status, classification) and the 7D markings on every page;
  - a **location sketch** (`App\Support\Reports\LocationSketch`): an inline SVG drawn on the server from the LGA's `boundary_simplified` outline (`MapView::outline`), the point and radius circle, and the linked incidents as dots, with a scale bar and a north arrow. It has no map tiles and no URLs of any kind; the coordinates are printed beneath it;
  - the description and recommended action (rich text);
  - the current assessment and the full assessment history;
  - the linked incidents, security notes and threat reports the requester may see (the page's own link helpers);
  - disseminations for the owning jurisdiction only (`DisseminationPresenter::sharing`, null for recipients), as on screen.

  The file's level is the highest classification among everything included.
- **Flashpoint register:** the `flashpoints` list exporter, with the page's filters. One row per flashpoint: name, kind, threat type, concern, status, state, LGA, community, coordinates, radius, last assessed, review due, the linked incidents the requester may see (drafts and invalid excluded) and classification. Description and recommended action are optional plain-text columns. **A blackspot register** is the same export filtered by kind = blackspot (and state).
- **Buttons:** **Print / Download** on each flashpoint page; **Export** on the flashpoint list (PDF table, XLSX, CSV).

### 11E.2 Exports for every list

- **Registry** (`app/Support/Exports/Lists`):
  - `ListExporter` (abstract) defines the page (`page()`, an index route or `route#tab`), the view permission (`canView`), the page's filter rules (`rules`), the scoped query (`query`), the columns (`ListColumn`: key, heading, reader; rich fields through their `_text` copies), the default columns (the page's visible ones), `sensitive()`, `maxRows()`, `classificationColumn()`/`levels()`, eager loads and batch lookups (`with`/`prepare`), and `around()` (trigram thresholds for name searches).
  - `ListExporters` lists the 25 exporters and `EXCLUDED`.
- **The page and its export share one query.** Every index controller (and the LGA tabs, the organization page, the oversight tab, `TabData`, `Leadership`) was refactored to call its exporter's `filters()` and `query()`/`filtered()`. An export is therefore the page's list for the same filters, through the requester's scopes at generation time. The filters are revalidated by the page's own rules inside the requester's context when the file is built.
- **Pipeline:**
  - Report type `list` (`ReportType::ListExport`) with parameters `list`, `filters` and `columns`. `ListExportBuilder` writes XLSX/CSV through `SpreadsheetWriter` (markings first, `SafeCell` against formulas), or a PDF table (`reports/list.blade.php`).
  - Rows are read in the page's order a chunk at a time (`lazy`), and each chunk is prepared once (no queries per row).
  - Caps: 50,000 rows (`buffalo.reports.max_rows`); PDF tables 2,000 (`buffalo.exports.pdf_max_rows`); sensitive lists 1,000.
  - Step-up above 10,000 rows (`confirm_rows`), and always for sensitive lists and the users list.
  - Rows count toward the daily row quota; runs toward the report quota.
- **Endpoints** (`ListExportController`):
  - `POST /exports/{list}/preview` returns the columns, defaults, row count, formats with their limits, step-up, reason length and the sensitive exports left today.
  - `POST /exports` queues the run and returns to `/reports?run=…`.
  - Both are throttled (`reports`) and authorised by `ReportPolicy::exportList`.
- **Export button** (`components/exports/export-list-button.tsx`) on every list page and list tab. It offers a format, the columns (from the allowed set) and the current filters only; there is no "everything" option. The 428 step-up dialog is the existing global one.
- **Lists covered (page → exporter):**

  | Page | Exporter | Page | Exporter |
  |---|---|---|---|
  | `incidents.index` | `incidents` | `lgas.show#religious_institutions` | `religious_institutions` |
  | `arrests.index` | `arrests` | `lgas.show#vulnerable_population` | `vulnerable_population` (aggregates) |
  | `threats.index` | `threats` | `lgas.show#security_notes` | `security_notes` |
  | `flashpoints.index` | `flashpoints` (register) | `lgas.show#leadership` | `political_offices` |
  | `blackspot-suggestions.index` | `blackspot_suggestions` | `lgas.show#organizations` (+ organization page) | `organization_sites` |
  | `coverage-reports.index` | `coverage_reports` | `access-requests.index#oversight` | `access_requests`, `access_grants` |
  | `formations.index` | `formations` | `disseminations.index` | `disseminations` (received/sent) |
  | `organizations.index` | `organizations` | `users.index` | `users` (super admin, step-up) |
  | `communities.index` | `communities` | `persons.index` | `persons` (**sensitive**) |
  | `lgas.index` | `lgas` | `persons-of-interest.index` | `persons_of_interest` (**sensitive**) |
  | `senatorial-districts.index` | `senatorial_districts` | `criminal-groups.show#members` | `criminal_group_members` (**sensitive**) |
  | `criminal-groups.index` | `criminal_groups` | `organization-sites.show#contacts` | `organization_contacts` (**sensitive**) |

  **Exclusions, each with its reason in `ListExporters::EXCLUDED`:** `audit.index` (audit log, own review screens), `login-history.index`, `notifications.index` (the user's inbox), `help.index`, `analytics.index` (charts; exported as analytics reports), `search.index` (each module's list is exportable), `reports.index` and `reports.schedules.index` (the pipeline itself), `reference-data.index` (configuration), `persons.duplicates.index` and `persons.merge-requests.index` (work queues), `uat.feedback.index` (UAT only).
- **Coverage test** (`ListExporterCoverageTest`) enumerates the GET `.index` routes of BUFFALO's controllers. It fails on any page without an exporter or an exclusion, on an exclusion without a reason or a page, on a duplicate key or unknown column, and pins the mapping above and the four sensitive lists.

### 11E.2.5 Sensitive lists

- **Who:** `exports.sensitive` (SD, DG, DDG); DSDs and LGA chiefs get 403.
- **Every export:** step-up, plus a reason of 20–1,000 characters stored on the run (`report_runs.reason`, `is_sensitive`). A CHECK requires the reason on sensitive runs.
- **Limits:** 1,000 rows (`BUFFALO_EXPORT_SENSITIVE_MAX_ROWS`, refused above). 3 a day per user (`BUFFALO_EXPORT_DAILY_SENSITIVE`, a new `sensitive_exports` entry in the 8B usage, counted from `report_runs`).
- **Columns:** as the prompt lists them. Persons: name, other names, aliases, gender, **age band** (`AgeBand`: Under 18, 18–19, 20–29 … 80 and over, from the date of birth or recorded approximate age, never revealing either), occupation, roles, LGA, state, classification, last updated. POIs: those plus risk, status, offence categories, last sighted, review due. Members: group, person, role, membership status, since. Contacts: organization, site, name, position and the management, security and primary flags.
- **Never included:** encrypted fields (NIN, phone, address, date of birth, contact phone and email), photos, or free-text offences and notes.
- **A search by number (NIN or phone) cannot be exported,** so no NIN or phone ever reaches a run's stored parameters.
- **Alert:** `SensitiveExportAlert`, `critical`, sent at once to the DG and the DDGs covering the requester (a DG is not told about their own export). It names the requester, the list, the row count, the reason and the run reference; never record content.
- **`/audit/exports`:** sensitive exports are highlighted (amber row, "Sensitive list" badge, the reason), list exports are named ("List export: Persons · RPT-…"), and a "Sensitive list exports only" filter was added.

### 11E.3 Other printable records

**Print / Download** on each page (`components/reports/print-record-button.tsx`), through the same pipeline. Each record is resolved again through the requester's scopes and logged as `print` (context `report`):

- **organization site** (`OrganizationSiteReportBuilder`): details, security notes, government security (formations the requester can see), contacts by name and position only, and the cover plus up to six photos (11D previews, sent to Gotenberg as files: nothing is fetched);
- **arrest**: details, persons (named only when visible), offences, the incident comparison, the status timeline;
- **threat report**: details, visible linked flashpoints, groups and incidents, the timeline, and disseminations for owners;
- **criminal group**: details, areas, members only as the requester can see them, visible linked incidents;
- **person**: exactly the page's profile, with the encrypted fields only where the page shows them. Under a cross-jurisdiction grant, only that grant's fields. Aliases, offices, POI details and group memberships when the requester may see them. No photos. **Step-up is required** (`RequestReport`), like evidence originals and secret downloads.

### 11E.4 Permissions

| Permission | DG | DDG | SD | DSD | LGA | SA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `exports.lists` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ (users list only) |
| `exports.sensitive` | ✓ | ✓ | ✓ | | | |

- **`reports.export_data` was removed** and folded into `exports.lists`, now that the incident list is in the registry. **LGA chiefs can therefore export the incident list,** which they could not before. The seeder now deletes permissions that are no longer in the enum.
- **Super admins:** they hold `exports.lists`, but `ReportPolicy::exportList` lets them export the users list only, and only they may export it.
- **Updated together:** the matrix, `RolesAndPermissionsTest`, `resources/js/types/auth.ts`, the navigation test and the route inventory (374 routes).
- **ReportPolicy:** `generate` covers the record types (each with its module's view permission). `download` checks the list's own rule for list runs. The old `incident_list` type is never generated again; runs made before 11E stay downloadable under the incidents exporter's rule.

### 11E.5 Tests

- `tests/Feature/Exports/ListExporterCoverageTest.php` (4): coverage, exclusions, sanity, the mapping and the sensitive set.
- `tests/Feature/Exports/ListExportsTest.php` (~45 with datasets):
  - every exporter, for DG and SD, writes exactly its scoped count, with markings and the default headings;
  - SD exports never include another state's rows (11 lists);
  - page equality for communities, arrests, flashpoints and LGAs;
  - clearance ceiling and file level (a secret arrest);
  - rich text as plain text and formula neutralisation (CSV and XLSX);
  - columns, PDF table markings, caps and step-up;
  - who may export what (deputies, chiefs, oversight, super admin and the users list);
  - the preview, the run queued and downloaded by the requester only and logged, quotas, and a run whose list became forbidden failing;
  - no encrypted column on any exporter.
- `tests/Feature/Exports/SensitiveExportsTest.php` (14):
  - roles for all four lists; step-up and reason (including the 428 through the page);
  - the row cap and daily limit;
  - **no planted NIN, phone, address or birth date in any column or in the raw bytes** (CSV and XLSX), and the other lists' columns;
  - age bands; DG/DDG alerts with no record content;
  - number searches refused; highlighting in the exports review.
- `tests/Feature/Exports/RecordPdfsTest.php` (9):
  - the sketch SVG (no tiles, links or external references);
  - the flashpoint PDF rendered with `Http::preventStrayRequests()`, so the only request is to Gotenberg, and the HTML sent holds the sketch and only the deputy's visible links;
  - the real PDF's markings, assessment history and owner disseminations; another state refused;
  - person PDF step-up and on-screen fields;
  - site PDF contacts without phone or email, and 7 embedded photos;
  - arrest, threat and group PDFs (a member in another state not listed);
  - quota and requester-only download; the print endpoint.
- **Updated:** `ReportsTest` (types per role; incident list via the registry; preview replaces the estimate), `ExportControlsTest` (row quota), `StepUpTest`, `RolesAndPermissionsTest`.

## Files

- **New:**
  - Migration: `database/migrations/2026_11_20_000001_add_list_exports.php`
  - Registry: `app/Support/Exports/Lists/{ListExporter,LgaTabExporter,ListColumn,ListExporters}.php`, 25 exporters in `app/Support/Exports/Lists/Exporters/`
  - Support: `app/Support/Exports/{AgeBand,SensitiveExports}.php`, `app/Support/Reports/LocationSketch.php`, `app/Support/Jurisdiction/OversightReach.php`
  - Builders: `app/Services/Reports/Builders/{ListExportBuilder,FlashpointReportBuilder,OrganizationSiteReportBuilder,ArrestReportBuilder,ThreatReportBuilder,CriminalGroupReportBuilder,PersonReportBuilder}.php`
  - Views: `resources/views/reports/{list,flashpoint,organization-site,arrest,threat,criminal-group,person}.blade.php`
  - HTTP and notification: `app/Http/Controllers/Exports/ListExportController.php`, `routes/exports.php`, `app/Notifications/SensitiveExportAlert.php`
  - Frontend: `resources/js/components/exports/export-list-button.tsx`, `resources/js/components/reports/print-record-button.tsx`
  - Tests: `tests/Feature/Exports/*`, `tests/Support/Exports/ExportFiles.php`
- **Changed:**
  - Enums and policy: `ReportType`, `Permission`, `ReportPolicy`
  - Pipeline: `RequestReport`, `ReportParameters`, `ReportBuilders`, `PdfRenderer` (extra files), `ReportRunPresenter`, `ExportControls`, `ReportController` (page types; estimate removed), `ExportReviewController`
  - The index controllers listed above, plus `TabData`, `Leadership`, `BlackspotSuggestionRows::visible`, `AccessRequestPresenter::place` and `PoiPresenter::offences` (now public), and `PersonController` grant field constants (public)
  - `report.css`, `config/buffalo.php`, the seeder
  - Frontend: all list pages and record pages above, `reports/index.tsx` (incident list estimate removed), `audit/exports.tsx`, `types/{reports,auth}.ts`, `export-report-button.tsx`
  - Docs: route inventory, README

## Deviations and decisions

1. **One report type for every list (`list`), not `flashpoint_register`.** The flashpoint register is the `flashpoints` exporter, so each list has exactly one export path. The record PDFs have their own types as asked (`flashpoint` and the rest).
2. **The incident list export moved into the registry** with the same columns, and still excludes drafts and invalid reports. The page shows the user's own drafts, so the incidents export is "the page's list minus drafts and invalid reports", as in 7D. The `/reports` page no longer offers the incident list or its estimate; the Export button on the incident list replaces it.
3. **Lists shown as tabs are exported too** (LGA tabs, an organization's sites, group members, site contacts, oversight). These lists have no index route, so they are registered with the page and tab they live on. The community page shows the same TabData lists; its tabs have no Export button yet, although the exporters accept a `community` filter.
4. **"Content equals the page"** is guaranteed by sharing the query, not by re-rendering. Three exporters return the page's full result where the page itself shows a capped preview: disseminations (the page shows the latest 200), persons (the page shows 50 and says "truncated"), and the persons "recent" mode (both 20).
5. **Columns left out of bulk files on purpose:**
   - formation commanders' names, ranks and official phones (as in the 7D LGA profile);
   - religious institution leaders and traditional rulers (persons);
   - threat source notes and access request reasons (free text);
   - person names in the arrests list (counted, not named);
   - organization contact lookups (a phone or email search exports nothing from the organizations list).

   Senators and LGA office holders are named (current public office holders).
6. **Person PDFs carry no photo.** The prompt says "as on screen"; leaving the photo out is the conservative reading. Ask if the photo is wanted.
7. **Sensitive exports are refused above 1,000 rows** rather than truncated, so a file never silently drops rows.
8. **The DG is not alerted about the DG's own sensitive export** (the covering DDGs are), matching the 8B volume alerts.
9. **Super admins hold `exports.lists`** so the generic pipeline (My reports, downloads) works for them, but the policy limits them to the users list.
10. **No new packages.** Existing ones only (OpenSpout, Gotenberg, Chart.js, poppler in tests).

## Known issues / notes for the next step

- **Not tried in a browser:** the Export dialog and Print buttons (signing in needs a TOTP account). HTTP, generation and file-content tests cover the server; the frontend passes `tsc`, ESLint and Vitest.
- **PDF tables of many columns** use small type (7.5 pt) on A4 portrait; very wide selections are better as XLSX.
- **The location sketch has no outline** for LGAs without an imported boundary (synthetic or missing). The point, radius and incidents are still drawn, and the note says so.
- **Deploy:** run the migration and `RolesAndPermissionsSeeder` (it adds `exports.*` and removes `reports.export_data`), then `make wayfinder`.

## Gates

- `make test`: 1,914 Pest tests passed (16,556 assertions) and 24 Vitest tests (5 files).
- `make lint`: passed (Pint, Larastan level 7, ESLint, Prettier, `tsc --noEmit`).
- `make ci`: "All CI checks passed." (Composer and npm audits clean.)
- The first full run after the refactors showed only the six tests that needed updating for the new export path (`ReportsTest` ×2, `ExportControlsTest`, `StepUpTest`, `RolesAndPermissionsTest` ×2); no other behaviour changed.
