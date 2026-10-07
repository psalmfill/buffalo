# Phase 9A handover: production packaging, deployment profiles and releases

## What was built

### 9A.1 Production compose, profiles, TLS (commit 8bbf763)

- `compose.prod.yml` is a production overlay on `compose.yml`, used instead of a `compose.override.yml`:
  - Release images only: `pull_policy: never`, builds reset, no source mounts, configuration baked into the images.
  - Laravel storage on an `app_storage` volume, so root filesystems stay read-only.
  - Application logs go to stderr.
  - Host ports and bind address are settings.
  - A one-off `tools` service, the only place besides `postgres` that holds the owner and superuser database passwords, used for role provisioning, migrations and seeding.
  - PostgreSQL memory settings (`BUFFALO_PG_*`) for the container's limit. Before this, `shared_buffers` was the 128 MB default.
- Profiles:
  - `compose.profile-airgapped.yml`: no egress network.
  - `compose.profile-connected.yml` and `compose.profile-cloud.yml`: an `egress` network joined only by nginx, for OCSP stapling.
- Opt-in overlays, each giving egress (`gw_priority` 100, so the default route goes through `egress`):
  - `compose.egress-mail.yml`: horizon.
  - `compose.egress-clamav.yml`: clamav, online signatures.
  - `compose.acme.yml`: certbot v5.8.0 by digest, HTTP-01, UID 101, renews every 12 h. nginx reloads within 15 minutes of a certificate change.
- Frontend network:
  - Masquerading is off and the bridge has a fixed name (`buffalo-front`).
  - `scripts/ops/egress-firewall.sh` adds a `DOCKER-USER` rule dropping new outbound connections from that bridge.
  - Docker cannot publish ports from a gateway-less (`internal`) network. I tested this: `isolated` gateway mode is only allowed on internal networks, and those refuse published ports. So the host rule is required.
  - `install.sh` applies the rule and installs `buffalo-egress-firewall.service`, which re-applies it whenever `docker.service` starts.
- TLS in nginx (container port 8443, published as 443):
  - TLS 1.2 and 1.3 only, Mozilla intermediate AEAD ciphers with forward secrecy, X25519/P-256/P-384, HTTP/2, no session tickets.
  - Port 80 redirects to HTTPS, except `/.well-known/acme-challenge/`.
  - `ssl_stapling` only where egress exists (`NGINX_OCSP_STAPLING`).
- `buffalo:production:check` runs at boot, before `buffalo:secrets:check` and `buffalo:keys:verify`. It refuses to start unless:
  - `APP_ENV` is production and `APP_DEBUG` is false
  - `APP_URL` uses https
  - session cookies are Secure, HttpOnly and SameSite lax or strict
  - the CSP is enforced, not report-only
  - Horizon is restricted and Inertia DevTools are off

  It prints setting names only, never values.
- Reverb configuration is read at runtime: the public key from a `<meta>` tag, host, port and scheme from the page. Before this, the production build baked empty `VITE_REVERB_*` values and real-time updates would not have connected.
- Password-reset mail is queued, because only workers reach the mail relay. Reverb's Valkey password is read through `Secrets`.
- New files: `deploy/buffalo.env.example`, `scripts/ops/generate-secrets.sh` (never prints or overwrites; files 0444 in a 0700 directory), `scripts/ops/fix-volume-ownership.sh`, `scripts/build-release-images.sh`.
- `scripts/check-compose-hardening.sh` now checks three combinations:
  - the base compose
  - production with every overlay and `tools`
  - air-gapped production

### 9A.2 Patched images (commits e2184f9, 012f87a)

`docker/patched/` holds PostGIS, nginx, ClamAV and Gotenberg rebuilt with current OS packages. Upstream is pinned by digest. PostGIS also has `gosu` removed. BUFFALO's configuration images (`buffalo/postgres`, `nginx`, `clamav`, `valkey`, `restic`) build on top, with configuration baked in:

- `make images` builds them.
- `.github/workflows/images.yml` rebuilds and scans weekly and pushes to `vars.BUFFALO_REGISTRY` when set.
- `security/vulnerability-exceptions.yaml` lists accepted findings in Trivy's ignore format. `scripts/security/check-exceptions.py` (run by `make ci` and the scan) fails when a statement is missing, a date has passed, or a review date is more than 60 days away.

Trivy, linux/amd64, HIGH and CRITICAL (total, with the fixable count in brackets):

| Image | Upstream | BUFFALO patched | Remaining |
|---|---|---|---|
| PostGIS 18 | 38 (38) | 0 | — |
| nginx 1.30.5 | 2 (2) | 0 | — |
| ClamAV | 63 (7) | 56 (0) | Unfixed upstream in Debian; not gated (`--ignore-unfixed`) |
| Gotenberg 8.37 | 350 (269) | 83 (2) | 2 fixable in pdfcpu's Go dependencies (CVE-2026-56854, CVE-2026-46603): excepted until 2026-12-05 |
| restic 0.19.1 | OS packages fixable | 0 OS | 13 findings in the restic Go binary: excepted until 2026-12-05 |
| Valkey | 0 | 0 | — |

