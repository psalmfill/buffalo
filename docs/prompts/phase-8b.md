# BUFFALO — Phase 8B: Application Security, Export Controls and Security Testing

Read `CLAUDE.md` before starting. Read `docs/handover/phase-8a.md` and the open issues in `docs/handover/phase-7-summary.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

8A hardened the database, secrets and audit trail. This step hardens the application itself: browser security headers, step-up authentication for sensitive actions, export quotas and review, malware scanning of uploads, safer report schedules, and automated security testing.

---

## Step 8B.1 — Browser security headers

1. **Content Security Policy** from Laravel middleware (not nginx, so nonces are possible):
   - `default-src 'self'`; `script-src 'self' 'nonce-…'` (Vite and Inertia support nonces; configure both); `style-src 'self' 'nonce-…'` (or `'unsafe-inline'` for styles only if Tailwind or shadcn require it; justify in the handover); `img-src 'self' data: blob:`; `connect-src 'self'` plus the Reverb WebSocket origin; `font-src 'self'`; `worker-src 'self' blob:` if the map needs it; `frame-ancestors 'none'`; `base-uri 'self'`; `form-action 'self'`; `object-src 'none'`.
   - In development, allow the Vite dev server origin only when `APP_ENV=local`.
   - Run in `Content-Security-Policy-Report-Only` first in local and CI, collecting violations to a log endpoint; fix all violations; then enforce. The handover lists any exceptions.
2. `Strict-Transport-Security` (1 year, `includeSubDomains`) when `APP_URL` is https. `Permissions-Policy` disabling camera, microphone, geolocation (unless the map picker uses browser geolocation; it should not), payment, USB. `Cross-Origin-Opener-Policy: same-origin`. `Cross-Origin-Resource-Policy: same-origin`.
3. Keep nginx's existing headers; avoid duplicates.
4. Tests: headers present on HTML, JSON and file responses; nonces differ per request; no inline script without a nonce in rendered pages.

## Step 8B.2 — Step-up authentication

Some actions need proof that the person at the keyboard is the account holder, even within a valid session.

1. `RequireRecentTwoFactor` middleware: passes if the user confirmed a TOTP code within the last 15 minutes (`config/buffalo.php` → `step_up.minutes`); otherwise returns a response that makes the frontend open a TOTP dialog, then retries the action.
2. A recovery code is **not** accepted for step-up (only TOTP), to keep recovery codes for lost devices.
3. Apply to:
   - downloads of report runs classified `secret`, and incident list exports over 10,000 rows (replacing the plain confirmation);
   - evidence original downloads above `restricted`;
   - approving cross-jurisdiction access requests and creating disseminations of `confidential`/`secret` records;
   - user management actions: role or jurisdiction changes, 2FA reset, issuing setup links, deactivating a super admin;
   - any key or backup operation exposed in the UI (8C).
4. Step-up confirmations and failures are logged (login history event types `step_up_success`, `step_up_failed`); failures count toward lockout.
5. Tests: each protected action requires step-up; the window expires; recovery codes rejected; failures count toward lockout.

## Step 8B.3 — Export quotas and review

1. **Daily quotas** per user (`config/buffalo.php` → `exports`): exported rows (default 100,000), report runs (default 50), evidence original downloads (default 100). Counted from `record_access_logs` and `report_runs`. Exceeding a quota blocks further exports that day with a clear message. A user may still view records normally.
2. **Unusual volume alert:** when a user passes 50% of any quota in a day, or exports `secret` material more than 5 times in a day, notify the DG and the DDGs covering the user's jurisdiction (payload: user's name, role, jurisdiction, counts; no record details). Once per user per day per rule.
3. **Exports review screen:** `/audit/exports` (permission `audit.view` plus a new `audit.exports` for DG and DDG): who exported, downloaded or printed what, when, at which classification, row counts, with filters by user, state, type, classification and date. National users only; DDGs limited to their zones.
4. Tests: quotas enforced per category; alerts once; review screen scoped for DDGs; super admin with `audit.view` sees it only if granted `audit.exports` (decide and report; default: not granted, since export records reveal what intelligence exists).

## Step 8B.4 — Malware scanning

1. Add a `clamav` service (official ClamAV image, pinned by digest) on the internal network, used via `clamd` over TCP from PHP (a small client; if a Composer package is used, report its licence).
2. Scan **before acceptance**: evidence uploads, person photos, community CSV imports, and boundary GeoJSON files. Evidence over the size limit for scanning is scanned in the queued processing job and stays in a `quarantined` state (not viewable or downloadable) until clean.
3. Infected files: rejected (or, for queued evidence, the evidence record marked `infected` and the file moved to a separate `quarantine` disk, never served). Notify super admins and the uploader's SD (payload: evidence or upload ULID, no content). Log a named activity event.
4. **Signatures offline:** `freshclam` updates automatically only when `CLAMAV_ONLINE_UPDATES=true`. For air-gapped deployments, document importing signature files (`main.cvd`, `daily.cvd`, `bytecode.cvd`) prepared on a connected machine, with a `make clamav-import-signatures` target. Report signature age in System health (8C) and alert super admins when older than 7 days.
5. If ClamAV is down, uploads are **refused** (fail closed), except evidence, which is accepted into `quarantined` and scanned when ClamAV returns. Show the reason to the user.
6. Tests: the EICAR test file is rejected for each upload type; quarantine flow; fail-closed behaviour when the service is unavailable; signature age alert.

## Step 8B.5 — Safer schedules and mail

1. When a user's role or jurisdiction changes (posting change), **pause** all their report schedules and notify them to review; resuming is a click. Deactivation pauses them too (already done for blocked owners; confirm).
2. **Mail subjects:** all mail subjects are generic ("BUFFALO notification", "BUFFALO: report ready", "BUFFALO: account setup"). No references, place names, incident types or classifications in subjects or previews. Bodies keep only the minimal text already used. Test every mailable.

## Step 8B.6 — Authorisation inventory test

The most valuable security test for a role-based system: prove every route is protected as intended.

1. A test that enumerates **every** registered route and fails if any route (other than an explicit allow-list: login, password reset, account setup, health check, broadcasting auth) lacks authentication, or lacks an authorisation check (policy, gate or `can` middleware). Report how authorisation is detected.
2. A generated matrix test: for each route with a model parameter, for each of the six roles, request a record **outside** that role's jurisdiction and assert 404 (or 403 where appropriate), and a record inside it and assert the expected status from the permission matrix.
3. The test output includes a route inventory file (`docs/security/route-inventory.md`, regenerated by a command) listing each route, method, middleware and policy.

## Step 8B.7 — Automated security scanning (CI)

Add to CI (not to the runtime stack):

- `composer audit` and `npm audit --omit=dev` (fail on high or critical).
- **Trivy:** scan the built `prod` image and the pinned third-party images for high/critical vulnerabilities; fail on fixable ones; produce a report artifact.
- **OWASP ZAP baseline scan** against the stack started in CI with a seeded test user (authenticated scan using a session set up by a script), passive rules plus a limited active scan of a few safe routes. Fail on high findings; document accepted warnings with reasons in `docs/security/zap-accepted.md`.
- Write `docs/security/pentest-guide.md`: scope, test accounts per role, what an external penetration tester should focus on (jurisdiction bypass, classification bypass, grant and dissemination abuse, export controls, evidence integrity, step-up bypass), so the client can commission an independent test before go-live.

## Step 8B.8 — Acceptance

- [ ] CSP enforced with no violations on any page in the browser; headers verified.
- [ ] Downloading a `secret` report asks for a TOTP code; 15 minutes later it asks again.
- [ ] A user exporting heavily triggers the DG alert; the exports review screen shows it.
- [ ] Uploading the EICAR test file as evidence is rejected; with ClamAV stopped, evidence is quarantined and released after scanning.
- [ ] Changing an officer's posting pauses their schedules.
- [ ] The route inventory test passes, with no unprotected routes outside the allow-list.
- [ ] CI runs `composer audit`, `npm audit`, Trivy and ZAP; all pass or have documented accepted findings.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-8b.md` per CLAUDE.md section 9 and print it.
