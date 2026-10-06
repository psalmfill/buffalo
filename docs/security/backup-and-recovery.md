# Backup and recovery

Phase 8C. For operators: what is backed up, how often, how long it is kept,
how to restore (in full or to a point in time), how to drill, and what
cannot be recovered.

## Targets

| Target | Default | Meaning |
|---|---|---|
| **RPO** (recovery point objective) | 15 minutes | Maximum data lost in a disaster. WAL is archived as each 16 MB segment fills and at least every 5 minutes (`archive_timeout`). |
| **RTO** (recovery time objective) | 4 hours | Maximum time to restore service. The drill measures restore time against it (`BUFFALO_RTO_HOURS`). |

**The client must confirm both** before go-live (Phase 9). Shorter targets
need a standby server (streaming replication), which BUFFALO does not set
up.

## What is backed up, and what is not

| Data | Backed up | How |
|---|---|---|
| PostgreSQL: all application data, audit trails, notifications | Yes | pgBackRest: weekly full, daily differential, continuous WAL archiving |
| `evidence_data` volume (evidence originals and previews, person photos) | Yes | restic: nightly, encrypted, deduplicated |
| `quarantine_data` volume (infected evidence originals, Phase 8B.4) | **No** | Malware; their records and hashes are in the database. Decide with the client whether to keep a separate, offline copy for forensics. |
| `reports_data` volume | **No** | Report files are classified copies that expire after 30 days and can be regenerated; backing them up would multiply classified copies. Run records are in the database. |
| `map_tiles` volume | No | Re-copied from the original PMTiles file (`make tiles`). |
| Valkey | No | Cache, queues and sessions only. After a restore everyone signs in again; jobs queued at the moment of failure are lost (see After a restore). |
| `APP_KEY`, `CIPHERSWEET_KEY`, all secrets, backup passphrases | **Never with the data** | Escrowed separately ([key-management.md](key-management.md)). |
| Configuration (compose files, nginx, env templates) | Yes | In git. The production `.env` and secret files are escrowed with the keys. |

Sessions are in Valkey, not the database, so no session data is restored.

## How it works

```
postgres ──archive_command──▶ pgBackRest ─┐
pgbackrest (sidecar) ── full/diff/check ──┼──▶ backup_repo volume ──(optional)──▶ repository 2
backup (restic) ── evidence, read-only ───┘    /backup/pgbackrest, /backup/restic, /backup/manifests
         └──────── status JSON ───────────▶ backup_status volume ──read-only──▶ app (System health, alerts)
```

- **`postgres`** runs `docker/postgres/Dockerfile`: the pinned PostGIS image
  plus pgBackRest. `docker/postgres/postgresql.conf` (mounted) sets
  `archive_mode = on` and `archive_command = 'pgbackrest --stanza=buffalo
  archive-push %p'`.
- **`pgbackrest`** (same image, user `postgres`) shares the data volume
  (read-only) and the Unix socket, runs the schedule, and writes status.
- **`backup`** (restic image, as the application user that owns the files)
  mounts the evidence volume read-only.
- Both repositories are encrypted: pgBackRest with `aes-256-cbc`
  (`BACKUP_PGBACKREST_PASSPHRASE`), restic with its own AES-256 encryption
  (`BACKUP_RESTIC_PASSWORD`). Supply both as files in production
  (`..._FILE`); development values are refused when `APP_ENV=production`.
- **Repository 2:** set `BACKUP_REPO2_ENABLED=true` and `BACKUP_REPO2_PATH`
  to a separate disk, NFS mount or removable media (Phase 9). Both services
  then write every backup to both repositories.
- **Key manifests:** after each database backup,
  `/backup/manifests/<label>.json` records the fingerprints of the active
  `APP_KEY` and `CIPHERSWEET_KEY` (fingerprints only, never keys). A restore
  refuses to continue unless the running keys match.

## Schedules and retention (Africa/Lagos time)

| What | When | Kept |
|---|---|---|
| Database full backup | Sunday 01:00 | 4 full backups and their differentials and WAL (`BACKUP_PG_RETENTION_FULL`) |
| Database differential | Monday to Saturday 01:00 | until its full backup expires |
| WAL archiving | continuous, at least every 5 minutes | from the oldest kept full backup |
| `pgbackrest check` | daily 03:30 | |
| `pgbackrest verify` | Saturday 04:00 | |
| Evidence backup (restic) | daily 02:00 | 14 daily, 8 weekly, 12 monthly snapshots (`BACKUP_EVIDENCE_KEEP_*`) |
| `restic check` | Sunday 05:00 | |
| `restic check --read-data-subset=5%` | 1st of the month 05:30 | |

