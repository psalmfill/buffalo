# Phase 8C handover — Backup, recovery, monitoring and container hardening

Prompt: [`docs/prompts/phase-8c.md`](../prompts/phase-8c.md). Completes
Phase 8 (see also [`phase-8-summary.md`](phase-8-summary.md)).

## What was built

### 8C.1 Database backups — pgBackRest (`5a15f41`)

- **Choice (reported as the prompt asks):** pgBackRest is installed *in*
  the PostgreSQL image (`docker/postgres/Dockerfile`: the pinned PostGIS
  image plus Alpine's `pgbackrest 2.58.0`), because WAL `archive-push` must
  run where PostgreSQL runs. Scheduled work runs in a **sidecar**
  (`pgbackrest`) using the same image, sharing the data volume (read-only)
  and the Unix socket (`postgres_run` volume). No SSH or TLS server inside
  the database container, one pgBackRest version everywhere.
- `docker/postgres/postgresql.conf` (mounted, `-c config_file=`) keeps
  initdb's settings and adds `archive_mode = on`, `archive_command =
  'pgbackrest --stanza=buffalo archive-push %p'`, `archive_timeout = 300`
  (RPO).
- `docker/postgres/pgbackrest.conf`: repository 1 `/backup/pgbackrest`
  (`backup_repo` volume), `aes-256-cbc`, zstd, `start-fast`; repository 2
  added through the environment when `BACKUP_REPO2_ENABLED=true`
  (`BACKUP_REPO2_PATH` mounted at `/backup-repo2`).
- `backup-entrypoint.sh`: passphrase from `BACKUP_PGBACKREST_PASSPHRASE` or
  `_FILE`, at least 24 characters, the development value refused when
  `APP_ENV=production`; retention `BACKUP_PG_RETENTION_FULL` (4).
- `pgbackrest-scheduler.sh` (Africa/Lagos): stanza-create and a first full
  backup on start; full Sunday 01:00, differential the other days 01:00
  (`BACKUP_PG_TIME`, `BACKUP_PG_FULL_DAY`, fix `4d6351d`); `check` daily
  03:30; `verify` Saturday 04:00; WAL state every 20 s. On demand:
  `docker compose exec pgbackrest backup-entrypoint.sh pgbackrest-scheduler.sh now full|diff|check|verify|status`.
- After each backup `/backup/manifests/<label>.json` records the active key
  fingerprints (8A).
- Status JSON on the `backup_status` volume (read-only in the app): task
  results, `pgbackrest info`, WAL (archiver stats, age of the oldest
  segment waiting), repository sizes and free disk.
- **Alerts** (critical, super admins, deduplicated): a backup/check/verify
  failed; the newest backup is older than 26 hours; WAL waits more than 15
  minutes or archiving fails; the sidecar stops reporting; the status volume
  is missing (`App\Support\Backups\BackupChecks`, `BUFFALO_BACKUP_*`).

### 8C.2 Evidence backups — restic (`c2e4d35`)

- `backup` service: `restic/restic:0.19.1` pinned by digest, as the
  application user (owner of the 0600 evidence files), evidence volume
  read-only. `docker/backup/restic-scheduler.sh`: nightly 02:00
  (`BACKUP_EVIDENCE_TIME`), then `forget --prune` keeping 14 daily, 8
  weekly, 12 monthly (`BACKUP_EVIDENCE_KEEP_*`); `restic check` Sunday
  05:00; `check --read-data-subset=5%` on the 1st at 05:30; first backup on
  start; repository 2 when enabled. Password `BACKUP_RESTIC_PASSWORD(_FILE)`
  with the same rules. Status: heartbeat, task results, snapshot list (paths
  and times, no file names), sizes. Same alerts as 8C.1.
- **Expected growth:** evidence is never deleted, so the repository grows
  with uploads, about the size of new evidence each day after
  deduplication (photos and video barely compress). Plan at least 1.2 × the
  evidence volume; database backups about 5 × the compressed database size
  (details in `backup-and-recovery.md`).

### 8C.3 Restore and drills (`42b0d46`)

- `scripts/restore/` (all with `--help`): `restore-database.sh` (latest or
  `--target-time`, `--set`, into a stopped or fresh project, `--dry-run`),
  `restore-evidence.sh` (all or `--path`, `--list`, `--dry-run`, read-only
  repository with `--no-lock`), `verify-restore.sh`, `drill.sh`,
  `ci-drill.sh`, `common.sh`.
- `buffalo:restore:verify`: refuses unless the running keys match the
  manifest and the restored database; row counts; persons' encrypted fields
  decrypt and blind-index searches find them; evidence SHA-256 (sample or
  `--all-evidence`; failures already reported before the backup are listed
  separately); audit chains; pending migrations; `--json`. Prints no
  decrypted values or names.
- `make restore-drill`: isolated project `buffalo-drill` (own networks and
  volumes, no published ports, mail/broadcast/queue off, only postgres,
  valkey, app), **source repository mounted read-only and archiving off**,
  verify, report in `docs/drills/` (results and timings only), status for
  System health, teardown. `--target-time`, `--wrong-keys`, `--keep`,
  `--teardown`, `--all-evidence`.
- CI job `restore-drill`: fresh stack, fixture data, backups, drill, and
  the wrong-key refusal.
- `docs/security/backup-and-recovery.md`: what is (not) backed up and why,
  RPO/RTO, schedules, retention, step-by-step restores including
  point-in-time recovery, the drill, after-restore steps, lost keys stated
  plainly.

### 8C.4 System health and monitoring (`d978738`)

- `/system/health` (gate `viewSystemHealth` = `system.settings`, super
  admins; navigation "System health"): services (app, PostgreSQL, Valkey,
  Horizon, Reverb, Gotenberg, ClamAV with signature age, nginx), queues
  (size, longest wait, failed jobs), last run of each scheduled command,
  database and evidence backups (newest full/diff, WAL, check/verify,
  repository sizes, last drill), disks (each volume and the backup
  repository), database size and largest tables, audit verification and
  current anchors, key fingerprints, TLS certificate expiry, open alerts.
  No intelligence content.
- `buffalo:health:monitor` (every minute) runs tagged checks
  (`BackupChecks`, `StackChecks`, `SchedulerCheck`) through
  `SystemAlerts`: one row per open condition in `system_alerts`, notified
  when raised (or escalated) and when cleared (`SystemAlert`; critical ones
  also by mail with the generic subject). Conditions: backups (above);
  failing service probes; disks over 80 % (warning) / 90 % (critical);
  more than 10 failed jobs in 24 h; queue waits over 5 minutes; scheduler
  silent for 10 minutes (also checked by `SchedulerWatchdogJob` on the
  queue, so a dead scheduler is still reported); ClamAV signatures over 7
  days; audit verification not run for 26 h; TLS expiry within 30 / 7 days.
- `health_status` table: last run of every scheduled command (event
  listeners) and the last full audit verification.
- 8A's audit chain alerts and 8B's ClamAV alerts now go through the same
  deduplication (`AuditChainBroken` kept as the notification;
  `MalwareScannerAlert` removed, the daily schedule replaced by the monitor).
- `/health/detailed`: JSON report, `Authorization: Bearer
  BUFFALO_HEALTH_TOKEN` (new optional secret, 32+ characters), 404 when no
  token is configured, outside the web group (no session), and allowed by
  nginx only from loopback and private ranges.

### 8C.5 Container hardening (`4570949`)

- Every image pinned by tag **and digest**: nginx, Valkey, Gotenberg,
  ClamAV, restic, PostGIS (built image), Mailpit and Node (dev), and the
  Dockerfile bases (composer, node, php).
- All 11 services: `no-new-privileges`, `cap_drop: [ALL]` with **no
  capability added back** (every service is non-root from its first
  process), read-only root with tmpfs, memory/CPU limits for 16 GB
  (`BUFFALO_MEM_*`, `BUFFALO_CPUS_*`; total ≈ 12.8 GB), health checks (new
  for nginx, scheduler, reverb) used by `depends_on`.
- Non-root: nginx as `nginx` on **port 8080** (own `nginx.conf`, temp
  paths in /tmp); postgres/pgbackrest as `postgres`; Valkey as `valkey`;
  ClamAV via `/init-unprivileged` with mounted `clamd.conf` /
  `freshclam.conf`; Gotenberg as `gotenberg`; restic as the app user.
- Networks: `frontend` (nginx, published), `backend`, `internal-tools`,
  `backup` (last three `internal: true`). Dev-only relaxations in
  `compose.override.yml` (source mounts, 127.0.0.1 ports, `default`
  network for Mailpit/Xdebug/forwarded ports).
- `scripts/check-compose-hardening.sh` (static; `--running` inspects uid,
  `CapEff`, `NoNewPrivs`) in `make ci` and the GitHub `security` job.
- `docs/security/container-hardening.md` with the per-service table and
  the one-time volume ownership fix for existing installations.

## Acceptance checklist

| Item | Result |
|---|---|
| Full, differential and continuous WAL archiving run on schedule; System health shows them | **Pass.** With the schedule set two minutes ahead (`BACKUP_PG_TIME`, `BACKUP_PG_FULL_DAY`, `BACKUP_EVIDENCE_TIME`), the scheduled full database backup (8 s) and evidence backup ran at 21:50; differentials ran on demand; WAL archived continuously (`pg_stat_archiver`, 0 failures). System health (browser, super admin) showed the newest full and differential, WAL time, last backup, check, sizes, snapshots and the last drill. Schedule restored to 01:00/02:00. |
| Point-in-time restore: delete a record, restore to one minute before, find it | **Pass.** Created "PITR Test Community" (20:38:25), deleted it (20:39:49), drill with `--target-time "2026-10-06 20:38:49+01"`: recovery stopped "before commit of transaction 791995" (the deletion), new timeline, record present. Learned: the WAL holding the first commit after the target must be archived (`pg_switch_wal()` on its own, or wait 5 minutes) — documented. |
| Wrong `CIPHERSWEET_KEY` refused | **Pass.** `drill.sh --wrong-keys`: "the running ciphersweet_key does not match the backup. Refusing to continue". Also tested in `RestoreVerifyTest` and CI. |
| `make restore-drill` end to end within the RTO | **Pass.** 21–23 s total (database 13–14 s, evidence 1 s, verify 1 s) against 4 hours; reports in `docs/drills/`; also passes after container hardening. |
| Evidence restore: deleted file restored, SHA-256 matches | **Pass.** Deleted an evidence original; `verify-restore.sh --all-evidence` reported "1 missing or altered"; `restore-evidence.sh --path …` restored it; SHA-256 `2bfe7ed7…` equals the recorded hash; verify then "0 missing or altered". |
| Filling a test volume past 90% → critical alert to super admins | **Pass.** A 20 MB tmpfs registered with `BUFFALO_HEALTH_EXTRA_DISKS`, filled to 95%: critical "Disk almost full" to both super admins (in-app and mail "BUFFALO notification") within 20 s; cleared a minute after emptying. |
| `check-compose-hardening.sh` passes; every container has dropped capabilities and no-new-privileges | **Pass.** 11/11 services; `--running`: every container non-root, `CapEff: 0000000000000000`, `NoNewPrivs: 1`, read-only root. |
| `make test`, `make lint`, `make ci` | **Pass** (below). |

## Files

### Created

- `docker/postgres/{Dockerfile,postgresql.conf,pgbackrest.conf,backup-entrypoint.sh,pgbackrest-scheduler.sh}`,
  `docker/backup/restic-scheduler.sh`, `docker/drill/{compose.drill.yml,compose.drill-dev.yml}`,
  `docker/nginx/nginx.conf`, `docker/clamav/{clamd.conf,freshclam.conf}`
- `scripts/restore/{common,restore-database,restore-evidence,verify-restore,drill,ci-drill}.sh`,
  `scripts/check-compose-hardening.sh`
- `app/Support/Backups/{BackupStatus,BackupChecks}.php`;
  `app/Support/Health/{Condition,HealthCheck,SystemAlerts,HealthStatus,ServiceProbe,Checks,StackChecks,SchedulerCheck,SystemHealthReport}.php`
- `app/Console/Commands/{HealthMonitorCommand,VerifyRestoreCommand,SchedulerAliveCommand}.php`,
  `app/Jobs/SchedulerWatchdogJob.php`, `app/Notifications/SystemAlert.php`
- `app/Http/Controllers/System/SystemHealthController.php`,
  `app/Http/Controllers/HealthDetailedController.php`, `routes/system.php`,
  `resources/js/pages/system/health.tsx`
- Migrations `2026_10_20_000001_create_system_alerts_table`,
  `2026_10_20_000002_create_health_status_table`
- Tests: `tests/Feature/Health/{BackupMonitoringTest,RestoreVerifyTest,SystemHealthTest}.php`,
  `tests/Support/Health/FakeServiceProbe.php`
- Docs: `docs/security/{backup-and-recovery,container-hardening}.md`,
  `docs/drills/*.md`

### Changed

- `compose.yml` (rewritten: hardening, backups, networks),
  `compose.override.yml`, `docker/php/Dockerfile` (digests),
  `docker/nginx/default.conf` (8080, `/nginx-ping`, `/health/detailed`
  rule), `docker/clamav/entrypoint.sh` (unprivileged init),
  `scripts/clamav-import-signatures.sh`, `scripts/security-scan.sh` and
  `scripts/zap/session.php` (nginx:8080, `frontend` network), `Makefile`
  (`restore-drill`, hardening check in `ci`, CI networks),
  `.github/workflows/ci.yml` (`restore-drill` job, hardening step),
  `.env.example`
- `bootstrap/app.php` (`/health/detailed`), `routes/{web,console}.php`,
  `config/buffalo.php` (`backups`, `health`), `AppServiceProvider` (checks,
  gate, schedule tracking), `Secrets`/`CheckSecrets`
  (`BUFFALO_HEALTH_TOKEN`), `RouteInventory`, `VerifyAuditChains`,
  `CheckMalwareSignatures`; navigation and its test
- Tests: `TestCase` (fake service probe), malware, mail, report
  infrastructure tests
- `README.md`

## Deviations and decisions

- **pgBackRest placement:** in the PostgreSQL image plus a sidecar on the
  same image (above). There is no official pgBackRest image; the package
  comes from the same Alpine release as the pinned PostGIS image and is
  pinned to `2.58.0-r0`.
- **One `backup_repo` volume** as asked, with per-writer subdirectories
  (`pgbackrest/` owned by postgres, `restic/` by the app user) seeded from
  the image on first mount.
- **The `backup` network** joins postgres and both backup services as
  specified, although pgBackRest talks to PostgreSQL over the shared socket
  and restic needs no network.
- **`backend` is internal** (no gateway). Outbound mail to a relay and
  online ClamAV updates need an egress network added per deployment
  (Phase 9). Not in the prompt; the air-gapped default.
- **nginx on 8080** inside the container (non-root, no capabilities); the
  dev port is unchanged.
- **Application read-only root:** works in development thanks to the source
  mount; the production compose (Phase 9) must mount a `storage` volume.
- **Restore verification** separates evidence whose integrity failure was
  already reported before the backup (the Phase 5B tampered test file in
  development) from new mismatches.
- **8A/8B alerts** moved into the deduplicated system (see 8C.4).
- **`/up`** stays minimal (8B's HealthController).

## New packages and images

- No new Composer or npm packages.
- Images: `restic/restic:0.19.1` (BSD-2-Clause); Alpine package
  `pgbackrest 2.58.0` (MIT) in the PostgreSQL image. Both named in
  CLAUDE.md's stack table.

## Known issues and decisions for the client

1. **Trivy (from 8B) still open:** fixable high/critical CVEs in the
   Gotenberg, PostGIS, ClamAV and nginx images, no newer upstream tags;
   options in `phase-8b.md`. Digests are now pinned, so a fix means a
   deliberate re-pin.
2. **RPO/RTO** (15 min / 4 h) are defaults; the client must confirm them.
   A shorter RPO needs streaming replication (not built).
3. **Repository 2** location (separate disk, NFS, removable media) and the
   escrow of `BACKUP_PGBACKREST_PASSPHRASE` / `BACKUP_RESTIC_PASSWORD` are
   Phase 9 decisions.
4. **Quarantined malware** is not backed up (decide whether to keep an
   offline forensic copy).
5. **Existing installations** need a one-time `chown` of the Valkey and
   ClamAV volumes (`container-hardening.md`).
6. **Development leftovers:** the 8B ZAP test account and the PITR test are
   in the dev database only; `docs/drills/` holds the development drill
   reports (results and timings only).
7. pgBackRest logs a harmless warning on restore ("unknown group 'root' in
   backup manifest") for a root-owned file in the PostgreSQL volume root.
8. Tinker in the read-only app containers cannot write its config
   directory (a harmless notice); use `HOME=/tmp` when needed.

## make test / make lint / make ci

- `make ci`: **all CI checks passed**. Container hardening check (11/11),
  `composer audit` (no advisories), `npm audit --omit=dev` (0), route
  inventory up to date, Pint, Larastan level 7, ESLint, Prettier, tsc,
  Vitest (14), secrets check, Pest **1494 passed (12,262 assertions)**,
  including the real-ClamAV client tests (required in CI).
- `make lint`: clean. `make test`: the full run found three issues (a
  time-dependent test dataset, a forbidden scope bypass in
  `buffalo:restore:verify`, an outdated 7D network assertion), fixed in
  `4570949`; `make ci` above is the complete green run.
- `make ci` change: the CI container now joins its own `buffalo_ci`
  network for Composer and npm downloads, because the stack's networks no
  longer have a gateway.
