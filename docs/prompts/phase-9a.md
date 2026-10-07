# BUFFALO — Phase 9A: Production Packaging, Deployment Profiles and Releases

Read `CLAUDE.md` before starting (updated for Phase 9: deployment profiles, releases, patched images, pilot rollout). Read `docs/handover/phase-8-summary.md`, especially "Phase 9 engineering".

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

BUFFALO is feature-complete and hardened. Phase 9 gets it onto a real server and into users' hands:

- **9A (this step):** production compose and images, deployment profiles, patched third-party images, signed releases with install and upgrade scripts, host hardening and the operations runbook.
- **9B:** go-live readiness: production data loading, bulk onboarding, pilot rollout controls, in-app help and manuals, UAT, and the go-live check.

The client has **not chosen** a deployment target. Build all three profiles; the choice must be a configuration step at install time, not development work.

| Profile | Internet egress | Releases arrive by | TLS certificate | Mail | ClamAV signatures |
|---|---|---|---|---|---|
| `airgapped` | none | signed offline bundle (USB or internal transfer) | provided by the client's CA | off, or an internal relay on the same network if one exists | offline import |
| `connected` | only through allow-listed egress (mail relay, ClamAV mirror, ACME if used) | signed bundle **or** registry pull, verified the same way | client's CA, or ACME | relay via egress network | online (freshclam) or offline |
| `cloud` | as `connected` | registry pull, verified | ACME or provided | relay via egress network | online |

---

## Step 9A.1 — Production compose

