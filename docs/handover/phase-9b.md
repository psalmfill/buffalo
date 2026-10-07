# Phase 9B handover: go-live readiness

Phase 9B prepares BUFFALO and its users for real use: production data
status, bulk onboarding, pilot rollout, in-app help, a UAT environment with
scripts, and an automated go-live check. It completes the core project.

## What was built

### 9B.1 Production data loading (commit 1bb2072)

- `docs/operations/data-loading.md`: the order and commands for a new
  system. Hierarchy (`--dry-run` first), boundaries with attribution,
  reference data review and sign-off, the optional community import, and a
  final check. The owner's files reach the database through the one-off
  `tools` container with a read-only bind mount.
- `buffalo:data:status` (`--json`), built on `App\Support\GoLive\DataStatus`.
  It reports:
  - hierarchy counts against 37/109/774, plus wards;
  - LGA and state boundaries with their sources, flagging synthetic ones;
  - communities;
  - a fingerprint for each reference list and an overall reference data
    version;
  - what is missing;
  - every kind of demo or synthetic data: demo LGAs and districts, synthetic
    boundary imports, `DEMO/` or `[Demo]` incidents, and accounts on reserved
    test domains (`.test`, `.local`, `.localhost`, `.example`, `.invalid`,
    `example.com/.net/.org`).

  It exits 1 until the system is ready. It runs only in the system context:
  in a user's context it refuses, rather than silently counting only that
  user's jurisdiction.
- **Guard:** the demo generators moved from `app/Console/Commands` (which is
  auto-discovered) to `app/Console/DemoCommands`:
  `buffalo:generate-demo-boundaries`, `buffalo:seed-demo-incidents` and
  `buffalo:uat:seed`.
  - They are registered only when `APP_ENV` is `local`, `uat` or `testing`.
  - With `APP_ENV=production`, which `compose.prod.yml` forces, they do not
    appear in `artisan list`. A test proves this by running Artisan in a
    separate process.
  - They, and `DevelopmentHierarchySeeder`, run only in `local` and `uat`.

### 9B.2 Bulk user onboarding (commit 6735342)

- **Users → Import** (super admins):
  - The CSV (`name,email,phone,service_number,rank,role,state_code,lga_code,zone_codes`)
    is malware-scanned as the new upload kind `user_csv`, read from PHP's
    temporary upload and never stored.
  - Every row is validated before anything is written. The checks are the
    single-user role and jurisdiction rules (`UserJurisdiction`), unknown
    roles, states, LGAs and zones, duplicates within the file, existing
    emails and service numbers, and invalid email addresses.
  - The preview marks each row New, Exists or Error.
  - Confirming requires step-up. It is refused while any row has an error,
    creates the new accounts in one transaction, and skips existing ones. No
    setup links are issued.
  - The audit event `users_imported` records counts only.
  - Super admin accounts are never imported in bulk; they are created one at
    a time.
- **Users → Onboarding:**
  - Filter by state, role and stage, then select users.
  - **Email setup links** (step-up, mail enabled) gives each user their own
    link. Batches reach only accounts still waiting for setup, so a batch can
    never reset the passwords of accounts in use.
  - **Start handover session** lists the selected users without any link.
    **Reveal link** issues one link at a time through the existing endpoint
    with the new delivery value `handover`. The link is shown once, held only
    in page memory, and copyable. Each reveal is audited (`setup_link_issued`
    with the delivery, never the link).
  - Links are never listed or exported. A test asserts which routes can
    return one.
- **Super admin dashboard:** a rollout and onboarding panel shows, per state
  and for national users, the users with no link, a link issued, 2FA not
  enrolled, and ready.

### 9B.3 Pilot rollout (commit 3c662b9)

- **Schema:** `states.is_live` (default false, including on upgraded
  installations), `went_live_at`, and the login event `state_not_live`.
