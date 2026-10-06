# BUFFALO — Phase 8C: Backup, Recovery, Monitoring and Container Hardening

Read `CLAUDE.md` before starting (stack table: pgBackRest, restic). Read `docs/handover/phase-8a.md` and `docs/handover/phase-8b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

BUFFALO is now hardened at the database and application level. This step makes sure data survives hardware failure, mistakes and attacks, that operators can see the system's health, and that every container runs with the least privilege it needs. It completes Phase 8.

**Targets** (defaults; the client must confirm, see `docs/security/backup-and-recovery.md`):
- **RPO** (maximum data loss): 15 minutes.
- **RTO** (maximum time to restore service): 4 hours.

**What is backed up:**

| Data | Backed up | How |
|---|---|---|
| PostgreSQL (all application data, audit, sessions excluded) | Yes | pgBackRest: weekly full, daily differential, continuous WAL archiving |
| `evidence_data` volume | Yes | restic: nightly, encrypted, deduplicated |
| `reports_data` volume | **No** | Report files are classified copies that expire in 30 days and can be regenerated; backing them up would multiply classified copies. Run records are in the database. |
| `map_tiles` volume | No | Re-fetched or re-copied from the original file |
| Valkey | No | Cache, queues and sessions only; queued jobs at the moment of failure may need re-running (document) |
| Keys (`APP_KEY`, `CIPHERSWEET_KEY`) and secrets | **Never with the data** | Escrowed separately (8A key management procedure) |
| Configuration (compose files, nginx, env templates) | Yes | In git; production `.env`/secrets escrowed with keys |

---

## Step 8C.1 — Database backups (pgBackRest)

1. Configure PostgreSQL for WAL archiving (`archive_mode`, `archive_command` via pgBackRest) through a mounted config file; pgBackRest runs in the postgres container or a sidecar sharing its data volume (choose the cleaner option and report).
2. Repository 1: a local `backup_repo` volume. Repository 2: an optional second path (`BACKUP_REPO2_PATH`), intended for a separate disk, NFS mount or removable media, configured per deployment in Phase 9. Both encrypted (`repo-cipher-type=aes-256-cbc`) with a passphrase supplied as a secret and escrowed with the keys.
3. Schedule: full weekly (Sunday 01:00), differential daily (01:00), WAL continuous; retention 4 full backups (configurable). `pgbackrest check` daily; `pgbackrest verify` weekly.
4. Each backup records the active key fingerprints (8A) alongside it (in a small manifest file in the repo), so a restore can confirm it has the matching keys.
5. Alerts to super admins (`critical`) when: a backup fails, the newest backup is older than 26 hours, WAL archiving lags more than 15 minutes, or `check`/`verify` fails.

## Step 8C.2 — Evidence backups (restic)

1. A `backup` service (pinned restic image) that mounts `evidence_data` read-only and backs it up nightly (02:00) to a restic repository on `backup_repo` (and repository 2 if configured), with a password supplied as a secret and escrowed.
2. Retention: keep daily for 14 days, weekly for 8 weeks, monthly for 12 months (configurable). `restic check` weekly, `restic check --read-data-subset=5%` monthly.
3. Evidence files are never deleted by the application, so backups only grow; report expected growth.
4. Same alerting as 8C.1.

## Step 8C.3 — Restore and drills

1. **Scripts** (`scripts/restore/`), each with `--help` and dry-run where possible:
   - `restore-database.sh`: full restore, or point-in-time (`--target-time`), into a stopped stack or a fresh one.
   - `restore-evidence.sh`: full restore of the evidence volume, or of specific files by path.
   - `verify-restore.sh`: after restore, runs the checks below.
2. **Post-restore checks** (`buffalo:restore:verify`):
   - keys match the backup manifest fingerprints (refuse to continue if not);
   - row counts of key tables reported;
   - a sample of encrypted person fields decrypts, and blind-index searches return the sampled records;
   - a sample of evidence files matches their recorded SHA-256 hashes (and a full check with `--all-evidence`);
   - `buffalo:audit:verify` passes for all chains;
   - migrations are up to date.
3. **Restore drill:** `make restore-drill` restores the latest backups into an **isolated** Compose project (separate network and volumes, no published ports, mail disabled, scheduler and queue workers off), runs `verify-restore.sh`, prints a report with timings (to measure against the RTO), and tears down. Document a monthly drill in the runbook. A drill report is stored in `docs/drills/` (no data, only results and timings).
4. Test the drill in CI at small scale (seeded fixture data: back up, restore into a second project, verify).
5. Write `docs/security/backup-and-recovery.md`: what is backed up and why, RPO/RTO, schedules, retention, the restore procedures step by step for an operator (including point-in-time recovery to before a mistaken change), the drill procedure, and what to do if keys are lost (data encrypted with the lost key cannot be recovered; say so plainly).

## Step 8C.4 — System health and monitoring

A **System health** page for super admins (`system.settings`), showing no intelligence content:

- Services: app, nginx, PostgreSQL, Valkey, Horizon (status, queue sizes, failed jobs, longest wait per queue), scheduler (last run of each scheduled command), Reverb, Gotenberg, ClamAV (signature age).
- Backups: newest full/differential/WAL time, repository sizes, last `check`/`verify` results, last restore drill date and result.
- Storage: free space on each volume and the host; database size and largest tables.
- Audit chains: last `buffalo:audit:verify` result and the current anchors (8A).
- Key fingerprints in use (fingerprints only).
- Certificates: TLS certificate expiry when configured (Phase 9).

**Alerts** (to super admins, `warning` or `critical`, deduplicated, once per condition until it clears): backup conditions above; disk over 80%/90%; failed jobs above a threshold; a queue wait over 5 minutes; scheduler not run for 10 minutes; any service health check failing; ClamAV signatures over 7 days old; audit verification failure (also DG).

Expose a token-protected `/health/detailed` JSON endpoint (token from secrets, internal network only via nginx rule) for any external monitoring the client may use later. The public `/up` stays minimal.

## Step 8C.5 — Container hardening

Apply to every service in `compose.yml` (production-relevant settings in the base file; development relaxations only in `compose.override.yml`):

- Pin **every** image by digest as well as tag (Gotenberg, ClamAV, restic, pgBackRest image, nginx, Valkey, PostGIS, Node, PHP base).
- `security_opt: [no-new-privileges:true]`; `cap_drop: [ALL]`, adding back only what each service needs (document per service).
- `read_only: true` root filesystems where possible, with `tmpfs` for temporary paths; report services where this is not possible and why.
- Non-root users everywhere possible (app already; check nginx, Gotenberg, ClamAV, backup).
- Memory and CPU limits per service, with defaults suitable for a single server with 16 GB RAM, overridable by env.
- Health checks for every service, used by `depends_on`.
- Networks: `frontend` (nginx only, published), `backend` (app, workers, Reverb, Postgres, Valkey), `internal-tools` (Gotenberg, ClamAV, `internal: true`), `backup` (backup service, Postgres). Each service joins only what it needs.
- A test or script (`scripts/check-compose-hardening.sh`, run in CI) that fails if any service lacks a digest pin, `no-new-privileges`, `cap_drop`, a health check or limits.

## Step 8C.6 — Acceptance

- [ ] A full backup, a differential and continuous WAL archiving run on schedule; System health shows them.
- [ ] Point-in-time restore: delete a test record, then restore the database to one minute before the deletion in the drill environment, and find the record.
- [ ] Restoring with the wrong `CIPHERSWEET_KEY` is refused by the key fingerprint check.
- [ ] `make restore-drill` completes end to end and reports timings within the RTO.
- [ ] Evidence restore: a deleted evidence file is restored and matches its SHA-256.
- [ ] Filling a test volume past 90% triggers a critical alert to super admins.
- [ ] `scripts/check-compose-hardening.sh` passes; every container runs with dropped capabilities and `no-new-privileges`.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-8c.md` per CLAUDE.md section 9, and `docs/handover/phase-8-summary.md` summarising all of Phase 8: roles, secrets and keys, audit integrity, security controls, scanning results, backup and recovery design with measured restore times, container hardening, and everything the client must decide or provide before deployment (Phase 9). Print both.
