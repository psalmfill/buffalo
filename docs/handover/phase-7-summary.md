# Phase 7 summary — Dissemination, search, analytics and reports

Date: 2026-10-02

Phase 7 is complete. Detailed handovers:

| Step | Handover | Scope |
|---|---|---|
| 7A | `phase-7a.md` | Cross-border dissemination of threat reports and flashpoints: read-only, time-limited, revocable access for named states or LGAs; requests, acknowledgement, onward sharing, gap alerts |
| 7B | `phase-7b.md` | Global search across ten modules, through each module's own scopes, with a time budget and hashed search logs |
| 7C | `phase-7c.md` | Analytics (six areas) on shared filters, dashboard periods and KPI tiles, marked and logged chart exports |
| 7D | `phase-7d.md` | Reports and exports: PDFs via Gotenberg, XLSX/CSV via OpenSpout, markings, requester-only downloads, expiry, scheduled reports |

Final checks: `make lint`, `make test` and `make ci` pass: 1380 Pest tests (10077 assertions) plus 13 Vitest.

## Tables added in Phase 7

| Table | Step | Notes |
|---|---|---|
| `record_disseminations` | 7A | Subject (threat report or flashpoint), source and target places (composite FKs), reason ≥ 20 characters, expiry, acknowledgement, revocation, onward parent. One active row per target. Not `HasJurisdiction` (two places); read through `receivedBy()` and the policy. |
| `dissemination_requests` | 7A | An LGA chief's request to their SD; scoped on the record's place; one pending per record and requester. |
| `dissemination_gap_alerts` | 7A | Cross-border areas already reported by the daily check, once each. |
| `search_logs` | 7B | User, SHA-256 of the normalised query (never the text), type, result count. Append-only. |
| `analytics_exports` | 7C | Each chart export (area, chart, format, filters; no figures). Append-only; the subject of its `record_access_logs` row. |
| `report_runs` | 7D | Type, format, parameters, requester, schedule, status, classification, private file path, SHA-256, size, rows or pages, generic error, expiry. |
| `report_schedules` | 7D | The owner's SITREP, weekly or monthly schedule (Africa/Lagos), active flag, last and next run. |

New search columns (7B): `search_vector` on threat reports and flashpoints, and trigram `search_name` on organization sites, LGAs and senatorial districts, plus `upper(code)` indexes.

## Dissemination rules (7A)

- **Only threat reports and flashpoints are disseminated** (the `Disseminable` contract, enforced by an architecture test). Persons stay on the Phase 3 grant mechanism. These are the only two ways records cross jurisdictions.
- **Who:**
  - SD, DSD, DG or DDGs who hold the record in their own jurisdiction and clearance disseminate it to named states or LGAs outside its own view;
  - LGA chiefs request dissemination from their SD;
  - a recipient SD/DSD may forward to LGAs of their state. Onward sharing ends with its parent.
- **What the recipient gets:**
  - read-only `view` through `DisseminationResolver`, inside `CombinedAccessResolver`, which keeps person grants unchanged;
  - every write ability stays on own jurisdiction;
  - no History tab;
  - linked records only if already visible to them;
  - views always logged (context `dissemination`).
- **Classification stays a hard ceiling.** The dialog warns which officers at each target cannot see the record.
- **Expiry:**
  - threats: follows the threat's expiry;
  - flashpoints: 90 days (`buffalo.disseminations.flashpoint_days`), renewable;
  - revocation cascades to onward rows;
  - `dissemination.ended` on `users.{ulid}` closes open pages.
- **Own-only figures:** counts, pickers and analytics use `ownJurisdiction($user)`, so a shared record is never counted or linked as the recipient's own.
- **Gap alert:** a daily check alerts the owning SD/DSD once per record when an active immediate or priority threat, or a high or severe flashpoint, has an area reaching other LGAs or states that no active dissemination covers.

## Search providers (7B)

`GlobalSearch` runs one provider per module, each starting from that module's own scoped Eloquent query and needing its module permission:

| Provider | Matches on |
|---|---|
| Incidents | reference (exact), `search_vector` full text |
| Threat reports | reference (exact), `search_vector` |
| Flashpoints | trigram name, full text |
| Persons | name and aliases (trigram); NIN or phone by blind index |
| Persons of interest | as persons, among visible POIs not deceased or cleared |
| Criminal groups | trigram name and other names |
| Communities | trigram names |
| Organizations | directory and site names (trigram); contact phone by blind index (names the organization only) |
| Agency formations | trigram name |
| LGAs and districts | trigram name or code, within reach |

