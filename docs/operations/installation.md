# Installing BUFFALO

This guide covers a production installation from a signed release (Phase 9A).
Prepare the host first with [host-hardening.md](host-hardening.md), and size it
with [sizing.md](sizing.md).

## What a release contains

| File | Purpose |
|---|---|
| `buffalo-<v>-images.tar.zst` | Every container image (`docker save`, linux/amd64) |
| `buffalo-<v>-bundle.tar.zst` | Compose files, profiles, scripts, documentation, `VERSION`, `images.lock`, the release public key |
| `install.sh` | The installer (also inside the bundle) |
| `RELEASE-NOTES.md` | Changes and new migrations since the previous release |
| `SHA256SUMS`, `SHA256SUMS.sig` | Checksums of the files above, signed with the BUFFALO release key |

How releases are built and signed: [releases.md](releases.md).

## Edge modes

The edge mode decides how BUFFALO's nginx container meets the outside world.
It is one setting, `BUFFALO_EDGE` in `/etc/buffalo/buffalo.env`, chosen with
`install.sh --edge` and remembered there. Switching between a dedicated and a
shared server is a configuration change only: run the installer again with
the other mode.

### Which mode do I need?

| Your server | Mode | Who terminates TLS |
|---|---|---|
| A server (or VM) for BUFFALO alone; nothing else uses ports 80 and 443 | `direct` (default) | BUFFALO's nginx container |
| A shared server that already runs nginx on 80 and 443 for other sites | `proxy` | The server's own nginx |

If you are not sure, run `sudo ss -ltnp 'sport = :443'`. If nothing listens on
port 443, use `direct`. If the host's nginx does, use `proxy`.

### `direct`: a dedicated server

    browser ──https:443──▶ BUFFALO nginx (TLS) ──▶ app, Reverb (wss)

- nginx publishes ports 80 and 443 on all interfaces (`BUFFALO_BIND_ADDRESS`,
  `BUFFALO_HTTP_PORT`, `BUFFALO_HTTPS_PORT`). It terminates TLS 1.2 and 1.3,
  and port 80 redirects to HTTPS (see [TLS](#tls)).
- The certificate comes from files (`--tls-cert fullchain.pem --tls-key
  privkey.pem`, or `BUFFALO_TLS_CERTIFICATE_FILE` and the `tls_key` secret),
  or from Let's Encrypt with `--acme` (connected/cloud profiles, port 80
  reachable from the internet). For tests, `--acme --acme-staging` uses Let's
  Encrypt's staging CA, whose certificates browsers do not trust.
- **No forwarded headers are trusted.** The client address in logs and in
  the login history is the TCP peer.

      sudo sh install.sh --profile connected --edge direct --acme
      sudo sh install.sh --profile airgapped --edge direct --tls-cert /root/fullchain.pem --tls-key /root/privkey.pem

### `proxy`: behind the server's existing nginx

    browser ──https:443──▶ host nginx (TLS) ──http──▶ 127.0.0.1:8090 ──▶ BUFFALO nginx ──▶ app, Reverb (wss)

- BUFFALO's nginx publishes **only** plain HTTP on `127.0.0.1:$BUFFALO_EDGE_PORT`
  (`--edge-port`, default 8090). Nothing listens on a public address except
  the host's nginx, and BUFFALO's nginx holds no certificate (no `tls_key`
  needed).
- `X-Forwarded-For` and `X-Forwarded-Proto` are trusted **only** from the
  Docker gateway address (connections to the published port arrive from
  it). From any other peer they are ignored. Laravel itself trusts no
  proxies. The host's nginx overwrites both headers, so a browser cannot
  choose its own address.
- Print the host's server block and install it (WebSocket upgrade,
  `X-Forwarded-Proto https`, larger proxy buffers, `client_max_body_size`,
  and placeholders for the certificate paths):

      sudo sh install.sh --profile airgapped --edge proxy --edge-port 8090
      /opt/buffalo/current/scripts/ops/edge-host-config.sh --config /etc/buffalo/buffalo.env \
          | sudo tee /etc/nginx/sites-available/buffalo
      sudo ln -s /etc/nginx/sites-available/buffalo /etc/nginx/sites-enabled/
      sudoedit /etc/nginx/sites-available/buffalo     # replace the two CHANGE-ME certificate paths
      sudo nginx -t && sudo systemctl reload nginx

  (`make edge-host-config CONFIG=/etc/buffalo/buffalo.env` does the same from a
  source tree.) The host's certificate and its renewal are yours to manage,
  for example with the distribution's certbot package.

### What is checked