- **`EnsureStateIsLive`** applies to state- and LGA-level users whose state
  is not live:
  - On the first request after password and 2FA, it ends the session,
    records `state_not_live` (instead of `login_success`) and shows "BUFFALO
    is not yet active for your state." on the sign-in page.
  - It also ends existing sessions when a state is switched off.
  - Account setup and 2FA enrolment stay possible, so officers can be
    onboarded in training before go-live.
  - National users and super admins are never affected.
  - A state-level account without a state fails closed.
- **Rollout page** (super admins): **Make live** / **Set not live** requires
  step-up and a reason. Audit events `state_went_live` / `state_taken_offline`
  record the reason, and the model change is also in the activity log.
- **`buffalo.rollout.enforce`:** always true in production (the production
  check refuses false). The test suite sets it false, except in the rollout
  tests.
- Local development marks the demo states live; UAT sets its own rollout.

### 9B.4 Help and manuals (commit c6f4b1c)

- `/help` renders `docs/manuals` on the server with league/commonmark (a
  Laravel dependency, no new package). Raw HTML is stripped, unsafe links are
  refused, images are removed, external links become plain text, links
  between manuals become `/help/...`, and headings get ids. The manuals are
  bundled into the image (`!docs/manuals` in `.dockerignore`).
- **Manuals**, written from the handovers and checked against the actual
  screen labels:
  - getting started;
  - one per role;
  - eight task guides, including fast critical reporting and "what is logged
    and why".
- **Visibility:**
  - each user's own role manual comes first;
  - intelligence roles see all intelligence role manuals and every guide;
  - the super admin sees getting started, their own manual and card, and
    "what is logged".
- **Contextual help:** a "?" next to the title on the dashboards and the
  incident, threat, flashpoint, persons, access request, coverage, reports,
  analytics, dissemination, blackspot, user, onboarding, import, handover,
  System health, Rollout and Go-live pages. A test checks that every link
  points to a real manual section.
- **Quick reference cards:** one per role, each one A4 page, printed through
  Gotenberg with the RESTRICTED banner, a "Generated for" line and a
  watermark. They are streamed, not stored, and audited as
  `help_card_printed`.
- **Report PDF fix (affects every 7D report):** under Chromium 154, the 9A
  patched Gotenberg, the header and footer ran the classification banner into
  the details and pushed the page number off the page. Each template now
  wraps its content in one full-width block. I verified the result on real
  renders.

### 9B.5 UAT environment and scripts (commit f4683cc)

- **Environment** (`make uat-up`, `make uat-down`, `make uat-credentials`):
  - A separate Compose project `buffalo-uat`. It uses the production images
    built from the current tree, `compose.prod.yml`, the airgapped profile and
    `compose.uat.yml` (`APP_ENV=uat`).
  - Settings, generated secrets and a self-signed certificate live in `.uat/`
    (git-ignored), on 127.0.0.1:19443 and 19080. It has its own frontend
    bridge, `bflo-uat-front`; Linux limits bridge names to 15 characters,
    which is now documented.
- **Production safety in UAT:**
  - The containers run `buffalo:production:check --uat`: the production rules,
    but `APP_ENV` must be `uat`.
  - The strict check (production only) is unchanged and still used by the
    go-live check, so a UAT system can never pass go-live.
  - UAT is production-like for the key fingerprint check, the
    destructive-command guard and the Horizon supervisors (`Uat::productionLike()`).
  - `up.sh` refuses `.uat` settings that say `APP_ENV=production`.
- **`buffalo:uat:seed`** (UAT only):
  - It refuses outside `APP_ENV=uat`, and on any database holding a non-UAT
    account.
  - It seeds roles, zones and states, reference data, the demo hierarchy for
    Lagos and Kano, about 3,000 demo incidents (before the boundaries, which
    the location trigger requires), synthetic boundaries, and a Kano person
    for the cross-state scenario.
  - It creates nine accounts (one per role, plus Kano and Oyo officers for the
    scenarios) with 2FA enrolled. Lagos and Kano are live; Oyo is not.
  - It prints the passwords and TOTP secrets once. Passwords avoid characters
    that break the table or a shell. `--reset-credentials` issues new ones;
    `--if-empty` makes `up.sh` idempotent.