Other rules:
- **Budget:** each provider runs with a 300 ms `statement_timeout` (`SearchBudget`). A timeout says "more results may exist".
- **Incident ranking** is limited to the 1,000 most recent visible matches; counts stay exact.
- **Only index operators** (`%`, `<%`, `@@`) are used for nationwide fuzzy matching.
- **Never** calls `PersonMatchService` and never writes match events. `search_logs` stores a hash only.
- **Access:** gate `globalSearch` (intelligence roles; super admin gets 403); rate limit 60 per minute.

## Analytics and rollups (7C)

- **`AnalyticsFilters`:**
  - presets or custom dates (whole Africa/Lagos days);
  - comparison with the previous period or the same period last year;
  - granularity (automatic, coarsened beyond 400 buckets);
  - zone, state or LGA within reach;
  - incident type, category and severity.

  Every figure goes through the viewer's scopes and the statistics rule (`MapFilters::COUNTED`, no drafts or invalid reports).
- **Areas:** incidents, casualties, response, threats & flashpoints, coverage, and cross-jurisdiction (national users with `access_requests.oversee`). All return `ChartData` with an aggregate table.
- **Rollups: none.** Direct queries on the existing indexes stay under one second uncached at 50,000 incidents. The one slow query (incidents at flashpoints) was rewritten from 1.3 s to 0.29 s.
- **Caching:** the incident-based areas are cached with `LayerCache` per scope signature, filters and incident data version, for 5 minutes.
- **Dashboards:** a 7/30/90-day period and KPI tiles. 7D added `DashboardKpis::forFilters` for any period.

## Report types and controls (7D)

| Type | Format | Who |
|---|---|---|
| Daily situation report (SITREP) | PDF | all five intelligence roles |
| Weekly summary | PDF | all five |
| Monthly summary | PDF | DG, DDG, SD, DSD |
| Incident list export | XLSX, CSV (≤ 50,000 rows; confirmation above 10,000) | DG, DDG, SD, DSD |
| Incident report | PDF | all five (needs `view` on the incident) |
| LGA profile | PDF | all five (needs `view` on the LGA) |
| Coverage report | PDF | all five (needs `view` on the report) |
| Analytics report | PDF | all five (areas they may open) |

Controls:
- **Built as the requester at generation time** (`JurisdictionContext::actingAs`) on the `reports` queue. A deactivated or locked requester's runs fail. Schedules of blocked owners are paused.
- **Markings on every PDF page:**
  - the classification banner top and bottom (the highest level included);
  - "Generated for [name], [role], [jurisdiction] on [time]";
  - `RPT-<ULID>`;
  - page X of Y;
  - a diagonal watermark with the requester's name.

  XLSX and CSV carry the same in their first rows; XLSX also in the document properties.
- **Data limits:**
  - no person names in bulk files: the incident list gives counts by involvement and no free-text descriptions;
  - the LGA profile names only current LGA office holders;
  - no evidence in any export;
  - formula injection is neutralised in every spreadsheet cell.
- **Requester-only download.** The SHA-256 is verified before each download. Each download is logged in `record_access_logs` (action `export`, context the type).
- **Private disk with expiry:** files are deleted after 30 days and the run record stays.
- **Notifications:** emails never carry files; they say "Your [report] is ready" with a link.
- **Gotenberg:** on an internal network with no egress, allow-listed to local files; unused routes disabled.
- **Live status:** `report.updated` on `users.{ulid}`, carrying the run ULID and status only.

## Permissions added in Phase 7

