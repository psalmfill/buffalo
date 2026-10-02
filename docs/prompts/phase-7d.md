# BUFFALO — Phase 7D: Reports and Exports

Read `CLAUDE.md` before starting (see the stack table rows for Gotenberg and OpenSpout, and the decisions log entry on exports). Read `docs/handover/phase-7c.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Officers need formal documents: daily situation reports, weekly and monthly summaries, incident lists for analysis, and printable profiles. Exports are also the easiest way for sensitive data to leave the system, so every file must be built through the requester's own scopes, carry classification markings, identify who generated it, and be logged.

This step completes Phase 7.

---

## Step 7D.1 — Infrastructure

1. **Gotenberg** (MIT) for HTML → PDF:
   - Add a `gotenberg` service, pinned image version, on a Docker network marked `internal: true` that only the app, Horizon and Gotenberg share. No ports published.
   - Configure Chromium to deny every outbound URL except the files sent with the request (use Gotenberg's allow/deny list options; verify the current flag names). Disable modules and routes BUFFALO does not use (LibreOffice conversion, webhooks, and similar), and report which.
   - A test PDF request containing an `<img src="https://example.com/x.png">` must render without fetching it (prove with Gotenberg logs or a network-isolated test).
   - Bundle the report CSS, fonts (Inter, already local) and Chart.js into each request as files; templates reference them by relative name.
2. **OpenSpout** (MIT) for streaming XLSX and CSV.
   - **Formula injection protection:** any cell value starting with `=`, `+`, `-`, `@`, tab or carriage return is prefixed with a single quote. Applies to XLSX and CSV.
3. A private `reports` disk on its own Docker volume (`reports_data`), like `evidence`: never public, files streamed only through an authorised route.

**Commit:** `chore(reports): add gotenberg service and report storage`

## Step 7D.2 — Report runs

`report_runs` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `report_type` | enum (7D.3) |
| `format` | `pdf`, `xlsx`, `csv` |
| `parameters` | jsonb (validated per report type) |
| `requested_by` | user |
| `report_schedule_id` | nullable (7D.5) |
| `status` | `queued`, `running`, `completed`, `failed`, `expired` |
| `classification` | the highest classification among the records included (minimum `restricted`), computed during generation |
| `file_path`, `sha256`, `size_bytes`, `row_count`, `page_count` | |
| `error_message` | generic message for the user; full details only in application logs (without sensitive values) |
| `started_at`, `completed_at`, `expires_at` | `timestampTz`; expiry 30 days after completion (`config/buffalo.php`) |

- Generation runs on the queue inside `JurisdictionContext::actingAs($requester, …)`. **Reports are built strictly through the requester's scopes, as they are at generation time.** A deactivated or locked requester's queued runs fail.
- Only the requester can download a run. Nobody else, including the DG, can download another user's file; they generate their own.
- Every download is logged in `record_access_logs` (action `export`, context the report type). The SHA-256 is verified before each download (as with evidence).
- An expiry job deletes files past `expires_at` and marks the run `expired`; the run record remains.

## Step 7D.3 — Report types

**Markings on every PDF page:** classification banner at top and bottom (the run's level), "Generated for [name], [role], [jurisdiction] on [date time]", report reference (`RPT-<ULID>`), page X of Y, and a faint diagonal watermark with the requester's name. XLSX and CSV files carry the same information in the first rows (and the XLSX in its document properties).

| Type | Format | Who | Content |
|---|---|---|---|
| **Daily situation report (SITREP)** | PDF | all five roles | For the viewer's jurisdiction, the last 24 hours (or since the previous SITREP for scheduled runs): new incidents (reference, type, LGA, time, severity, status, casualties summary); casualties totals; escalations and acknowledgements; new or changed `immediate`/`priority` threats; flashpoints raised to `high`/`severe`; disseminations received; overdue verifications; coverage reports awaiting review. |
| **Weekly summary** | PDF | all five roles, for their own jurisdiction | Week vs previous week: KPI tiles, trend and breakdown charts (from 7C services), top LGAs (or states), notable incidents (critical and high), threats outcome, flashpoint changes, coverage status. |
| **Monthly summary** | PDF | DG, DDG, SD, DSD | Month vs previous month and same month last year; the 7C incident, casualty, response and threat analyses; for national users, by state. |
| **Incident list export** | XLSX, CSV | DG, DDG, SD, DSD | Any `IncidentFilters` selection, up to 50,000 rows. Columns: reference, type, category, severity, status, occurred at, precision, state, LGA, community, title, casualty counts by outcome, agencies involved, escalation, reporting officer, dates of each workflow step. **No person names** (counts of persons by involvement only), no descriptions above `restricted` unless the viewer has the clearance (they are included only as their scopes allow), no evidence. |
| **Incident report** | PDF | all five roles | One incident (the 5D print layout rendered through Gotenberg). Person names appear only as the viewer could see them on screen. |
| **LGA profile** | PDF | all five roles | Profile, communities summary, religious institutions summary, latest vulnerable population figures, security presence (formations), current **public office holders** (political offices only), flashpoints, recent incident summary. No persons of interest, no criminal group members. |
| **Coverage report** | PDF | all five roles | One coverage report with deployments, uncovered communities and workflow history. |
| **Analytics report** | PDF | all five roles | The current 7C analytics view with its filters, charts and tables. |

Rules:
- Persons and persons of interest are **never** exported in bulk (CLAUDE.md). The only names in bulk documents are current public office holders in the LGA profile.
- Reports with no data still generate, stating "No records in this period".
- Charts in PDFs use the same Chart.js wrappers, rendered with animation off; the template signals readiness (e.g. `window.reportReady = true`) and Gotenberg waits for it.

## Step 7D.4 — Generation UI

- `/reports`: choose a report type (only those the user may generate), parameters (period, geography within reach, filters, format), and **Generate**. The run appears in "My reports" with live status (Reverb on the user's channel, payload: run ULID and status only), and a download button when complete.
- "Export" buttons on the incident list (current filters → incident list export), incident page (incident report), LGA page (LGA profile), coverage report page, and analytics views (analytics report) open the same flow pre-filled.
- Large exports (over 10,000 rows) show a confirmation stating the row count and that the export is logged.

## Step 7D.5 — Scheduled reports

`report_schedules` table: `ulid`, `user_id`, `report_type` (SITREP, weekly, monthly only), `parameters`, `format`, `cadence` (`daily` with time; `weekly` with weekday and time; `monthly` with day 1–28 and time; all Africa/Lagos), `is_active`, `last_run_at`, `next_run_at`, `timestampsTz`.

- A scheduler job every 5 minutes creates runs for due schedules, generated **as the schedule's owner** with their scopes at that moment. Schedules of inactive or locked users are skipped and paused.
- On completion, the owner gets a notification (and an email if mail is enabled) saying only "Your [report name] is ready", with a link. **Files are never attached to emails.**
- Users manage their own schedules on `/reports/schedules`. Offer one-click defaults: daily SITREP at 07:00 (all roles), weekly summary Monday 08:00, monthly summary on the 1st at 08:00 (DG, DDG, SD, DSD).

## Step 7D.6 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `reports.generate` (PDF reports per the table above) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reports.export_data` (incident list XLSX/CSV) | ✓ | ✓ | ✓ | ✓ | |
| `reports.schedule` | ✓ | ✓ | ✓ | ✓ | ✓ |

