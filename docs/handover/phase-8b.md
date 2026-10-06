# Phase 8B handover — Application security, export controls and security testing

Prompt: [`docs/prompts/phase-8b.md`](../prompts/phase-8b.md). Builds on Phase 8A
(database roles, secrets, keys, audit chains).

## What was built

### 8B.1 Content Security Policy and browser headers (`3ff8162`, fix `375e07b`)

- `App\Http\Middleware\SecurityHeaders` (appended globally) sets on every
  response:
  - a **nonce-based CSP** (`Vite::useCspNonce()`, `Horizon::cspNonce()`,
    `<meta name="csp-nonce">`, `createInertiaApp({ nonce })`): `default-src
    'self'`, `script-src 'self' 'nonce-…'`, `style-src 'self'
    'unsafe-inline'`, `img-src 'self' data: blob:`, `connect-src` adds the
    Reverb websocket (`buffalo.security.csp.websocket`), `frame-ancestors
    'none'`, `object-src 'none'`, `base-uri`/`form-action 'self'`,
    `report-uri /csp-report`;
  - Permissions-Policy, Cross-Origin-Opener-Policy, Cross-Origin-Resource-
    Policy, and HSTS when `APP_URL` is https. nginx keeps
    X-Content-Type-Options, X-Frame-Options and Referrer-Policy.
- Report-only mode with `BUFFALO_CSP_REPORT_ONLY=true`; violations are
  logged by `CspReportController` to the `csp` daily log (directive, blocked
  origin, page path only), throttled 60/min, CSRF-exempt.
- Local hot mode only: the Vite dev origin and `worker-src blob:`.
- Fix: a response that sets its own stricter policy keeps it (evidence files
  are served with `default-src 'none'; sandbox`).

### 8B.2 Step-up authentication (`af5dd22`)

- `App\Support\Auth\StepUp`: a TOTP code within the last
  `buffalo.step_up.minutes` (15, `BUFFALO_STEP_UP_MINUTES`) is required for:
  secret report downloads; incident list exports above
  `reports.confirm_rows`; evidence originals above `restricted`; access
  request approval; disseminations (and onward) of confidential/secret
  records; user creation, jurisdiction changes, 2FA reset, setup links and
  deactivation of super admins.
- `StepUpRequired` → 428 JSON for Inertia/JSON requests (the
  `StepUpDialog` asks for the code and retries the visit; `postJson`
  retries once), otherwise a redirect to `/step-up?intended=`.
- `StepUpController` verifies with Fortify's provider (replay-protected),
  logs `step_up_success`/`step_up_failed` in login history; failures count
  towards lockout. Recovery codes are not accepted.

### 8B.3 Export quotas, volume alerts and exports review (`ce6cb35`)

- `App\Support\Exports\ExportControls`: daily per-user quotas (Africa/Lagos
  day) from report_runs and record_access_logs: exported rows (incident list
  exports, estimate stored at request), report runs (manual only, schedules
  excluded) and evidence original downloads (`buffalo.exports`). Exceeding a
  quota throws `ExportQuotaExceeded` (429 JSON, or a form error `quota`).
- Alerts (`ExportVolumeAlert`, warning) to the DG and the DDGs covering the
  user, never the user, once per user/rule/day (`export_alerts` unique
  table): passing `alert_fraction` (0.5) of a quota, or more than
  `secret_alert_count` (5) secret exports/downloads in a day.
- `/audit/exports` (`ExportReviewController`, gate `viewExportReview`:
  permission `audit.exports` + system/national reach; DG and DDG get it, DDGs
  with zones see officers in their zones only). Filters: officer, state,
  type, classification, dates. Navigation item "Exports review".

### 8B.4 Malware scanning (`d467312`, fix `44a04eb`)

- `clamav` service: **`clamav/clamav-debian:1.5.4`** pinned by multi-arch
  digest, on a new internal `scanning` network (app, horizon, scheduler).
  `clamd` limits raised to 160 MB. Wrapper `docker/clamav/entrypoint.sh`:
  online signature updates only with `CLAMAV_ONLINE_UPDATES=true`; refuses
  to start with no signatures offline; supervises `clamd` and exits when it
  dies so the restart policy restarts it (fix `44a04eb`, see Known issues).