Key: **DG** — Director General; **DDG** — Deputy Director General; **SD** — State Director; **DSD** — Deputy State Director; **LGA** — LGA Security Chief. Super admin holds none.

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `disseminations.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `disseminations.create` | ✓ | ✓ | ✓ | ✓ | |
| `disseminations.request` | | | | | ✓ |
| `disseminations.revoke` | ✓ | ✓ | ✓ | | |
| `analytics.view`, `analytics.export` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reports.generate` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reports.export_data` | ✓ | ✓ | ✓ | ✓ | |
| `reports.schedule` | ✓ | ✓ | ✓ | ✓ | ✓ |

- **Gates:** `disseminate`, `request`, `decideRequest`, `globalSearch`, `viewAnalytics`, `exportAnalytics`.
- **Policies:** `DisseminationPolicy`, `ReportPolicy` (runs and schedules).
- **Navigation:** "Shared threats & flashpoints" (Threats & flashpoints); "Analytics" and "Reports" (Overview); the search box in the header.

## Notifications and scheduled jobs added

| Job / event | When | Recipients |
|---|---|---|
| Disseminated, acknowledged, reminder, revoked/expired, requested, declined | On the event; reminder after 2 h (immediate) or 24 h | As in `phase-7a.md` (each recipient checked) |
| `buffalo:process-disseminations` | Every 15 minutes | Expiries and reminders |
| `buffalo:check-dissemination-gaps` | Daily 07:20 | SD, DSD of the owning state, once per gap |
| `buffalo:run-report-schedules` | Every 5 minutes | Report runs as owners; `ReportReady` to the owner |
| `buffalo:expire-reports` | Hourly | — (deletes expired files) |

## Performance (dev: about 50,000 incidents)

- **Search** (7B): quick search providers take 1–40 ms each. The incident count over 50,040 matches takes 25–56 ms, all within the 300 ms budget. Index plans were checked.
- **Analytics** (7C): whole tabs over HTTP take 0.13–0.68 s uncached, 0.13–0.21 s cached.
- **Reports** (7D):
  - PDFs: 0.3–1.4 s (2.4 s for the first, cold render);
  - incident list: 16,434 rows XLSX in 3.9 s and CSV in 2.4 s; 21,541 rows XLSX in under 8 s;
  - streaming in chunks of 1,000 keeps memory flat.

## Decisions for the CLAUDE.md log

- **Dissemination:** `CombinedAccessResolver` composes person grants and disseminations. Recipients are SD/DSD of the target state and chiefs of target LGAs, never the owning state's own officers. Recipients do not see History.
- **Search:**
  - one provider per module on its own scoped query;
  - a 300 ms budget per provider;
  - incident ranking limited to the 1,000 most recent visible matches;
  - logs store a query hash only.
- **Analytics:**
  - no rollup tables;
  - "previous period" for partial calendar periods compares the same days;
  - rates are per 100,000 people a year from `lgas.population_estimate`;
  - threat and flashpoint analytics count own records only.
- **Reports:**
  - Gotenberg 8 (Chromium only) on an internal network, rendering local files only;
  - a dedicated `redis-reports` queue connection and Horizon supervisor;
  - incident list exports carry no free-text descriptions;
  - the LGA profile names only current LGA office holders, and no commanders;
  - queued jobs log record access as the user whose context they run in.

## Open issues for Phase 8 (security hardening, backup and recovery)

- **Backups:**
  - The `reports_data` volume holds classified files. Decide whether backups include it (files expire after 30 days and can be regenerated), and encrypt it if so.
  - Back up `report_runs`, `report_schedules`, `search_logs`, `analytics_exports` and the dissemination tables with the database.
  - Restore drills must cover the Gotenberg service and the `reports` network.
- **Gotenberg hardening:** pin the image by digest (as PostGIS is); consider a read-only root filesystem, dropped capabilities and memory limits; add a health check to monitoring. The PDF engines route and LibreOffice are already off.
- **Report files at rest:**
  - Files sit unencrypted on a private volume (0750). Consider encryption at rest; the SHA-256 already detects tampering.
  - Expired runs keep their parameters (ULIDs and filters), which is acceptable.
- **Rate limits and quotas:**
  - Reports: 20 requests a minute per user; there is no daily quota. A user could generate many 50,000-row exports.
  - Consider a per-day export quota and an alert to oversight on unusual export volume, using `record_access_logs` action `export`.
- **Audit review:** export and download logs exist but have no review screen. Phase 8 could add an "exports" view to the audit area (who exported what, when, at what classification).
- **Schedules after role or jurisdiction changes:**
  - a schedule always runs with the owner's scopes at run time, so a moved officer gets their new area;
  - a schedule's saved place outside the new reach makes runs fail validation and is skipped (logged).
  - Consider pausing schedules automatically when a user's jurisdiction changes.
- **Mail:** report and dissemination emails carry titles and links only. If mail is enabled in production, review the subject lines against classification policy.
- **Search logs and analytics exports** are append-only with no retention policy. Phase 8 should set retention for all audit-type tables together.
- **Session and download hardening:** downloads use `no-store` and requester-only checks. Consider re-authentication (password or TOTP) before large exports or downloads of `secret` reports.
- **Carried over:** module searches for communities and criminal groups still use `word_similarity() >=` (7B note).