- **At every start** (`buffalo:production:check`, all application
  containers). `BUFFALO_EDGE` must be `direct` or `proxy`, and must match what
  the compose files publish: `BUFFALO_EDGE=proxy` without
  `compose.edge-proxy.yml` in `COMPOSE_FILE` is refused, and so is the
  reverse. In proxy mode the published address must be `127.0.0.1`: setting
  `BUFFALO_EDGE_BIND` to anything else stops BUFFALO from starting. `APP_URL`
  must be `https://` in both modes, as before.
- **Before starting** (`install.sh`, `upgrade.sh`): `scripts/ops/edge-check.sh`
  checks the merged Compose configuration (`buffalo:edge:check-compose`). In
  proxy mode nginx must publish exactly one port, on 127.0.0.1. In direct mode
  only its HTTP and HTTPS servers. No other service may publish anything.
- **Go-live check and System health** show the mode, address and ports. In
  proxy mode the TLS certificate item reads "Check on the host": BUFFALO
  cannot see the host's certificate, so check its expiry and renewal there.

## Deployment profiles

The application is identical in every profile. Only the compose files and
settings differ.

| Profile | Outbound access | Compose files |
|---|---|---|
| `airgapped` | None from any container | `compose.yml`, `compose.prod.yml`, `compose.profile-airgapped.yml` |
| `connected` | Only through the `egress` network, only for the services below | as above with `compose.profile-connected.yml` |
| `cloud` | As `connected` (cloud firewall rules recommended in addition) | `compose.profile-cloud.yml` |

In `connected` and `cloud`, nginx joins `egress` (OCSP stapling). Three more
services can be given egress with an opt-in overlay (an installer option):

| Overlay | Installer option | Service | Why |
|---|---|---|---|
| `compose.egress-mail.yml` | `--mail-egress` | `horizon` | Sends mail (notifications, password resets) to the mail relay |
| `compose.egress-clamav.yml` | `--clamav-online` | `clamav` | Downloads virus signatures (otherwise import them offline: `scripts/clamav-import-signatures.sh`) |
| `compose.acme.yml` | `--acme` (edge mode `direct`) | `certbot` | Obtains and renews certificates (ACME HTTP-01, port 80) |

The web process (`app`), PostgreSQL, Gotenberg, Valkey, Reverb, the scheduler
and the backup services never join `egress` in any profile.

### The frontend network and the host firewall

nginx publishes ports 80 and 443 (in edge mode `proxy`, 127.0.0.1:8090), and Docker can only publish ports from a
network that has a gateway, so Docker alone cannot stop nginx from opening
connections out. The frontend network therefore has a fixed bridge name
(`buffalo-front`, setting `BUFFALO_FRONTEND_BRIDGE`, at most 15 characters
(a Linux interface name)), with masquerading
switched off, and `scripts/ops/egress-firewall.sh apply` adds a `DOCKER-USER`
rule that drops new outbound connections from that bridge. Replies to inbound
HTTPS connections are unaffected. The installer applies the rule and adds a
systemd unit (`buffalo-egress-firewall.service`) that re-applies it whenever Docker starts.
Check it at any time:

    sudo /opt/buffalo/current/scripts/ops/egress-firewall.sh status

**Restrict egress at the host or network firewall as well.** In `connected`
and `cloud`, allow outbound traffic from the Docker host only to the
destinations you need: the mail relay (`MAIL_HOST:MAIL_PORT`), the ClamAV
mirror (`database.clamav.net`, 443) if `--clamav-online` is used, the ACME
CA (for example `acme-v02.api.letsencrypt.org`, 443) and the CA's OCSP
responder (from the certificate's Authority Information Access extension).
Deny everything else.

## Steps

1. **Verify the release yourself** with the BUFFALO public key obtained
   through a separate channel, and confirm its fingerprint with the project:

       ssh-keygen -lf release-signing.pub
       ssh-keygen -Y verify -f release-signing.pub -I buffalo-release -n buffalo-release \
           -s SHA256SUMS.sig < SHA256SUMS
       sha256sum -c SHA256SUMS

2. **Create the settings file** `/etc/buffalo/buffalo.env` (mode 0640, owner
   root) from `deploy/buffalo.env.example` in the bundle. At minimum set
   `BUFFALO_SERVER_NAME`, `APP_URL` (`https://...`, in both edge modes), the
   certificate path `BUFFALO_TLS_CERTIFICATE_FILE` (edge mode `direct` with
   your own certificate) and the mail settings. Running the
   installer without a settings file creates it from the template and stops.

3. **Create the secrets** (never printed, never overwritten):

       sudo sh scripts/ops/generate-secrets.sh --dir /etc/buffalo/secrets

   Then (edge mode `direct` with your own certificate) put the certificate's private key in `/etc/buffalo/secrets/tls_key`
   (mode 0444, directory 0700; Docker reads the files as root and mounts
   each one only into the containers below). Put the mail relay password in
   `mail_password` if the relay needs one. Escrow the keys and passphrases as
   described in [../security/key-management.md](../security/key-management.md).

4. **Run the installer** as root:

       sudo sh install.sh --profile airgapped
       sudo sh install.sh --profile connected --mail-egress --acme
       sudo sh install.sh --profile airgapped --edge proxy

   It verifies the signature and checksums, checks Docker (28+), Compose
   (2.33.1+), memory (16 GB), disk and clock synchronisation, unpacks the bundle
   to `/opt/buffalo/releases/<v>` (`/opt/buffalo/current` points at it), loads
   and verifies the images against the signed `images.lock`, checks the secret
   files, provisions the database roles, migrates as the owner role, seeds
   BUFFALO's reference data (roles and permissions, zones and states,
   reference lists; never demo data), applies the firewall rule and starts
   every service. It stops at the first error and can be run again.
   `--dry-run` shows what it would do; `--help` lists every option.

   From a registry (connected/cloud): `--registry registry.example.gov.ng/buffalo`.
   The pulled images are compared with the image IDs in the signed
   `images.lock` and refused if they differ.