- `App\Support\Malware\ClamdScanner`: a small INSTREAM/VERSION client over
  TCP (no package). `MalwareGuard` scans and reports (`malware_detected`
  activity event; `MalwareDetected`, critical, to super admins and the
  uploader's State Director; payload is kind, reference ULID, signature name,
  uploader; never the file, its name or content).
- Before acceptance:
  - person photos, community CSV previews: `MalwareFree` rule (after size,
    before type decoding); fails closed when ClamAV is down;
  - boundary GeoJSON (and mapping CSV): checked in `buffalo:import-boundaries`
    before reading; fails closed;
  - evidence ≤ `inline_max_mb` (10): scanned before storing, rejected if
    infected; larger files, or any file while ClamAV is down, are stored
    `quarantined` (new `incident_evidence.scan_status`, `scanned_at`) and
    scanned by `ProcessEvidenceJob` (now unique per item) through
    `ScanQuarantinedEvidence`. Infected originals move to the private
    `quarantine` disk (`evidence/<ULID>/original.quarantined`) and the item is
    marked `infected`; integrity verification checks that copy.
- Quarantined/infected files are never served (404 on thumb, preview,
  stream, download) and never decoded: conversions are skipped while the
  media item carries `awaiting_scan`, and EXIF is read only after a clean
  scan. The UI shows the state and why.
- `buffalo:malware:scan-quarantined` (every 5 minutes) and
  `buffalo:malware:check-signatures` (daily 06:45: alerts super admins when
  signatures are older than 7 days or ClamAV is down).
- `make clamav-import-signatures dir=…` verifies files with `sigtool` in a
  no-network container, copies them into the volume and reloads `clamd`.
- Docs: `docs/security/malware-scanning.md`.

### 8B.5 Schedules and mail (`cd35e0d`, wording fix `efcb0d1`)

- `PauseReportSchedules`: a role or jurisdiction change
  (`UpdateUserJurisdiction`) pauses all the user's schedules
  (`paused_reason = posting_change`, `paused_at`) and sends
  `ReportSchedulesPaused`; deactivation pauses them at once
  (`deactivated`); the runner still pauses blocked owners (`owner_blocked`).
  The schedules page explains the reason; resuming is the existing one-click
  button (clears the reason).
- Mail: `BuffaloNotification::mailSubject()` ("BUFFALO notification"),
  `mailBody()`, `mailUrl()`. `ReportReady` → "BUFFALO: report ready", body
  without report type, link to `/reports` (not the run); `AuditChainBroken`
  mail without table/row; `AccountSetupNotification` → "BUFFALO: account
  setup"; password reset → "BUFFALO: password reset"
  (`App\Support\Auth\PasswordResetNotification`).

### 8B.6 Route inventory and jurisdiction matrix (`16af280`)

- `App\Support\Security\RouteInventory` classifies all 312 routes. How
  authorisation is detected: `can:` middleware; Horizon's gate; in the
  controller action or any method of the same controller it calls
  (transitively): `$this->authorize`, `Gate::authorize|allows|denies|check|
  inspect`, `->can/cannot/canAny/checkPermissionTo/hasPermissionTo`; a Form
  Request whose `authorize()` is more than `return true`; or an explicit
  allow-list: 14 public routes, 27 self-service routes (own account,
  session, notifications, search through own scopes), 2 data-free redirects.
- `RouteInventoryTest`: every route authenticated or public, every route
  authorised or allow-listed, allow-lists not stale, no `storage/`,
  `_inertia/`, debug routes, and `docs/security/route-inventory.md` up to
  date (`buffalo:security:route-inventory`, `--check` in CI).
- `JurisdictionMatrixTest` (generated, 1042 assertions): one linked set of
  restricted records of every scoped model in LGA A1a and in state B1
  (`tests/Support/Security/MatrixRecords`). For every route with a scoped
  model parameter and each of the six roles: **outside** the jurisdiction,
  every method returns 404/403; **inside**, GET routes succeed exactly when
  the seeded matrix grants the route's permissions (`ROUTE_PERMISSIONS`),
  with three documented workflow exceptions (LGA chief edits only own
  coverage reports and threats; submitted incidents need `incidents.amend`)
  and photo-only stream. 19 routes with non-jurisdictional parameters are
  listed in `EXCLUDED` with the test that covers them.