The gate (`scripts/security-scan.sh trivy`, `--ignore-unfixed` with the exceptions file) passes. There are 15 exceptions in total, all to be reviewed by 2026-12-05.

### 9A.3 Signed releases

`make release VERSION=x.y.z RELEASE_KEY=/path/outside/repo [SINCE=ref]` runs `scripts/release.sh`. It refuses a key inside the repository, and it refuses to build if `install.sh` embeds a different key from `security/release-signing.pub`. Steps:

1. Builds all images for linux/amd64 without cache (`REUSE_IMAGES=1` skips the build).
2. Writes `images.lock`, with image IDs and platforms.
3. Saves `buffalo-x.y.z-images.tar.zst`.
4. Writes `RELEASE-NOTES.md` from Conventional Commit subjects and new migrations since the previous tag.
5. Packs `buffalo-x.y.z-bundle.tar.zst`: compose files, profiles, overlays, `scripts/ops`, `scripts/restore`, ClamAV import script, `docs/operations`, `docs/security`, env template, drill overlay, public key, `VERSION`, `images.lock`, notes.
6. Writes `SHA256SUMS` over the archives, `install.sh` and the notes.
7. Signs it with `ssh-keygen -Y sign -n buffalo-release`.
8. Verifies the signature against the committed public key.

Registry installs pull each image and compare its ID with the signed `images.lock`, refusing any mismatch.

### 9A.4 Install, upgrade, rollback

All in POSIX sh, each with `--help` and `--dry-run`, stopping at the first error.

`scripts/ops/install.sh --profile airgapped|connected|cloud`:

1. Verifies the signature against the embedded key and checks every checksum.
2. Refuses a TEST-key release without `--allow-test-signature`.
3. Shows the key fingerprint for confirmation.
4. Checks prerequisites:
   - Docker 28+ and Compose 2.33.1+ (needed for `gw_priority`)
   - RAM of at least 16 GB (`--accept-low-memory` for test systems)
   - free disk space (warning only)
   - NTP synchronisation (refused if not synchronised)
5. Unpacks to `/opt/buffalo/releases/<v>` and points `/opt/buffalo/current` at it.
6. Loads, or pulls, the images and verifies them against `images.lock`.
7. Creates the settings file from the template if missing, then stops.
8. Records `COMPOSE_FILE`, version, profile and paths in the settings file.
9. Checks that every secret file exists (names only).
10. Starts postgres and valkey, then runs `buffalo:secrets:check`, provisions roles, migrates as the owner role, and seeds roles and permissions, zones and states, and reference lists (no demo data).
11. Prepares the placeholder certificate for ACME, applies the firewall rule, starts everything with `--wait`, and prints next steps.

`scripts/ops/upgrade.sh <new release dir>`, run from the installed release:

1. Verifies the new release with the installed release's public key, so trust carries forward (rotation is described in `releases.md`).
2. Refuses an older or identical version.
3. Shows the release notes and asks for the typed phrase `upgrade to x.y.z`.
4. Takes a full pgBackRest backup (recording the set label) and a restic snapshot.
5. Shows the maintenance page (a flag file on the `maintenance` volume; nginx serves a static 503).
6. Stops horizon and the scheduler with a timeout (`--worker-timeout`, default 300 s), then app and reverb.
7. Unpacks, loads and verifies the images, switches `current`, and records `BUFFALO_PREVIOUS_VERSION`.
8. Starts the new postgres and valkey, provisions roles, migrates, and reseeds (the seeders are idempotent).
9. Starts everything and runs smoke checks: no pending migrations, `buffalo:production:check`, `buffalo:keys:verify`, `/up` through PHP-FPM answering `up` (database and Valkey), nginx answering HTTPS with the 503.
10. Leaves maintenance mode.

On failure, it says which stage failed and whether the maintenance page is on. It prints the exact rollback commands, including the backup set label. It never rolls the database back.

`docs/operations/rollback.md` has two procedures:

- **A, failure before migrations:** switch back and start.
- **B, failure during or after migrations:** stop, switch back, `restore-database.sh --set <label> --backup-only`, `verify-restore.sh`, start, leave maintenance mode.

### 9A.5 Documentation (`docs/operations/`)