1. `compose.prod.yml` (used **instead of** `compose.override.yml`): the `prod` image for app, Horizon, scheduler and Reverb; no source mounts; configuration (nginx, PHP, pgBackRest, Postgres) **baked into images** rather than bind-mounted (8A note); a `storage` volume for Laravel's writable paths so read-only root filesystems work; no Mailpit, no Vite.
2. Profile overlays:
   - `compose.profile-airgapped.yml`: no egress network at all.
   - `compose.profile-connected.yml` / `compose.profile-cloud.yml`: an `egress` network joined **only** by the services that need it (queue workers for mail if enabled, ClamAV's updater if online, the ACME client if used), never by the app's web process, Postgres or Gotenberg.
   - Document that the host firewall should further restrict egress to the named destinations (mail relay host, ClamAV mirror, ACME endpoint), since Docker networks alone do not restrict destinations.
3. **Per-container secrets:** each service receives only the secret files it needs (map them explicitly; list the mapping in the handover).
4. **TLS** in nginx: certificate and key from files (`BUFFALO_TLS_CERTIFICATE`, `BUFFALO_TLS_KEY`); TLS 1.2 and 1.3 only with a modern cipher list; HTTP redirects to HTTPS; OCSP stapling only when the profile has egress. Optional ACME client (certbot, Apache-2.0, pinned by digest) for `connected`/`cloud` using HTTP-01, as a separate service with a renewal schedule and nginx reload; off by default.
5. Production defaults enforced at boot (fail to start otherwise): `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, secure cookies, CSP enforced (not report-only), Horizon dashboard restricted, `buffalo:secrets:check` passing, key fingerprints matching.
6. One-time migration helper for installations created before 8C (Valkey/ClamAV volume ownership) as `scripts/ops/fix-volume-ownership.sh`, idempotent.
7. Logging: Docker `json-file` with size and file rotation on every service; application logs to stderr in production (no sensitive values, per CLAUDE.md); a documented way to collect logs to a file on the host for the client's own tools.

## Step 9A.2 — Patched third-party images

1. `docker/patched/<name>/Dockerfile` for PostGIS, nginx, ClamAV and Gotenberg: `FROM` the digest-pinned upstream image, apply OS security updates (`apt-get upgrade` / `apk upgrade` as appropriate; for Gotenberg, also update Chromium if the base distribution offers a newer patched package), clean caches, keep the upstream user, entrypoint and health behaviour, and label the image with the upstream digest and build date.
2. All compose files use the patched images.
3. CI workflow `images.yml`, weekly and on demand: rebuild patched images, scan with Trivy, push to the project registry (for `connected`/`cloud`), and produce a vulnerability report artifact.
4. **Exceptions:** `security/vulnerability-exceptions.yaml` (Trivy ignore format with per-entry expiry dates; verify the current format) listing remaining fixable-but-unpatched or unfixable findings, each with a reason and a review date at most 60 days ahead. CI fails when any exception has expired or a new high/critical finding appears without an exception.
5. Report in the handover: the Trivy counts before and after patching for each image, and what remains with reasons.

## Step 9A.3 — Signed releases

1. `make release VERSION=x.y.z` (run in CI or on a build machine):
   - builds all BUFFALO and patched images for `linux/amd64`;
   - `docker save` into `buffalo-x.y.z-images.tar.zst`;
   - packages compose files, profile overlays, scripts, docs and a `VERSION` file into `buffalo-x.y.z-bundle.tar.zst`;
   - writes `SHA256SUMS` for both, and signs it with `ssh-keygen -Y sign -n buffalo-release` using a release signing key that is **not** stored in the repository;
   - writes release notes from the commit history (Conventional Commits) and the migrations included.
2. The public verification key is committed as `security/release-signing.pub` and embedded in the install and upgrade scripts.
3. For `connected`/`cloud`, the same manifest and signature are published with the registry images; the scripts verify image digests against the signed manifest before running them.

## Step 9A.4 — Install and upgrade

All scripts are POSIX shell, idempotent where possible, with `--help` and `--dry-run`, and stop at the first error.

**`scripts/ops/install.sh --profile <airgapped|connected|cloud>`**
1. Verify the bundle signature and checksums (refuse on any mismatch).
2. Check prerequisites: Docker and Compose versions, ≥ 16 GB RAM, disk space, time synchronisation active (NTP), host time zone irrelevant but clock accurate (TOTP and audit depend on it).
3. Load images (bundle) or pull and verify digests (registry).
4. Prompt for the secrets directory and check every expected secret file exists (no values printed); run `buffalo:secrets:check`.
5. Start Postgres, provision roles, run migrations as the owner role, start all services, wait for health checks.
6. Seed reference data that ships with BUFFALO (zones and states, default reference data, roles and permissions). **No demo data in production.**
7. Print next steps: create the first super admin (`buffalo:create-super-admin`), import hierarchy and boundaries (9B), configure backups repository 2, run the first restore drill.

**`scripts/ops/upgrade.sh <bundle>`**
1. Verify signature and checksums; refuse a version older than the installed one.
2. Show the release notes and migrations; require typed confirmation.
3. Take an immediate full pgBackRest backup and a restic evidence snapshot; abort if either fails.
4. Enable maintenance mode (a static page from nginx), stop workers and scheduler after current jobs finish (with a timeout).
5. Load new images, run migrations as the owner role, restart services, wait for health checks, run `buffalo:restore:verify --quick` style smoke checks (keys, migrations, audit chain heads, a sample decrypt).
6. Disable maintenance mode.
7. On failure after migrations: print the documented rollback procedure (restore the pre-upgrade backup with point-in-time recovery and load the previous images). Do not attempt automatic database rollback.

**`scripts/ops/rollback.md`**: the step-by-step rollback, tested once in the drill environment.

## Step 9A.5 — Host hardening and runbook

Write, in plain language for the client's IT staff:

- `docs/operations/host-hardening.md`: supported OS (Ubuntu Server LTS or RHEL-compatible; state tested versions), full-disk or volume encryption (LUKS) for Docker data and backup disks, firewall (only 443 inbound, and 80 if ACME; SSH only from the admin network, keys only, no root login), automatic OS security updates policy, Docker daemon settings (log rotation, `live-restore`, `userns-remap` if compatible with the volumes; test it), NTP, restricting access to the secrets directory, and disabling unused services.
- `docs/operations/runbook.md`: daily and weekly checks (System health), responding to each alert type, monthly restore drill, ClamAV offline signature import, certificate renewal, upgrades and rollback, adding disk space, rotating keys, what to do on suspected compromise (isolate, preserve logs and audit chain anchors, rotate keys, notify), and contacts table to be filled by the client.
- `docs/operations/sizing.md`: server sizing for a pilot (2 states) and national rollout (all 37), with database, evidence and backup growth estimates from the Phase 5–8 measurements.

## Step 9A.6 — Verification

- [ ] `make release` produces a signed bundle; tampering with one byte makes `install.sh` refuse it.
- [ ] A clean VM (or a fresh Docker host in CI) installs from the bundle with the `airgapped` profile: no container has a route to the internet (prove with a test request from inside each container).
- [ ] The same install with the `connected` profile: only the egress-joined services can reach the outside.
- [ ] Production boot refuses `APP_DEBUG=true`, an http `APP_URL`, or report-only CSP.
- [ ] TLS: only 1.2/1.3; HTTP redirects; an SSL scan tool run locally (for example `testssl.sh`, GPL, used only as an external test tool) reports no weak ciphers.
- [ ] An upgrade from the previous version takes a backup, migrates, passes smoke checks; a deliberately failing migration leaves maintenance mode on and prints the rollback procedure; following it restores service.
- [ ] Patched images reduce Trivy findings; the exceptions file is enforced in CI.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-9a.md` per CLAUDE.md section 9 and print it.