Report types also require their module's view permission. Super admin: none. Navigation: "Reports" under Overview. Update the matrix, drift tests and `auth.ts`.

## Step 7D.7 — Tests

- Gotenberg cannot fetch external URLs; disabled routes are unreachable; the service is not reachable from outside the internal network (configuration test).
- Every report type per role: content equals the viewer's scoped data; drafts and invalid excluded; clearance ceiling; person names absent from bulk exports; LGA profile contains only public office holders.
- Run classification equals the highest included level.
- Only the requester can download; downloads logged; SHA-256 verified; expired files deleted and runs marked expired.
- Formula injection: values starting with `=`, `+`, `-`, `@`, tab and CR are neutralised in XLSX and CSV.
- Markings present on every PDF page (check extracted text) and in XLSX/CSV header rows.
- Scheduled runs: created when due, generated as the owner, skipped for inactive users, no attachments in emails.
- Row cap and large-export confirmation.
- Real-time status payloads contain only run ULID and status.

## Phase 7 completion checklist

- [ ] A Lagos `immediate` threat disseminated to a Kano LGA appears in that LGA chief's next SITREP.
- [ ] Global search finds a threat by reference and an incident by a phrase from its description.
- [ ] The DG's analytics compare quarters by state with materialisation rates for threats.
- [ ] A State Director generates the weekly summary PDF with charts, classification markings on every page and their name in the watermark; the download appears in the access log.
- [ ] A 20,000-row incident export opens in Excel with no person names, and a title starting with `=` shows as text.
- [ ] A scheduled daily SITREP is generated at 07:00 and the notification links to it.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-7d.md` per CLAUDE.md section 9, and `docs/handover/phase-7-summary.md` summarising all of Phase 7: dissemination rules, search providers, analytics and any rollups, report types and controls, permissions, performance, and open issues for Phase 8 (security hardening, backup and recovery). Print both.