- `installation.md`: release contents, profiles and the egress table, frontend firewall, the destinations to allow outbound, steps, secrets per container, TLS, log collection on the host, volume ownership.
- `releases.md`: the release key (TEST key warning, creating the real key, rotation), building, registries.
- `rollback.md`: as above.
- `runbook.md`: start/stop, maintenance mode, upgrading, backup quick reference, certificates, ClamAV, users, troubleshooting.
- `host-hardening.md`: OS, access, network, the Docker daemon (`daemon.json`), monitoring, checklist.
- `sizing.md`: pilot and national sizing, per-service limits, PostgreSQL settings, storage growth.
- README section "Production deployment and releases (Phase 9A)".

### 9A.6 Verification, as run

Local runs used Docker Desktop 29.7.2 (7.7 GB VM), with native-architecture images for the test releases.

| Check | Result |
|---|---|
| `make release` produces a signed release | `dist/buffalo-0.9.0/`: images 1.36 GB, bundle 67 KB, signature self-verified. |
| One changed byte is refused | One byte changed in each of `RELEASE-NOTES.md`, the bundle, the 1.36 GB images archive, `SHA256SUMS` and `SHA256SUMS.sig`. All refused (checksum mismatch, or invalid signature). The untampered TEST-key release is refused without `--allow-test-signature`. |
| Fresh air-gapped install | `scripts/ci/release-install-test.sh` on a fresh project: 11/11 healthy. Outbound TCP from inside every container blocked (nginx through the firewall rule). HTTPS 200, HTTP 301, TLS 1.0/1.1 refused. |
| Fresh connected install (`--mail-egress --clamav-online`) | Only nginx, horizon and clamav reach the outside; the other 8 services are blocked. HTTPS checks pass. |
| Production boot refusals | `APP_DEBUG=true`, `APP_URL=http://…` and `BUFFALO_CSP_REPORT_ONLY=true` each exit 1 with "Unsafe production settings: refusing to start". The unchanged configuration starts. |
| TLS scan (testssl.sh 3.2, by digest, external tool only) | SSLv2/3 and TLS 1.0/1.1 not offered; TLS 1.2 and 1.3 offered. No NULL, export, LOW, 3DES, RC4 or CBC ciphers. Forward secrecy with AEAD only. Not vulnerable to Heartbleed, ROBOT, CRIME, BREACH, POODLE, SWEET32, LOGJAM, BEAST or LUCKY13. |
| Upgrade 0.9.0 → 0.9.1 (one new migration) | Backup set `20261007-135822F` and a restic snapshot taken first. Maintenance on, workers stopped, images verified, migration applied (59 → 60), smoke checks pass, maintenance off, HTTPS 200. |
| Version and confirmation refusals | Older version refused, same version refused, refused without `--yes` and no terminal. Answering "yes" at the typed prompt does not proceed. |
| Failing migration (0.9.2) | Migration error shown. Site left on the maintenance page (503). Rollback printed with backup set `20261007-140014F`. Nothing rolled back automatically. |
| Rollback (procedure B) | Followed as written. A table created after the backup was gone after `--set … --backup-only`; migrations back to 60. `verify-restore.sh` passes (keys, audit chains, migrations). 11/11 healthy on 0.9.1, maintenance off, HTTPS 200, pgBackRest `check` ok on the new timeline. |
| Patched images and exceptions | See the table above; the exceptions check is in `make ci` and the weekly workflow. |
| Restore tooling regression (dev) | `make restore-drill` passes with the changed scripts: 25 s, all checks green (`docs/drills/2026-10-07-0057.md`). |
| `make test`, `make lint`, `make ci` | All pass. Pest: 1,501 tests (12,276 assertions); Vitest: 14. Pint, Larastan level 7, ESLint, Prettier and tsc: no errors. `make ci`: images build, hardening checks pass for all three combinations, 15 exceptions dated, `composer audit` and `npm audit` clean. |

For a fresh host in CI, `.github/workflows/release-install.yml` runs weekly and on demand, for both profiles:

1. Builds a release.
2. Signs it with a throwaway key generated in the job.
3. Confirms a tampered copy is refused.
4. Runs the same install and egress test as root, with the firewall rule applied by the installer.

It has not run on GitHub yet: it needs a push.

## Secrets per container

| Service | Secrets | Networks (all overlays) |
|---|---|---|
| app | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret, mail_password, health_token | backend, internal-tools |
| horizon | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret, mail_password | backend, internal-tools (+ egress) |
| scheduler, reverb | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret | backend (+ internal-tools for scheduler) |
| tools (one-off) | the scheduler set + db_migrations_password, db_admin_password, db_readonly_password | backend |
| nginx | tls_key | frontend, backend (+ egress in connected/cloud) |
| postgres | db_admin_password, db_migrations_password, db_password, db_readonly_password, pgbackrest_passphrase | backend, backup |
| pgbackrest | pgbackrest_passphrase | backup |
| backup (restic) | restic_password | backup |
| valkey | redis_password, valkey_admin_password | backend |
| gotenberg, clamav, certbot | none | internal-tools / egress |