5. **Follow the printed next steps**: create the first super admin, load
   the production data ([data-loading.md](data-loading.md)), configure the
   second backup repository, run the first restore drill.

## Secrets per container

Each container receives only the secret files it needs, mounted under
`/run/secrets/<name>` and read through the `*_FILE` settings.

| Service | Secrets | Networks (all overlays) |
|---|---|---|
| `app` | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret, mail_password, health_token | backend, internal-tools |
| `horizon` | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret, mail_password | backend, internal-tools (+ egress with `--mail-egress`) |
| `scheduler` | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret | backend, internal-tools |
| `reverb` | app_key, ciphersweet_key, db_password, redis_password, reverb_app_key, reverb_app_secret | backend |
| `tools` (install/upgrade only) | the `app` set without mail/health, plus db_migrations_password, db_admin_password, db_readonly_password | backend |
| `nginx` | tls_key (edge mode `direct` only) | frontend, backend (+ egress in connected/cloud) |
| `postgres` | db_admin_password, db_migrations_password, db_password, db_readonly_password, pgbackrest_passphrase | backend, backup |
| `pgbackrest` | pgbackrest_passphrase | backup |
| `backup` (restic) | restic_password | backup |
| `valkey` | redis_password, valkey_admin_password | backend |
| `gotenberg`, `clamav`, `certbot` | none | internal-tools / egress |

The database owner and superuser passwords exist only in `postgres` and the
one-off `tools` container. The running application connects as the
non-owner application role (Phase 8A).

## TLS

In edge mode `direct`, nginx terminates TLS on port 443 (container port 8443): TLS 1.2 and 1.3
only, Mozilla "intermediate" AEAD ciphers with forward secrecy, HTTP/2, and
port 80 redirecting to HTTPS (except `/.well-known/acme-challenge/`). OCSP
stapling is switched on only in profiles with egress. nginx reloads itself
within a minute of the certificate file changing.

- **Own certificate:** set `BUFFALO_TLS_CERTIFICATE_FILE` to the full chain
  (leaf then intermediates) and put the key in the `tls_key` secret.
- **ACME (`--acme`):** certbot (5.8.0, pinned by digest) obtains the
  certificate by HTTP-01, renews it every 12 hours when due, and nginx uses
  it automatically. Port 80 must be reachable from the internet. A
  self-signed placeholder is served until the first issuance.

In edge mode `proxy` the host's nginx terminates TLS: use its own TLS
settings (at least `ssl_protocols TLSv1.2 TLSv1.3`, as in the generated
server block).

Check the result with an external scanner, for example testssl.sh run from
another machine: `testssl.sh --protocols --std --fs --vulnerable buffalo.example.gov.ng`.

## Logs

Every container logs to stdout/stderr (Laravel's `stderr` channel in
production), and Docker's `json-file` driver rotates logs at
`BUFFALO_LOG_MAX_SIZE` (20m) × `BUFFALO_LOG_MAX_FILES` (5) per container.
Logs never contain NIN, phone numbers or names of persons of interest.

To keep logs centrally, collect them on the host rather than adding a log
shipper container: for example point journald at the Docker logs
(`"log-driver": "journald"` in `/etc/docker/daemon.json`, then forward with
`systemd-journal-remote` or rsyslog to the agency's log server), or have
the host's collector read `/var/lib/docker/containers/*/*-json.log`. The
audit trail itself is in the database (activity log, record access log),
not in container logs.

## Volume ownership

Volumes created by older versions of BUFFALO may be owned by root. Correct
them once (idempotent, with `--dry-run` to preview):

    sudo sh /opt/buffalo/current/scripts/ops/fix-volume-ownership.sh --project buffalo