So a database can be restored to any moment of roughly the last four
weeks; evidence to any night of the last two weeks, any week of the last
two months, any month of the last year.

Both services take their first backup as soon as they start on an empty
repository. To run a task now:

```sh
docker compose exec pgbackrest backup-entrypoint.sh pgbackrest-scheduler.sh now full   # or diff, check, verify, status
docker compose exec backup restic-scheduler.sh now backup                             # or forget, check, check-data, status
```

### Expected growth

Evidence is never deleted by the application, so the evidence backups only
grow. restic deduplicates, so each night adds roughly the size of that
day's new evidence (compressed where the files allow; photos and video
barely compress), and the retention policy only removes snapshots, not
files still present. Plan repository space as at least **1.2 × the
evidence volume**, growing with uploads (for example 2 GB of new evidence
a month adds about 24 GB a year). Database backups hold 4 full copies
(compressed with zstd, roughly 10–15% of the database size in development)
plus differentials and all WAL for four weeks; plan **5 × the compressed
database size**. System health shows both repositories' sizes.

## Monitoring

`buffalo:health:monitor` (every minute) reads the status files and raises a
**critical** alert to super admins (in-app, and by mail with a generic
subject when mail is configured), once per condition until it clears, when:

- a backup, `check` or `verify` fails;
- the newest database or evidence backup is older than 26 hours
  (`BUFFALO_BACKUP_MAX_AGE_HOURS`);
- WAL archiving lags more than 15 minutes (`BUFFALO_BACKUP_WAL_LAG_MINUTES`)
  or is failing;
- a backup service has stopped reporting, or the status volume is missing.

## Restoring

All scripts are in `scripts/restore/` and accept `--help`. They take the
backup passphrases from the environment or `.env` (or their `_FILE`
variants) and never print them. Every restore ends with verification.

### Before you start

1. Have the escrowed **`APP_KEY` and `CIPHERSWEET_KEY` of the backup** in
   the `.env` or secret files of the stack you restore into, and the backup
   passphrases. Without the keys the restore is refused (see Lost keys).
2. Decide the target: the latest state, or a point in time.
3. Tell users the system is going down.

### Full restore of the database (latest state)

Into the existing stack (for example after disk failure of the database
volume, once the volume is recreated):

```sh
scripts/restore/restore-database.sh --dry-run        # lists the backups; changes nothing
scripts/restore/restore-database.sh                  # stops the app and the database, restores, starts postgres
scripts/restore/verify-restore.sh                    # must report "The restore verifies."
docker compose up -d                                 # start everything else
```

Into a fresh server: install the stack (Phase 9 runbook) without starting
it, copy or mount the backup repository (from repository 2 if repository 1
was lost) into the `backup_repo` volume, then run the same commands.

`restore-database.sh` uses pgBackRest `--delta` (only changed files are
rewritten) and replays all archived WAL; PostgreSQL opens for writes when
replay is done. Archiving then continues on a new timeline in the same
repository.

### Point-in-time recovery (undo a mistaken change)

Example: a large mistaken change was made at 14:32.

1. **Practise first** in the drill environment (it never touches
   production):

   ```sh
   # Make sure the WAL covering the mistake is archived (or wait 5 minutes):
   docker compose exec postgres psql -U "$DB_ADMIN_USERNAME" -d buffalo -c 'SELECT pg_switch_wal()'
   scripts/restore/drill.sh --target-time "2026-10-06 14:31:00+01" --keep
   docker compose -p buffalo-drill -f compose.yml -f docker/drill/compose.drill.yml \
       exec postgres psql -U "$DB_ADMIN_USERNAME" -d buffalo   # look at the data as it was at 14:31
   scripts/restore/drill.sh --teardown
   ```

   `pg_switch_wal()` must be run on its own (not in the same statement as a
   change): recovery needs the WAL that holds the first commit after the
   target time.

2. **Choose:** either restore production to that moment (everything after
   it is lost, including correct work), or copy the needed rows out of the
   drill database and put them back in production through the application
   (keeps later work; usually better for a single mistake). Decide with the
   Director General and record the decision.