- **UAT-only features:**
  - a red "UAT — TEST DATA ONLY" banner on every page (forced by
    `SystemBanner`);
  - a **Feedback** button. Feedback goes into the `uat_feedback` table with
    the path only (query strings dropped), the role and the user. Super admins
    get a list and a CSV export, with formula injection neutralised.
  - The routes are registered only in `uat`, `testing` and `local` (for the
    route helpers) and answer 404 outside UAT. They do not exist in a
    production deployment. The frontend uses fixed paths for them, because
    production builds generate route helpers in production mode.
- **`docs/uat/`:** a README (accounts, rules, recording results) and one
  script per role. Together they cover every workflow in the prompt:
  - an incident end to end, and the fast critical path;
  - evidence and its custody;
  - person search, cross-state request and approval;
  - threat creation and dissemination;
  - flashpoint assessment;
  - the coverage report cycle;
  - reports and exports with step-up;
  - onboarding;
  - the pilot state toggle.
- **`scripts/uat/run.py`** (Python standard library only) runs the six
  scripts against a UAT environment. It signs in as each tester with
  password and TOTP, performs each step through the endpoints the screens
  use, checks the expected results from the server's answers, and writes
  `docs/uat/results/<date>-<script>.md`.

### 9B.6 Go-live check (commit 292bc91)

- **`buffalo:go-live:check`** lists technical items, which must pass:
  - production and start-up checks;
  - the TLS certificate nginx actually serves, valid for at least 30 days
    (read over the internal network, so it works for own certificates and
    ACME alike);
  - no demo or synthetic data;
  - the 37/109/774 hierarchy and LGA boundaries;
  - an active super admin with 2FA;
  - no accounts on test domains;
  - a full database backup (8 days at most) and a recent backup and evidence
    snapshot (26 hours at most);
  - repository 2 in use for both backups;
  - a passing full or point-in-time restore drill on this server within 30
    days (the drill now records its host);
  - ClamAV signatures under 7 days old;
  - audit chains verified within 24 hours;
  - vulnerability exceptions not expired;
  - a passed security scan recorded for the installed version.

  It also lists the client decisions, which must be recorded. It exits 1
  until both are complete, writes a Markdown report (no secrets) to the
  private disk under `go-live/`, and runs daily with `--record` (which always
  exits 0, so the scheduler does not report a failure every day before
  go-live).
- **`golive_decisions`:** append-only (`buffalo_reject_audit_change`) and
  hash-chained (added to `AuditChain::TABLES`). The decisions are:
  - RPO/RTO;
  - retention (periods set, or keep indefinitely);
  - escrow (custodians named and verified at a drill);
  - ZAP sign-off;
  - penetration test (completed or deferred, with details);
  - quarantine handling;
  - mail (enabled or not);
  - **reference data** sign-off. This one records the reference data version
    and lapses when the data changes.

  Super admins record decisions on the **Go-live** page with step-up. A newer
  record supersedes the previous one.
- **Read-only view:** a Go-live card on System health, and the Go-live page
  with every item, the decision history and the form.
- **Releases:**
  - `make release` runs the Trivy gate on the release images
    (`scripts/security-scan.sh trivy-release`) and ships `security-scan.json`
    inside the signed bundle. `SKIP_SCAN=1` is for test releases only and
    fails the go-live check.
  - `install.sh` and `upgrade.sh` record it with `buffalo:release:record`.
  - The app image carries `BUFFALO_VERSION` as a build argument, so the check
    compares the scan with the version actually running.

## Verification

### UAT (scripted, `docs/uat/results/`)

