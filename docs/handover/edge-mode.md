# Edge mode — handover

One setting, `BUFFALO_EDGE`, decides how BUFFALO's nginx container meets the
outside world, for the UAT demo (`make uat-up`) and production
(`install.sh`). Moving between a dedicated server and a shared server is a
configuration change only.

## What was built

### The modes

| Mode | Where | nginx publishes | TLS | Forwarded headers |
|---|---|---|---|---|
| `direct` | dedicated server (UAT and production; production default) | 80 and 443 on all interfaces | in BUFFALO's nginx: files, or Let's Encrypt via the Phase 9A ACME overlay | never trusted |
| `proxy` | shared server behind its existing nginx (UAT and production) | plain HTTP on `127.0.0.1:$BUFFALO_EDGE_PORT` only (default 8090) | in the host's nginx | trusted only from the Docker gateway |
| `local` | UAT on a workstation (UAT default, as before) | `127.0.0.1:19080/19443` | self-signed | never trusted |

### Production (`install.sh`)

- **Options:** `--edge direct|proxy`, `--edge-port PORT`,
  `--tls-cert FILE --tls-key FILE` (the pair is checked to match; the key is
  written into the `tls_key` secret in place), and `--acme-staging` (with
  `--acme`).
- **Remembered:** `BUFFALO_EDGE` and `BUFFALO_EDGE_PORT` are kept in the
  settings file, so later starts and re-runs need no flags.
- **Refused:**
  - `--acme` or `--tls-*` with `proxy`;
  - `--acme-staging` without `--acme`;
  - a bad port;
  - an unknown mode;
  - `--acme` together with `--tls-cert`;
  - a mismatched key;
  - direct mode without a certificate file or ACME.
- **No key needed in proxy mode:** an empty `tls_key` placeholder is created.
- **Printed for proxy mode:** the command that writes the host's server block.

### UAT (`make uat-up`)

- **Inputs:** `EDGE=local|direct|proxy`, `DEMO_HOST`, `EDGE_PORT`,
  `TLS_CERT`/`TLS_KEY`, `TLS=acme|self-signed`, `ACME_EMAIL`, `ACME_STAGING=1`
  and `BASIC_AUTH=1|0`. All of them are remembered in `.uat/buffalo.env`.
- **Backward compatible:** `make uat-up DEMO_HOST=... DEMO_PORT=...` still
  means proxy mode, and an older `.uat/` is migrated (`DEMO_PORT` →
  `BUFFALO_EDGE_PORT`, `BUFFALO_DEMO_GATEWAY` → `BUFFALO_EDGE_GATEWAY`).
- **ACME:** `TLS=acme` uses the `connected` profile, because certbot needs
  egress.

### Proxy server and site password

- **`compose.edge-proxy.yml`** replaces `compose.uat-demo.yml` and is in the
  release bundle. The proxy server template and the gateway detection are
  now baked into the nginx image (`templates-proxy/`, `edge-gateway.envsh`)
  rather than mounted from the source tree. In proxy mode nginx has no
  certificate, no `tls_key` and no TLS server.
- **`make edge-host-config`** (`scripts/ops/edge-host-config.sh [--config FILE]`)
  prints a `sites-available` server block for `DEMO_HOST` or
  `BUFFALO_SERVER_NAME`. It includes:
  - the WebSocket upgrade;
  - `X-Forwarded-For $remote_addr`, which overwrites any value the browser
    sent;
  - `X-Forwarded-Proto https`;
  - the larger proxy buffers;
  - `client_max_body_size 100M`;
  - `CHANGE-ME` placeholders for the certificate paths.
- **Site password (UAT only):** `compose.uat-basic-auth.yml` and
  `scripts/uat/basic-auth.sh` put nginx `auth_basic` in front of the whole
  site, in any mode. The password is generated on first start and printed
  once. Only a SHA-512 crypt hash is kept, in `.uat/secrets/basic_auth`, and
  it is hashed in the app image with the password passed on stdin.
  `make uat-credentials` (now `scripts/uat/credentials.sh`) reissues it with
  the account credentials, and the change applies immediately with no
  restart. The production start-up check refuses `BUFFALO_BASIC_AUTH`.