- Found and fixed by the inventory:
  - the `local` disk had `serve => true`: unauthenticated, signature-only
    `GET|PUT storage/{path}` routes over `storage/app/private` → `serve`
    false;
  - **Inertia DevTools** was on in the local environment: it recorded every
    request's page props (persons, incidents) to `storage/inertia-devtools`
    and served them at `/_inertia/devtools` without sign-in → off unless
    `INERTIA_DEVTOOLS_ENABLED=true` (`config/inertia.php`).

### 8B.7 Security scanning in CI (`442b022`)

- `scripts/ci.sh`: `composer audit` (fails on high/critical), `npm audit
  --omit=dev --audit-level=high`, route inventory check.
- npm findings fixed: `shell-quote` overridden to `^1.11.0`
  (GHSA-pqg4-j6r4-53mv; `concurrently` pins 1.9.0), `source-map-js` 1.2.2;
  `concurrently` moved to devDependencies (local dev runner only). Both
  audits (production and full) now report 0 vulnerabilities.
- `scripts/security-scan.sh trivy|zap` (`make security-scan what=…`, GitHub
  job `security`, reports uploaded as `security-reports`):
  - **Trivy 0.75.0** (pinned by digest): the built `prod` image and the
    runtime third-party images from `compose.yml`; JSON report per image;
    fails on fixable high/critical (`.trivyignore` for reviewed exceptions,
    currently empty).
  - **OWASP ZAP 2.17.0** (pinned by digest), Automation Framework plan
    `scripts/zap/plan.yaml`: session from the real password + TOTP login of a
    dedicated test DG (`scripts/zap/session.php`, refuses production, not an
    Artisan command), GET-only spider, passive scan, active scan limited to
    search/list GET routes (10 minutes); `scripts/zap/check.py` fails on
    unaccepted high alerts.
- Fixed from ZAP: Laravel's `/up` page loaded Bunny Fonts and the Tailwind
  CDN (air-gap violation) → `App\Http\Controllers\HealthController`, plain
  text or JSON, outside the `web` group (no session).
- Docs: `docs/security/zap-accepted.md`, `docs/security/pentest-guide.md`.
- Third-party actions/images in CI are pinned by commit SHA / digest;
  Trivy and ZAP run as plain `docker run`, not third-party GitHub Actions.

## Acceptance checklist

| Item | Result |
|---|---|
| CSP enforced with no violations on any page; headers verified | **Pass.** Enforced (`BUFFALO_CSP_REPORT_ONLY=false`). As the Lagos SD: dashboard, incidents, incident evidence tab, map, analytics, persons, threats, flashpoints, reports, schedules, settings: no CSP console messages and nothing reported to `/csp-report`. Headers checked with curl. |
| Secret report download asks for TOTP; asks again after the window | **Pass**, with `BUFFALO_STEP_UP_MINUTES=1` temporarily: the secret weekly summary's download redirected to `/step-up`; after the code it returned 200; 80 s later it redirected again. No file was saved (checked with HEAD requests). |
| Heavy exporting triggers the DG alert; review screen shows it | **Pass**, with the daily report quota temporarily 4: the third SITREP sent "Unusual export volume" to the DG (once); the fifth was refused with "You have reached today's limit of 4 reports". `/audit/exports` as the DG lists the SD's exports with classification. See Known issues (requests vs exports). |
| EICAR evidence rejected; with ClamAV stopped evidence quarantined and released | **Pass**, against the real ClamAV: EICAR upload → 422 "contains malware", `Eicar-Test-Signature FOUND` in clamd's log, both super admins notified. With ClamAV stopped, a text file was accepted as `quarantined` with the "scanning is unavailable" notice and its download returned 404; after restarting ClamAV the scheduled sweep scanned it: `clean`, integrity-verified. |
| Changing an officer's posting pauses their schedules | **Pass** (through `UpdateUserJurisdiction`, the action the users screen calls): the DSD's schedule paused with `posting_change`; "Your report schedules were paused" delivered; moved back afterwards. |
| Route inventory test passes, nothing unprotected outside the allow-list | **Pass.** |
| CI runs composer audit, npm audit, Trivy and ZAP; all pass or documented | **Partly.** composer/npm audits pass; ZAP: no high alerts, medium/low documented in `zap-accepted.md`. **Trivy fails on third-party images** (open decision below); our own `prod` image passes. |
| `make test`, `make lint`, `make ci` pass | **Pass.** `make ci`: 1464 Pest tests (12,135 assertions) including the real-clamd tests (required there), 14 Vitest tests. |