`scripts/uat/run.py` ran on a freshly created UAT environment
(`make uat-down && make uat-up`). **67 of 67 steps passed** across the six
role scripts. Among them:

- **Incident end to end:** reported, submitted (`BUF/LA/D11/2026/000001`),
  verified, investigated, resolved and closed, then locked.
- **Critical fast path:** the incident was escalated automatically and the
  DDG acknowledged it.
- **Evidence:** malware-scanned on upload; the original downloaded byte for
  byte; views and downloads in the custody record.
- **Cross-state request:** a NIN search gave "Record found" with no
  details; a name alone gave no notice. The Kano State Director approved it
  narrowed to profile, with step-up, and the Lagos chief saw a read-only
  profile without NIN or phone.
- **Threat dissemination:** acknowledged by Kano, which sees no History.
- **Flashpoint** assessment.
- **Coverage report** returned, resubmitted, then reviewed and locked.
- **Exports and reports:** the SITREP PDF, and the incident list XLSX marked
  SECRET (the highest level included), with no descriptions and a code
  asked for the secret download.
- **Delegation** granted and revoked.
- **Reference data** added and deactivated; audit log by user; a duplicate
  invalidated; the reference card printed.
- **Onboarding:** an import preview with one error, then four accounts
  created with step-up; a handover reveal; an officer completing setup.
- **Pilot:** the Oyo officer refused, then made live, then signing in.
- **Feedback** recorded and exported.

The runner asserts that step-up was asked, or confirmed within the
15-minute window.

### Phase 9 completion checklist

Run on a **fresh airgapped install of the signed release 1.0.0-rc1**:

- `make release` built the release, and the Trivy gate passed on all seven
  release images.
- `scripts/ci/release-install-test.sh` installed it: every container was
  blocked from the internet, including nginx through the firewall rule.