### Checks

- **`buffalo:production:check`** (every application container, at every
  start) adds `EdgeMode::problems()`:
  - the mode must be `direct` or `proxy` (UAT also allows `local` and the site
    password);
  - it must match what the compose files publish (`BUFFALO_EDGE_PUBLISHED`,
    set by `compose.prod.yml` or `compose.edge-proxy.yml`);
  - proxy mode must have `BUFFALO_EDGE_BIND` = `127.0.0.1`.

  `APP_URL` must still be `https` in every mode.
- **`buffalo:edge:check-compose`**, run by `scripts/ops/edge-check.sh` from
  `install.sh`, `upgrade.sh` and `make uat-up` before anything starts. It
  reads the merged `docker compose config` and refuses:
  - in proxy mode: anything except exactly one port, on 127.0.0.1, to the
    proxy server;
  - in direct mode: any port that is not nginx's HTTP or HTTPS server;
  - any other service publishing a port.
- **`scripts/check-edge-ports.sh`** (in `make ci`) renders the real compose
  files with the settings template and asserts the exact mappings:
  - direct: `0.0.0.0:80→8080` and `0.0.0.0:443→8443`, with and without
    ACME;
  - proxy: only `127.0.0.1:8090→8081`, or `127.0.0.1:9123→8081` with
    `BUFFALO_EDGE_PORT=9123`; also with a stale `BUFFALO_BIND_ADDRESS=0.0.0.0`
    and with the site password;
  - UAT local: `127.0.0.1:19080/19443`.

  `check-compose-hardening.sh` also covers the proxy and UAT basic-auth
  combinations.
- **Go-live check:** a new item, "Edge mode and port (mode)". In proxy mode
  the TLS item gets the new status `manual` ("Check on the host"), which
  does not block go-live. The console report and the go-live page render it.
- **System health:** a new "Edge mode" row (mode, address, ports, problems),
  and the TLS row reads "At the host's nginx" in proxy mode.

## Problems found while testing (fixed)

1. **First ACME certificate never served** (Phase 9A bug). `cert-reload.sh`
   started with no baseline, so its first check, 15 minutes after start,
   only recorded the certificate certbot had issued in the first minute.
   nginx kept serving the 7-day placeholder until the next renewal, about 60
   days later. It now takes the starting hash as the baseline and checks
   every minute.
2. **certbot could not write HTTP-01 challenges** (Phase 9A bug). Docker
   creates the `acme_webroot` volume root-owned, and nothing handed it to
   uid 101. `scripts/ops/acme-prepare.sh` (shared by `install.sh --acme` and
   `make uat-up TLS=acme`) now hands both ACME volumes to uid 101. The Phase
   9A ACME overlay could never have issued a certificate before these two
   fixes.
3. **System health's nginx probe failed** in production and UAT. `GET
   http://nginx:8080/up` was redirected to `https://nginx/up` on port 443,
   where nothing listens inside the network. Both port-8080 servers now
   answer `/up` (internal networks only).
4. **`make uat-up` let empty inputs override remembered settings.** Compose
   gives exported variables precedence over `--env-file`, and make exports
   every input, empty when not given. `make uat-up ... ACME_STAGING=1` would
   have run certbot against production Let's Encrypt. `up.sh` now clears its
   inputs after reading them.
5. The go-live console command crashed on the new `manual` status (found in
   the first UAT run, fixed, test added).

## Tests run

**Automated:**

- Pest:
  - `ProductionCheckTest` (edge cases, proxy and UAT acceptance);
  - `EdgeComposeCheckTest`;
  - `GoLiveCheckTest` (edge item, manual TLS, console output);
  - `SystemHealthTest` (edge row).
- `scripts/check-edge-ports.sh` and `check-compose-hardening.sh`.

**End to end** with `scripts/uat/edge-check.sh` and
`tests/browser/edge/edge.spec.ts` (Playwright, Chromium). Each run:

1. signs in through the edge over https with a TOTP code, sending a forged
   `X-Forwarded-For: 203.0.113.77`;