Temporary acceptance settings in the local `.env` were removed afterwards.

## Files

### Created

- `app/Http/Middleware/SecurityHeaders.php`, `RequireRecentTwoFactor.php`;
  `app/Http/Controllers/Security/CspReportController.php`,
  `Auth/StepUpController.php`, `Audit/ExportReviewController.php`,
  `HealthController.php`
- `app/Support/Auth/StepUp.php`, `PasswordResetNotification.php`;
  `app/Support/Exports/ExportControls.php`;
  `app/Support/Malware/{MalwareScanner,ClamdScanner,MalwareGuard,ScanResult,ScannerVersion,ScannerUnavailable}.php`;
  `app/Support/Security/RouteInventory.php`
- `app/Exceptions/{StepUpRequired,ExportQuotaExceeded}.php`;
  `app/Rules/MalwareFree.php`;
  `app/Enums/{EvidenceScanStatus,SchedulePauseReason,UploadKind}.php`
- `app/Actions/Evidence/ScanQuarantinedEvidence.php`,
  `app/Actions/Reports/PauseReportSchedules.php`
- `app/Console/Commands/{CheckMalwareSignatures,ScanQuarantinedEvidenceCommand,RouteInventoryCommand}.php`
- `app/Notifications/{ExportVolumeAlert,MalwareDetected,MalwareScannerAlert,ReportSchedulesPaused}.php`
- Migrations `2026_10_18_000001`–`000004` (step-up login events,
  export_alerts, evidence scan status, schedule pause reason)
- `routes/step-up.php`; `resources/js/components/step-up-dialog.tsx`,
  `lib/step-up.ts`, `pages/auth/step-up.tsx`, `pages/audit/exports.tsx`
- `docker/clamav/entrypoint.sh`; `scripts/clamav-import-signatures.sh`,
  `security-scan.sh`, `zap/{session.php,plan.yaml,check.py,accepted.txt}`;
  `.trivyignore`
- `docs/security/{malware-scanning,route-inventory,zap-accepted,pentest-guide}.md`
- Tests: `tests/Feature/Security/{SecurityHeaders,ExportControls,MalwareScanning,ClamdScanner,SchedulesAndMail,RouteInventory,JurisdictionMatrix}Test.php`,
  `tests/Feature/Auth/StepUpTest.php`,
  `tests/Support/{Malware/FakeMalwareScanner,Security/MatrixRecords}.php`

### Changed

- Controllers: reports, report schedules, evidence (files and upload), access
  requests, disseminations, users; presenters for evidence and report runs
- `StoreEvidence`, `VerifyEvidenceIntegrity`, `ProcessEvidenceJob`,
  `RequestReport`, `UpdateUserJurisdiction`, `DeactivateUser`,
  `RunReportSchedules`, `ImportBoundaries`
- Models `IncidentEvidence` (scan status, conversion gating), `ReportSchedule`,
  `User` (password reset mail); enums `AccessAction` (labels), `LoginEvent`,
  `Permission` (`audit.exports`); seeder matrix; `AuditAccess`
- Notifications base class (mail subject/body/URL) and `ReportReady`,
  `AuditChainBroken`, `AccountSetupNotification`
- Form requests for person photos, persons, community import preview,
  report generation
- `bootstrap/app.php` (headers, CSRF exemption, step-up alias, own health
  route), `routes/{web,audit,console}.php`, `config/{buffalo,filesystems,
  inertia,logging}.php`, `.env.example`
- `compose.yml` (clamav, scanning network, quarantine volume),
  `docker/php/Dockerfile` (quarantine directory), `Makefile`
  (`clamav-import-signatures`, `security-scan`, ClamAV in `make ci`),
  `.github/workflows/ci.yml` (ClamAV service, `security` job),
  `scripts/ci.sh`, `.gitignore` (`/build`)
- Frontend: `app.tsx`, `app.blade.php`, `lib/http.ts`, navigation and its
  test, `types/{auth,evidence,reports}.ts`, evidence components, reports and
  schedules pages, login history table
- `package.json`/`package-lock.json` (override, devDependency move)
- Tests: `TestCase` (fake scanner bound for every test), `CrossFixtures`
  (`signIn` sets step-up; `signInWithoutStepUp`), reports, users, password
  reset, evidence upload, health check tests
- `README.md` (Phase 8B section)

## Deviations and decisions

