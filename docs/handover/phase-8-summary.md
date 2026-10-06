# Phase 8 summary — Security hardening, backup and recovery

Phase 8 hardened BUFFALO from the database to the browser, made its data
recoverable, and made its health visible. Details:
[`phase-8a.md`](phase-8a.md), [`phase-8b.md`](phase-8b.md),
[`phase-8c.md`](phase-8c.md). Operator documents are in
[`docs/security/`](../security).

## 1. Database roles, secrets and keys (8A)

- **Least-privilege database roles.** The application connects as
  `buffalo_app`: it owns nothing, cannot change schema, cannot disable
  triggers, cannot truncate, and may only `SELECT, INSERT` on the
  append-only audit tables. Migrations run as `buffalo_owner`; the
  PostgreSQL superuser only provisions roles, extensions and databases;
  `buffalo_readonly` has `SELECT` only. `DatabaseRolesTest` proves it.
- **Secrets** come from environment variables or files (`NAME_FILE`,
  Docker secrets). `buffalo:secrets:check` refuses missing, short or
  development values; production containers will not start otherwise.
  Valkey uses ACL users (default user off; the app user cannot FLUSHALL,
  CONFIG, KEYS, DEBUG and other admin commands).
- **Keys.** SHA-256 fingerprints of `APP_KEY` and `CIPHERSWEET_KEY` are
  stored (never the keys); production refuses to serve with keys that do
  not match. Rotation (`buffalo:keys:rotate-app-key`,
  `rotate-ciphersweet`, resumable) and loss (`retire`) procedures are in
  `key-management.md`. Every database backup records the key fingerprints
  it was taken with (8C).

## 2. Audit integrity (8A)

- Append-only audit tables (activity log, record access log, login
  history, analytics exports, search logs, match events, anchors) are
  **hash-chained** by a database trigger. `buffalo:audit:verify` (nightly)
  finds the first altered, inserted or deleted row; `buffalo:audit:anchor`
  records each chain's head daily for an external copy. Alterations alert
  super admins and the DG once until resolved (8C deduplication).
- Retention: nothing is deleted until the client sets periods
  (`retention.md`).

## 3. Application security controls (8B)

| Control | What it does |
|---|---|
| Content Security Policy | Nonce-based scripts on every page; strict headers (COOP, CORP, Permissions-Policy, HSTS on HTTPS); violation reporting; enforced and verified page by page. |
| Step-up authentication | A TOTP code from the last 15 minutes for secret downloads, large exports, classified evidence originals, access approvals, classified disseminations and sensitive user administration. |
| Export controls | Daily quotas per user (rows, reports, evidence downloads); unusual volume and repeated secret exports alert the DG and covering DDGs; `/audit/exports` review screen. |
| Malware scanning | ClamAV scans evidence, person photos, community CSVs and boundary files before acceptance; infected files rejected or quarantined; fails closed (evidence held in quarantine while ClamAV is down); offline signature import. |
| Safer schedules and mail | Report schedules pause on a posting change or deactivation; every mail subject is generic and mail bodies carry no references, places or classifications. |
| Route authorisation | All 312 routes inventoried (`route-inventory.md`); a test fails on any route without authentication or a detected authorisation check; a generated matrix tests every scoped route for all six roles inside and outside their jurisdiction. Found and closed: signature-only `storage/{path}` routes and Inertia DevTools serving page data without sign-in. |

## 4. Security scanning results

| Scan | Result (6 October 2026) |
|---|---|
| `composer audit` | No advisories. |
| `npm audit` (production and full) | 0 vulnerabilities after fixing `shell-quote` (critical, via `concurrently`) and `source-map-js` (high). |
| OWASP ZAP 2.17.0, authenticated (real login + TOTP), passive + limited active | **No high-risk alerts.** Fixed: Laravel's `/up` page loaded CDN fonts and scripts (air-gap breach). Accepted with reasons (`zap-accepted.md`): `style-src 'unsafe-inline'`, readable `XSRF-TOKEN` cookie, `report-uri`. |
| Trivy 0.75.0 | Our `prod` image and Valkey: **clean**. **Open:** fixable high/critical CVEs in the upstream Gotenberg (269, mostly Chromium 152), PostGIS (38), ClamAV (7) and nginx (2) images, with no newer tags. **Client decision needed** (below). |
| Container hardening check | 11/11 services pass; every container non-root with no capabilities and `no-new-privileges`. |
| Restore drill (CI and local) | Pass; the wrong-key negative test is refused as required. |

`pentest-guide.md` prepares an independent penetration test (scope,
accounts per role, focus areas).

## 5. Backup and recovery (8C)