2. waits for the `wss://<host>/app/...` subscription;
3. sends a live notification from the server and waits for it to arrive
   over wss;
4. reads the login history.

| Environment | Mode | Result | Login history address |
|---|---|---|---|
| UAT | proxy, through a host nginx built from `make edge-host-config` | pass | 172.17.0.1 (what the host nginx saw); forged value ignored |
| UAT | proxy + `BASIC_AUTH=1` | pass; 401 with `WWW-Authenticate: Basic` first; wss works through the site password; `make uat-credentials` made the old password 401 and the new one 200 with no restart | 172.17.0.1 |
| UAT | direct, certificate files (test CA), ports 8080/8443 via test-only overlay | pass; chain verifies; TLS 1.2 and 1.3 accepted, 1.0 and 1.1 refused by the server; HTTP → HTTPS 301 | 192.168.65.1 (TCP peer) |
| UAT | direct, `TLS=acme` against a local ACME CA | pass; certificate issued by HTTP-01; first issuance served within a minute; forced renewal served without restart | 192.168.65.1 |
| UAT | local | pass | 192.168.16.1 |
| Production (`install.sh`) | direct, `--tls-cert/--tls-key`, ports 18080/18443 via settings | pass; go-live edge item passes and the TLS item read the served certificate | 192.168.65.1 |
| Production (`install.sh`, same install re-run with `--edge proxy --edge-port 8091`) | proxy, through a host nginx built from the release's `edge-host-config.sh` | pass; go-live shows TLS "check on the host" | 172.17.0.1 |

- **Proxy, non-gateway peer:** a peer on the frontend network sending a
  forged `X-Forwarded-For` straight to BUFFALO's nginx was logged under its
  own address.
- **Production refusals** on the real install:
  - `BUFFALO_EDGE_BIND=0.0.0.0` in proxy mode: `edge-check.sh` refused, and
    the app container refused to start (`buffalo:production:check`), even
    when started directly.
  - `BUFFALO_EDGE=proxy` without `compose.edge-proxy.yml`: refused by both.

**How production was tested.** The real TEST signing key was lost in Phase
9A, so the production install used a throwaway release built in the session
scratchpad (not committed). It contained:

- an `install.sh` that trusts a throwaway key;
- a bundle assembled as `release.sh` does, from this tree;
- the already-built images retagged, verified by `install.sh` against
  `images.lock` as usual.

Everything except the signature's trust root was exercised.

**Docker Desktop and client addresses.** Docker Desktop delivers connections
to published ports from its own gateway addresses (192.168.65.1, 172.17.0.1),
so "the client address" on this workstation is that address, not the
browser's own. The tests prove the address recorded is the one the outermost
nginx saw, and never the forged header. On a Linux server the outermost
nginx sees the real client.

## What still needs confirming on a real internet-facing server

On the fresh droplet:

1. **Real 80/443 binding (direct).** `make uat-up EDGE=direct ...` or
   `install.sh --edge direct`, then from another machine:
   `curl -I http://<host>/` (301 to https), `curl -I https://<host>/login`,
   and `testssl.sh --protocols <host>` (TLS 1.2 and 1.3 only). Port 80 was
   taken on the test workstation, so direct mode ran on 8080/8443 via
   `tests/edge/compose.direct-test-ports.yml`. `check-edge-ports.sh` proves
   the real files publish `0.0.0.0:80` and `0.0.0.0:443`.