- **ClamAV image:** `clamav/clamav-debian` instead of `clamav/clamav`: the
  latter has no arm64 build. Same publisher (Cisco Talos), pinned by digest.
- **Exports review access:** the prompt implies audit access; DDGs do not
  hold `audit.view`, so the gate requires only `audit.exports` (DG, DDG) and
  national/system reach. Super admins do not get `audit.exports` by default
  (intelligence-adjacent); the gate admits them if it is granted.
- **Evidence scanning inline limit** (`BUFFALO_MALWARE_INLINE_MAX_MB`, 10 MB)
  matches the existing `queue_above_mb`; existing evidence was set to
  `quarantined` by the migration (fail closed) and is scanned by the sweep.
- **Matrix inside expectations** come from the seeded permissions (read via
  `checkPermissionTo`), not from the policies under test; workflow rules
  are listed explicitly.
- **Scanner session** is created through the real login and TOTP flow
  rather than by forging a session.
- Laravel's built-in health route was replaced (CDN assets).

## New packages and images

- No new Composer or npm packages. npm: `overrides.shell-quote` `^1.11.0`
  (MIT, already a transitive dependency).
- Images: `clamav/clamav-debian:1.5.4` (GPL-2.0, separate service container,
  as CLAUDE.md allows); CI only: `aquasec/trivy:0.75.0` (Apache-2.0),
  `zaproxy/zap-stable:2.17.0` (Apache-2.0); GitHub action
  `actions/upload-artifact` v7.0.1 (MIT) pinned by SHA.

## Known issues and open decisions

1. **Open decision: Trivy on third-party images.** The gate fails today on
   fixable high/critical vulnerabilities in images we do not build, with no
   newer upstream tags available (6 Oct 2026): Gotenberg 8.37.0 (269, mostly
   Chromium 152 → fixed in 153/154), PostGIS 18-3.6-alpine (38: OS packages
   and Go stdlib in its bundled `gosu`), ClamAV 1.5.4 (7: OpenSSL, PCRE2),
   nginx 1.30.5 (2: expat, PCRE2). Valkey and our `prod` image are clean.
   Options: (a) build thin patched derivatives (OS updates on top of the
   pinned images; Chromium upgrade inside Gotenberg needs PDF retesting);
   (b) accept with dated `.trivyignore` entries until upstream releases;
   (c) gate only our image and report the others. Needs the owner's choice;
   the `security` job stays red until then. Phase 8C.5 (digest pinning)
   touches the same images.
2. **ClamAV memory.** `clamd` needs about 1.5 GB. In development (8 GB Docker
   VM, with Gotenberg's Chromium, ZAP and builds) the kernel killed it; the
   new entrypoint supervision restarts it (verified by killing `clamd`).
   Phase 8C's per-service memory limits must reserve enough for it.
3. **ClamAV signatures** in the image are from 28 Sep 2026; the age alert
   fires in development until signatures are imported or online updates
   enabled.
4. **Exports review** lists exports, downloads and prints (record_access_logs).
   Report *requests* that were never downloaded do not appear, although
   they count towards the report quota and its alert. Consider listing
   requests too if the client wants it.
5. A HEAD request to a download route counts as an export (logged and
   counted). Browsers do not send HEAD for downloads; noted for completeness.
6. **CSP** keeps `style-src 'unsafe-inline'` (sonner, react-style-singleton)
   and uses `report-uri` (not `report-to`); see `zap-accepted.md`.
7. Development leftovers (not in git): `storage/inertia-devtools` still holds
   the 106 recordings made while DevTools was on; delete them when
   convenient. The ZAP test account `zap.scanner@buffalo.test` exists in the
   dev database (deactivated; `session.php` reactivates it per scan).
8. The route inventory detects authorisation statically; it proves a check
   exists, not that it is right. `JurisdictionMatrixTest` and the module
   tests cover correctness.

## For the next step (8C)

- `/up` is now `HealthController` (plain); the detailed health endpoint is
  8C's.
- System health should show ClamAV signature age
  (`MalwareScanner::version()->signatureAgeDays()`), the quarantine count,
  and the CSP/ZAP results if useful.
- Container hardening must keep: the `scanning` network internal, ClamAV's
  memory, the quarantine volume (back it up? it holds infected originals:
  decide), and the supervision wrapper.
- Restored evidence must respect `scan_status`; restic should include the
  quarantine volume only if the client wants infected originals kept.
