# BUFFALO — Phase 9B: Go-Live Readiness

Read `CLAUDE.md` before starting. Read `docs/handover/phase-9a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

9A made BUFFALO installable and upgradable on any of the three deployment profiles. This step prepares the system and its users for real use: loading production reference data, onboarding hundreds of officers, rolling out to pilot states first, in-app help and role manuals, a UAT environment and scripts, and an automated go-live check. It completes the core project.

---

## Step 9B.1 — Production data loading

1. `docs/operations/data-loading.md`: the order and commands for a new production system:
   1. `buffalo:import-hierarchy` with the owner's senatorial district, LGA and ward CSVs (`--dry-run` first);
   2. `buffalo:import-boundaries` for LGAs (and states if supplied), with attribution;
   3. review of the default reference data (incident types and their fields, threat types, agencies, sectors) with the client, recording sign-off;
   4. optional bulk community import per LGA (Phase 2B import).
2. `buffalo:data:status`: a summary of what is loaded (counts of states, districts, LGAs, wards, boundaries, communities; reference data versions), what is missing, and whether **any** synthetic or demo data exists (demo LGAs, synthetic boundaries, demo incidents, acceptance fixtures). Used by the go-live check.
3. A guard: the demo and synthetic generators (`DevelopmentHierarchySeeder`, `buffalo:generate-demo-boundaries`, `buffalo:seed-demo-incidents`) are not just refused outside `local`; in a production image they are absent from the command list.

## Step 9B.2 — Bulk user onboarding

Creating hundreds of accounts one by one is impractical.

1. **Import** (super admin, step-up required): upload a CSV (`name,email,phone,service_number,rank,role,state_code,lga_code,zone_codes`), validated in full before writing (role and jurisdiction combinations, duplicates, existing emails, unknown places), with a preview showing each row as `new`, `exists` or `error`; confirm to create accounts in one transaction. Malware-scanned like other uploads. The file is not stored.
2. Imported accounts are created **without** setup links.
3. **Issuing links in batches:**
   - **By email** (when mail is enabled): select users (filter by state, role, "setup pending"), confirm with step-up, and send each their own link.
   - **Handover sessions** (no mail): a page listing the selected users where the admin reveals one user's link at a time (shown once, with a copy button), for in-person or phone handover during training sessions. Each reveal is logged.
   - Links are never exported to files.
4. "Setup pending" and "2FA not enrolled" counts per state on the super admin dashboard, so onboarding progress is visible.

## Step 9B.3 — Pilot rollout controls

1. Add `is_live` (boolean, default false) and `went_live_at` to `states`. Super admin toggles it with step-up and a reason (logged).
2. Until a state is live, its state- and LGA-level users cannot sign in: after password and 2FA they see "BUFFALO is not yet active for your state." The attempt is recorded in login history (`state_not_live`). National users and super admins are unaffected.
3. Nothing else changes: data for non-live states can still be loaded (communities, formations) by national users or during training via the UAT environment.
4. A "Rollout" panel on the super admin dashboard: live states, users per state by onboarding status.

## Step 9B.4 — In-app help and manuals

1. A **Help** section (`/help`) rendering Markdown manuals from `docs/manuals/` (bundled into the image; sanitised rendering; no external links or images):
   - a getting-started guide (signing in, 2FA, the dashboard, notifications, search);
   - one manual per role: DG, DDG, State Director, Deputy State Director, LGA Security Chief, Super Admin;
   - task guides: reporting an incident (including fast critical reporting), evidence, persons and cross-state requests, threats and dissemination, flashpoints, coverage reports, reports and exports, what is logged and why.
2. Each user sees their own role's manual first; all task guides are visible to all intelligence roles; the super admin manual is visible to super admins only.
3. **Contextual help:** a "?" link on major pages opening the relevant guide section.
4. One-page **quick reference cards** per role (`docs/manuals/cards/`), printable via a Gotenberg PDF route (classification `restricted` marking, as with other PDFs).
5. Write the manuals from the system as actually built (the handovers and the code), in plain English, using screenshots only if they can be generated from the UAT environment with synthetic data (never real data); otherwise describe screens in words.

## Step 9B.5 — UAT environment and scripts

1. `make uat-up`: a separate Compose project for user acceptance testing, using the `prod` image with the `airgapped` profile, synthetic demo hierarchy and boundaries for two demo states, demo incidents (a few thousand), and one demo account per role with known passwords and TOTP secrets printed once (UAT only; refuses to start if `APP_ENV=production`). A visible banner on every page: "UAT — TEST DATA ONLY".
2. `docs/uat/`: one test script per role, as step-by-step scenarios with expected results and a pass/fail column, covering every major workflow (report and process an incident end to end, critical incident fast path, evidence upload and custody, person search with cross-state request and approval, threat creation and dissemination, flashpoint assessment, coverage report cycle, reports and exports with step-up, user onboarding, pilot state toggle).
3. A **feedback** button in the UAT environment only, storing short text, page URL and role in a `uat_feedback` table, with a list for super admins and a CSV export. Disabled in production builds.

## Step 9B.6 — Go-live check

`php artisan buffalo:go-live:check` (and a read-only view on System health), listing each item as pass, fail or "client decision recorded":

**Technical** (must pass):
- production mode and boot checks (9A) pass; TLS valid for at least 30 days;
- no demo or synthetic data (`buffalo:data:status`);
- hierarchy loaded: 37 states, 109 districts, 774 LGAs; LGA boundaries loaded;
- at least one active super admin with 2FA; no development accounts;
- backups: a full database backup and an evidence snapshot exist and are recent; repository 2 configured; a restore drill completed on **this** server within the last 30 days with a passing report;
- ClamAV signatures under 7 days old; audit chains verified within 24 hours;
- Trivy exceptions not expired; CI security scans passed for the installed version.

**Client decisions** (must be recorded, with who and when, in a `golive_decisions` table via a super admin form with step-up): RPO/RTO confirmed; retention periods set or "keep indefinitely" explicitly chosen; key and passphrase escrow custodians named and escrow verified at a drill; ZAP accepted findings signed off; independent penetration test completed (date and summary reference) or explicitly deferred with a reason; quarantined malware handling decided; mail enabled or not.

The check exits non-zero until every technical item passes and every decision is recorded. Its output (no secrets) is saved to `docs/drills/` style reports for the client's records.

## Step 9B.7 — Tests and acceptance

- Hierarchy and boundary data status, including demo-data detection.
- Bulk import: validation, preview, transaction, no links issued, file not stored, step-up.
- Batch link issuing by email and by handover session; reveal logged; links never exportable.
- Pilot gating: non-live state users blocked after authentication with the right message and login history entry; national users unaffected; toggling logged with step-up.
- Help: role-appropriate manuals, sanitised rendering, no external resources (CSP still clean), super admin manual hidden from others.
- UAT environment refuses production mode; feedback disabled in production builds.
- Go-live check: each item fails correctly in a fixture setup and passes in a fully prepared one.

**Phase 9 completion checklist**

- [ ] On a fresh `airgapped` install from a signed bundle: create the super admin, import the owner's data (or the test fixtures standing in for it), import 50 users from CSV, mark one state live, hand over setup links in a session, and have users from each role complete setup.
- [ ] A user in a non-live state is blocked with the right message.
- [ ] Every role's manual and quick reference card is complete and accurate against the built system.
- [ ] The UAT environment runs all role scripts end to end with no failures (record results in `docs/uat/results/`).
- [ ] `buffalo:go-live:check` fails on a demo install and passes on a prepared one.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-9b.md` per CLAUDE.md section 9, and `docs/handover/phase-9-summary.md` summarising Phase 9 and the **state of the whole project**: what is built, how to deploy it on each profile, what the client must still decide or provide, known limitations, and recommended next steps (including the optional Phase 10 extensions). Print both.