| Data | How | Kept |
|---|---|---|
| PostgreSQL | pgBackRest: full weekly, differential daily, WAL archived continuously (≤ 5 min) | 4 full backups (~4 weeks of point-in-time recovery) |
| Evidence volume | restic nightly, deduplicated | 14 daily, 8 weekly, 12 monthly |
| Keys, secrets, backup passphrases | **Never with the data**; escrowed separately | — |
| Reports, map tiles, Valkey, quarantined malware | Not backed up (expiring classified copies, re-copyable, cache/queues, malware) | — |

- Both repositories are encrypted; an optional second repository (separate
  disk, NFS or removable media) receives every backup.
- **Targets:** RPO 15 minutes, RTO 4 hours (defaults, to be confirmed).
- **Measured restore times** (development: 450 MB database, Apple Silicon
  emulating the amd64 PostGIS image): database restore and WAL replay
  13–14 s, evidence 1 s, verification 1 s, **total 21–23 s**. Point-in-time
  recovery to one minute before a deletion recovered the record. A wrong
  `CIPHERSWEET_KEY` is refused before anything else is checked.
- `make restore-drill` restores into an isolated project and stores a
  report in `docs/drills/`; run it monthly. CI runs a small drill on every
  push.
- Post-restore verification checks keys, row counts, decryption and
  blind-index search, evidence hashes, audit chains and migrations.
- **Lost keys:** data encrypted with a lost `CIPHERSWEET_KEY` cannot be
  recovered by any backup; a lost backup passphrase makes that repository
  useless (`backup-and-recovery.md`).

## 6. Monitoring

- **System health** (`/system/health`, super admins): services, queues,
  scheduler, backups, disks, database size, audit chains and anchors, key
  fingerprints, TLS certificate, open alerts. No intelligence content.
- **Alerts** to super admins (critical ones also by mail), once per
  condition until it clears: backups failing, stale or behind; disks over
  80/90 %; failed jobs; queue waits; scheduler stopped (also watched from
  the queue); services down; old malware signatures; audit verification
  missing or failing (also the DG); TLS expiry.
- `/health/detailed`: token-protected JSON for external monitoring,
  internal networks only.

## 7. Container hardening (8C)

Every container: image pinned by digest, non-root from the first process,
**all Linux capabilities dropped and none added back**, `no-new-privileges`,
read-only root filesystem, memory and CPU limits (≈ 12.8 GB of 16 GB),
health checks. Networks: only nginx is published; the backend, tools and
backup networks have no route to the internet.

## 8. What the client must decide or provide before deployment (Phase 9)

**Decisions**

1. **Third-party image vulnerabilities (Trivy):** build patched derivative
   images, accept dated exceptions until upstream releases, or gate only
   BUFFALO's own image. Gotenberg's Chromium is the largest item.
2. **RPO and RTO:** confirm 15 minutes / 4 hours, or fund a standby
   database for shorter targets.
3. **Deployment target** (air-gapped, government network or cloud), which
   decides mail, signature updates and the second backup repository.
4. **Outbound mail:** whether BUFFALO sends mail at all; if so, the relay
   and an egress network for the queue workers.
5. **ClamAV signatures:** online updates (needs an egress route to the
   mirror) or the weekly offline import procedure.
6. **Retention periods** for audit, access and login records (currently
   kept indefinitely).
7. **Quarantined malware:** keep an offline forensic copy or not.
8. **Accepted ZAP findings:** the security officer signs off
   `zap-accepted.md`.
9. **Independent penetration test** before go-live (`pentest-guide.md`).

**To provide**

1. Production secrets as files: `APP_KEY`, `CIPHERSWEET_KEY`, database
   role passwords, Valkey passwords, Reverb key and secret,
   `BACKUP_PGBACKREST_PASSPHRASE`, `BACKUP_RESTIC_PASSWORD`, optional
   `BUFFALO_HEALTH_TOKEN`, mail credentials if used. Each container should
   receive only its own (8A note).
2. **Key and passphrase escrow:** two sealed copies in separate locations,
   custodians named, recovery tested at a drill.
3. **Second backup repository** location and its mount
   (`BACKUP_REPO2_PATH`).
4. **Server sizing:** at least 16 GB RAM (limits assume it); disk for the
   database, evidence (growing), backups (≥ 1.2 × evidence + 5 × the
   compressed database) and logs.
5. **TLS certificate** and its path (`BUFFALO_TLS_CERTIFICATE`) for expiry
   monitoring; HTTPS is required (history encryption, secure cookies).
6. **Reference data:** the owner-supplied CSVs and boundary GeoJSON
   (earlier phases).

**Phase 9 engineering (not decisions):** a production compose file using
the `prod` image with a `storage` volume (needed for read-only roots),
configuration baked into images instead of bind mounts (8A note), egress
networks as decided, per-container secret files, the one-time Valkey/
ClamAV volume ownership fix on any pre-8C installation, and a first
restore drill timed on the production server.