The secret files live in `BUFFALO_SECRETS_DIR` (default `/etc/buffalo/secrets`) and are mounted at `/run/secrets/<name>`.

## Files created or changed (this commit)

- **New:**
  - `scripts/release.sh`, Makefile `release` target, `/dist` in `.gitignore`
  - `scripts/ops/install.sh`, `upgrade.sh`, `release-key.sh`
  - `security/release-signing.pub` (TEST key)
  - `scripts/ci/release-install-test.sh`, `.github/workflows/release-install.yml`
  - `docs/operations/*.md` (6 files)
  - `docs/drills/2026-10-07-0057.md`
- **Changed:**
  - `compose.prod.yml` (PostgreSQL memory settings), `deploy/buffalo.env.example` (capacity settings)
  - `scripts/ops/egress-firewall.sh` (documentation)
  - `docker/postgres/pgbackrest-scheduler.sh`, `docker/backup/restic-scheduler.sh`: backup functions return the backup's result. Before, `now full` exited with the status of `write_info`, so a failed on-demand backup looked successful.
  - `scripts/restore/*`: production installations read `/etc/buffalo/buffalo.env` (or `BUFFALO_CONFIG`) when there is no `.env`, take secrets from `BUFFALO_SECRETS_DIR`, and use the release's own `buffalo/postgres` and `buffalo/restic` images instead of the retired `buffalo-postgres:dev` and an upstream restic image that an air-gapped host would not have. The drill uses the production files with the air-gapped profile. `restore-database.sh` gains `--backup-only`.
  - `README.md`

## Deviations and decisions

- **Compose 2.33.1 and Docker 28 are required**, for network `gw_priority`, so egress-joined services route out through `egress`. `!reset` and `!override` in `compose.prod.yml` also need Compose 2.24+.
- **The frontend network keeps a gateway, plus a host firewall rule.** Docker cannot publish ports from an internal network, so "no egress" for nginx is enforced with `DOCKER-USER` (bridge `buffalo-front`). On hosts where the installer cannot apply it (not Linux, or not root) it warns. Two installations on one host need different `BUFFALO_FRONTEND_BRIDGE` names.
- **nginx Dockerfile targets:** `base` (development: configuration and maintenance page) and `prod` (templates, TLS, the application's `public/` copied from the app image).
- **Valkey and restic images are patched too** (`apk upgrade`), beyond the four named in the prompt, because Trivy flagged fixable OS packages.
- **Pre-existing bugs fixed while testing:**
  - Password-reset mail queued, and runtime Reverb configuration (both above).
  - Backup functions not returning their result.
  - Restore scripts with stale image names.
  - Before this phase, `shared_buffers` was the 128 MB default.
- **Release signing key:** the committed `security/release-signing.pub` is a **development TEST key** (principal `buffalo-release-test`), and installers refuse it without `--allow-test-signature`. The machine rebooted mid-session and the first throwaway private key, kept in the session's temporary directory, was lost; a second test key replaced it before anything was committed. **The project owner must run `scripts/ops/release-key.sh` on the offline signing machine and commit the real public key (principal `buffalo-release`) before any production release.**
- **The release-install test used native-architecture images locally.** CI builds linux/amd64 as `make release` does by default.
- **`rollback.md` uses `--backup-only`.** `--set` alone replays all archived WAL after the backup, which would re-apply a failed non-transactional migration. Verified with a marker table.

## New packages and tools

- No new Composer or npm packages.
- Images: `certbot/certbot:v5.8.0` (Apache-2.0), pinned by digest, only in the opt-in ACME overlay.
- External test tools, not part of BUFFALO:
  - `drwetter/testssl.sh:3.2` (GPL-2.0) at `sha256:61f638ce55000e759949e52df5a25804e90bb88c8bae5ef74dee3b5b61fe23b3`
  - `koalaman/shellcheck:v0.11.0` (GPL-3.0), for linting the scripts

## Known issues

- `release-install.yml` has not run on GitHub yet.
- The Docker Desktop VM loses the `DOCKER-USER` rule when it restarts (it did during this session); re-apply it by hand there. On Linux the systemd unit handles this.
- `install.sh` cannot check NTP without `timedatectl`; it warns instead.
- The 15 Trivy exceptions are due for review on 2026-12-05. CI fails after that date until they are reviewed.
- The images archive is about 1.4 GB, mostly Gotenberg's Chromium.

## For the next step (9B)

- Run installs and upgrades only through the signed bundle. Never start production with `docker compose up` from a checkout.
- The pilot `is_live` flag on states is 9B work; nothing for it exists yet.
- The hierarchy and boundaries are imported after installation (`buffalo:import-hierarchy`, `buffalo:import-boundaries`), from owner-supplied files.
- Every new migration must be safe to run during an upgrade: the site is in maintenance mode and workers are stopped.