2. **Let's Encrypt staging certificate.** `make uat-up EDGE=direct
   DEMO_HOST=<host> TLS=acme ACME_EMAIL=<you> ACME_STAGING=1` (or
   `install.sh --acme --acme-staging`). Within about a minute the served
   certificate's issuer should be "(STAGING) ...". Only a local ACME CA
   (step-ca) was used here.
3. **Renewal.** `docker compose ... exec certbot certbot renew --dry-run
   --config-dir /etc/letsencrypt --work-dir /tmp/le-work --logs-dir
   /tmp/le-logs` should succeed against staging. A real renewal happens about
   30 days before expiry, and nginx picks it up within a minute ("TLS
   certificate changed: reloading nginx" in its log). Forced renewal was
   tested here against the local CA only. Then switch to the production CA:
   `make uat-down`, `docker volume rm buffalo-uat_letsencrypt`, and
   `make uat-up` without `ACME_STAGING=1`.
4. **Real client IP in the login history, in each mode.**
   - Direct: sign in from a phone on mobile data. The login history (Users →
     the user → Login history, or `login_histories.ip_address`) must show
     the phone's public address.
   - Proxy: install the host nginx block from `make edge-host-config` and
     repeat. The address must again be the phone's, not `127.0.0.1` or the
     Docker gateway. Also check the BUFFALO nginx log line "trusting
     X-Forwarded-* from <gateway>" matches the address the host's
     connections arrive from (`docker compose logs nginx`). If it does not,
     set `BUFFALO_EDGE_GATEWAY`.
5. **wss through a real proxy.** Watch a notification arrive live in proxy
   mode with the host's real nginx (tested here with a stand-in running the
   generated block).

## Files

**New:**

- `compose.edge-proxy.yml` (was `compose.uat-demo.yml`)
- `compose.uat-basic-auth.yml`
- `docker/nginx/templates-proxy/proxy.conf.template` (was
  `docker/nginx/demo/demo.conf.template`)
- `docker/nginx/edge-gateway.envsh` (was
  `docker/nginx/demo/15-buffalo-demo-gateway.envsh`)
- `app/Support/Edge/EdgeMode.php`
- `app/Console/Commands/EdgeComposeCheckCommand.php`
- `scripts/ops/edge-check.sh`, `scripts/ops/edge-host-config.sh`,
  `scripts/ops/acme-prepare.sh`
- `scripts/uat/basic-auth.sh`, `scripts/uat/credentials.sh`,
  `scripts/uat/edge-check.sh`
- `scripts/check-edge-ports.sh`
- `tests/edge/compose.direct-test-ports.yml`, `tests/edge/compose.acme-test.yml`
- `tests/browser/edge.config.ts`, `tests/browser/edge/edge.spec.ts`
- `tests/Feature/Edge/EdgeComposeCheckTest.php`
- this handover

**Changed:**

- nginx: `docker/nginx/Dockerfile`, `templates/default.conf.template`,
  `buffalo-app.conf`, `cert-reload.sh`
- compose and settings: `compose.prod.yml`, `deploy/buffalo.env.example`,
  `config/buffalo.php`
- application: `ProductionCheckCommand`, `GoLiveCheckCommand`,
  `GoLiveCheck`, `SystemHealthReport`
- frontend: `pages/system/go-live.tsx`, `pages/system/health.tsx`
- scripts: `scripts/uat/up.sh`, `scripts/ops/install.sh`,
  `scripts/ops/upgrade.sh`, `scripts/release.sh`,
  `scripts/check-compose-hardening.sh`, `Makefile`
- tests: `tests/browser/support/session.ts`, `ProductionCheckTest`,
  `UatTest`, `GoLiveCheckTest`, `SystemHealthTest`
- docs: `docs/operations/installation.md` ("Edge modes", "Which mode do I
  need?"), `docs/operations/demo-hosting.md` (rewritten)

## Packages and images

- **No new Composer or npm packages.**
- **Test only (not in any release, never run in production):**
  `smallstep/step-ca:0.30.2` (Apache-2.0, March 2026, pinned by digest) as
  the local ACME CA in `tests/edge/compose.acme-test.yml`. Pebble was not
  used: it is MPL-2.0.

## Known issues and notes

- **Old test volumes.** The ACME test runs left two UAT volumes:
  `buffalo-uat_step_ca` (unused, from a first attempt) and
  `buffalo-uat_edge_test_ca` / `_root`. The production test left
  `buffalo-prodedge_*` volumes. All are test-only; remove them with
  `docker volume rm` when convenient. Deleting volumes was not done
  automatically.
- **Who manages the host certificate.** In proxy mode the host certificate
  and its renewal are outside BUFFALO; the go-live item says so rather than
  passing.
- **`BUFFALO_HEALTH_NGINX_URL`.** The default `http://nginx:8080/up` now
  works in every mode.