3. To restore production to that moment:

   ```sh
   scripts/restore/restore-database.sh --target-time "2026-10-06 14:31:00+01"
   scripts/restore/verify-restore.sh
   docker compose up -d
   ```

Times are written with their offset (`+01` is West Africa Time).

### Evidence

```sh
scripts/restore/restore-evidence.sh --list                           # snapshots
scripts/restore/restore-evidence.sh                                  # everything, latest snapshot
scripts/restore/restore-evidence.sh --path original/01m3.../original.jpg --dry-run
scripts/restore/restore-evidence.sh --path original/01m3.../original.jpg
scripts/restore/verify-restore.sh --all-evidence                     # every file against its SHA-256
```

Paths are relative to the evidence volume: `original/<media ULID>/original.<ext>`
for evidence originals (previews under `conversions/` beside them) and
`photos/<media ULID>/photo.<ext>` for person photos. The media ULID is in
the `media` table (`model_type`, `model_id` of the evidence item). Restored
files are byte-for-byte the originals; the SHA-256 recorded at upload
proves it.

### Verification (`buffalo:restore:verify`)

`verify-restore.sh` runs, in the restored stack:

1. **keys** — the running keys match the backup manifest and the restored
   database; otherwise it stops;
2. row counts of key tables;
3. a sample of persons: encrypted NIN, phone and date of birth decrypt, and
   blind-index searches find the same persons;
4. evidence originals match their SHA-256 (a sample, or all with
   `--all-evidence`); items whose integrity check had already failed before
   the backup are listed separately;
5. `buffalo:audit:verify`: every audit chain is intact;
6. no migration is pending.

It prints counts and results only, never decrypted values or names.

### After a restore

- Everyone signs in again (sessions were in Valkey).
- Jobs queued at the moment of failure are lost. Check Horizon's failed jobs
  and ask officers to regenerate any report they were waiting for. Report
  schedules and other scheduled work resume on their own.
- Evidence uploaded after the last evidence backup but before the failure
  has a database record (if the database restore reaches past the upload)
  but no file: `verify-restore.sh --all-evidence` lists them. Ask the
  uploaders to upload them again from their source.
- Run `buffalo:keys:verify` (production startup does this) and look at
  System health.

## Restore drill

`make restore-drill` (`scripts/restore/drill.sh`) restores the latest
backups into an **isolated** Compose project, `buffalo-drill`: its own
networks and volumes, no published ports, mail, broadcasts and queues off,
no scheduler, workers, Reverb or nginx. The production backup repository is
mounted read-only, and the restored database runs with archiving off, so a
drill cannot write to it. It verifies the restore, prints and stores a
report in `docs/drills/` (results and timings only, no data), records the
result for System health, and removes the drill project.

Options: `--target-time` (point in time), `--wrong-keys` (negative test:
must be refused), `--all-evidence`, `--keep` / `--teardown`.

**Do a drill every month**, and after any change to the backup set-up.
Compare the total time with the RTO, and keep the report. CI runs a small
drill on every push (job `restore-drill`): fixture data, backup, restore,
verify, and the wrong-key refusal.

Development measurements (6 October 2026; 450 MB database, 0.5 MB
evidence, Apple Silicon running the amd64 PostGIS image under emulation):
database restore and WAL replay 13–14 s, evidence 1 s, verification 1 s,
total about 21 s. A production-sized database restores at disk speed; time
it in the first drill on the production server.

## Lost keys and passphrases

Say this plainly to the client:

- **Data encrypted with a lost `CIPHERSWEET_KEY` cannot be recovered.**
  Persons' NIN, phone numbers, addresses and dates of birth, and
  organization contacts' phone and email, are unreadable without it. No
  backup helps: the backups hold the same ciphertext.
- A lost `APP_KEY` loses encrypted settings, two-factor secrets (everyone
  re-enrols) and signed links; see [key-management.md](key-management.md)
  (`buffalo:keys:retire`).
- **A lost backup passphrase makes that repository useless.** pgBackRest
  and restic repositories cannot be opened without it.

Keys and passphrases are escrowed separately from the backups (sealed, two
locations, per [key-management.md](key-management.md)). Check the escrow at
every drill: the drill uses the same keys, so a drill that verifies proves
the escrowed keys are the right ones only if you restore with the escrowed
copies.