| Item | Result |
|---|---|
| Create the super admin | `buffalo:create-super-admin` (password piped); 2FA enrolled at first sign-in |
| Import the owner's data (test fixtures) | Full-size stand-in files in the owner's format: 37 states, 109 districts, 774 LGAs, imported with `--dry-run` first; 774 LGA boundaries with attribution, 37 state boundaries derived. `buffalo:data:status`: no demo data, required data complete |
| Import 50 users from CSV | Preview 50 new, 0 errors; created with step-up; all setup pending |
| Mark one state live | Lagos (reason and step-up); no other state live |
| Hand over setup links in a session | The session page lists 6 users and no links; links revealed one at a time (delivery `handover`) |
| Users from each role complete setup | A DG, a DDG, the Lagos State Director, Deputy and LGA chief, and the Kano State Director set password and 2FA; the five with live access reach their own dashboards |
| A user in a non-live state is blocked | The Kano State Director: "BUFFALO is not yet active for your state."; `state_not_live` in login history |
| `buffalo:go-live:check` fails on a demo install | UAT: fails (demo data, test accounts, decisions missing); the dev stack fails the same way |
| …and passes on a prepared one | After an offline ClamAV signature import, repository 2, fresh backups, a restore drill on that install (pass, within the RTO), audit verification and the eight decisions: **all 15 technical items pass and all 8 decisions are recorded, "Ready to go live"** (`docs/drills/2026-10-07-1950-go-live-check.md`, labelled as a test installation) |
| `make test`, `make lint`, `make ci` | All pass: 1,602 Pest tests (12,891 assertions) and 14 Vitest; Pint, Larastan level 7, ESLint, Prettier and tsc clean; `make ci` (hardening checks for three combinations, 15 dated exceptions, no Composer or npm advisories, fresh build, the full suite). One `make ci` run failed in its fresh `npm` install (Rolldown's native binding was missing, a known intermittent npm optional-dependency issue); an unchanged re-run passed. |

The checklist found five defects, fixed in commit 64fb8e2 (and b8125cb:
`golive_decisions` added to the application role's append-only grants,
which the full suite caught):

1. `tools` was not on the scanning network, so the documented boundary
   import failed on every production install.
2. `clamav-import-signatures.sh` used a Compose flag that does not exist and
   only worked against the development project.
3. Account setup was limited to five officers per minute per IP, which a
   training room on one address would hit. It is now limited per link, with
   a per-IP ceiling of 60.
4. Go-live details mixed UTC and local times.
5. The install test's certificate was shorter than the go-live minimum.


## Deviations and decisions

- **Demo generators in the production image.** The prompt asks for them to
  be absent from production images, but 9B.5 builds UAT from the same `prod`
  image, which needs them. I followed the existing code: the classes are in
  the image, but their commands are registered only in `local`, `uat` and
  `testing`. With `APP_ENV=production` they are not in the command list.
  `DevelopmentHierarchySeeder` (a seeder, not a command) refuses outside
  `local` and `uat`.
- **Reference data sign-off** is recorded as a go-live decision. The prompt
  lists it under data loading (9B.1.3) but names no place to record it. It
  is tied to the reference data version.
- **The UAT runner is scripted, not a browser.** Chrome refused the
  self-signed UAT certificate, and an HTTP runner is repeatable and reusable
  by the client. It exercises the same endpoints and checks the same
  outcomes, but not the visual layout. The client's testers still run the
  scripts by hand; the runner proves the environment and workflows first.
- **Setup and 2FA enrolment are allowed before a state goes live**, so
  officers can be onboarded during training. Signing in is not allowed.
- **Help cards are audited, not access-logged.** The audit event is
  `help_card_printed`; `record_access_logs` is not used because a card
  contains no records.
- **UAT feedback routes are registered locally** for the route helpers. They
  answer 404 outside UAT, and the frontend still uses fixed paths so that
  production builds work.

## Bugs found and fixed during 9B

- **Report PDF headers and footers** were broken under Chromium 154 (all
  report types; see 9B.4).
- **Idle timeout:** the 9A production settings template set
  `SESSION_LIFETIME=120`, against Phase 1C's 30-minute rule. It is now 30,
  and `buffalo:production:check` refuses more than 30.
- **Horizon** had no supervisors for any environment except production and
  local, so queued jobs and reports never ran in UAT. A `uat` environment
  was added.
- **Production frontend build:** importing route helpers of UAT-only routes
  broke `npm run build`. The frontend uses fixed paths, and a test guards
  against such imports.

## New packages

None. `league/commonmark` is already a Laravel dependency. The UAT runner
uses the Python standard library only.

## Known issues

- **The release signing key is the development TEST key.** The owner must
  create the real one (`scripts/ops/release-key.sh`) before the first
  production release.
- **No real place data yet.** The checklist used stand-in files in the
  owner's format; performance with the real 774 boundaries must be measured
  on the target server.
- **Release 1.0.0-rc1 (local test build) predates the checklist fixes.** The
  next release carries them; rc1 was never published.
- **Air-gapped ClamAV** needs the weekly offline import; the go-live check
  and System health warn after 7 days.
- **The UAT runner** checks behaviour, not visual layout; the client's
  testers still run the scripts by hand.
- **UAT on Docker Desktop:** the frontend no-egress rule must be re-applied
  after each Docker Desktop restart (Linux hosts use the systemd unit).
- **Carried over** (see `phase-9-summary.md`): no merging of duplicate
  records; scale tested on synthetic data only.

## Anything the next phase must know

- **Phase 10** (optional extensions) builds on a complete core. Any new
  route needs a policy and an inventory entry. Any new upload kind needs
  malware scanning. Any new environment must be added to Horizon
  (`config/horizon.php`) and considered in `Uat::productionLike()`.
- Every scheduled check that is expected to fail before go-live must not
  exit non-zero daily (the pattern is `--record`).
- After changing manuals, keep the contextual help sections valid: the help
  test checks every link.
