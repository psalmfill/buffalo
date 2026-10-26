# Hosting the UAT environment as a demo

The UAT environment can be shown on a public host name. It has synthetic
data only, the red "UAT — TEST DATA ONLY" banner, and accounts for every
role. This is a **demo** of the UAT build. It is never a production
deployment and holds no real data. Production uses the same edge modes,
chosen with `install.sh --edge`
([installation.md, "Edge modes"](installation.md#edge-modes)).

## Which mode do I need?

| Where the demo runs | Mode | Command |
|---|---|---|
| Your own machine, for testing | `local` (default) | `make uat-up` → `https://localhost:19443` |
| A server for the demo alone; nothing else on ports 80 and 443 | `direct` | `make uat-up EDGE=direct DEMO_HOST=demo.example.org TLS=acme ACME_EMAIL=you@example.org` |
| A shared server whose nginx already serves other sites on 80 and 443 | `proxy` | `make uat-up EDGE=proxy DEMO_HOST=demo.example.org` |

Not sure? On the server, `sudo ss -ltnp 'sport = :443'`: if nothing listens
there, use `direct`. If the host's nginx does, use `proxy`.

Every choice (mode, host, port, certificate, site password) is remembered
in `.uat/buffalo.env`, so a later `make uat-up` needs no flags. Switching
modes is just another `make uat-up EDGE=...`: the data and accounts are kept.

## Requirements

- The BUFFALO source tree on the server, with Docker and Docker Compose (as
  for `make uat-up`, see `docs/uat/README.md`), and about 6 GB of free
  memory.
- For `direct` and `proxy`: a DNS name for the demo pointing at the server.

## `direct`: a dedicated server

```
browser ──https:443──▶ UAT nginx container (TLS for demo.example.org) ──▶ app, Reverb (wss)
```

nginx publishes ports 80 and 443 on all interfaces and terminates TLS with
the production settings (TLS 1.2 and 1.3, port 80 redirecting to HTTPS).
No forwarded headers are trusted. The certificate comes from one of:

```sh
# Let's Encrypt, obtained and renewed automatically (port 80 must be
# reachable from the internet; the server needs outbound HTTPS)
make uat-up EDGE=direct DEMO_HOST=demo.example.org TLS=acme ACME_EMAIL=you@example.org

# ... first against Let's Encrypt's staging CA (browsers will warn)
make uat-up EDGE=direct DEMO_HOST=demo.example.org TLS=acme ACME_EMAIL=you@example.org ACME_STAGING=1

# your own certificate files
make uat-up EDGE=direct DEMO_HOST=demo.example.org TLS_CERT=/path/fullchain.pem TLS_KEY=/path/privkey.pem
```

With `TLS=acme` the environment uses the `connected` profile: certbot (and
nginx, for OCSP stapling) can reach the internet. A temporary self-signed
certificate is served for the first few seconds until Let's Encrypt has
issued the real one. To leave the staging CA, run the command again without
`ACME_STAGING=1`, after `make uat-down` and
`docker volume rm buffalo-uat_letsencrypt` (so the staging certificate is not
kept).

## `proxy`: behind the server's existing nginx

```
browser ──https──▶ host nginx (TLS for demo.example.org, :443)
                     │  plain HTTP, X-Forwarded-For / X-Forwarded-Proto
                     ▼
               127.0.0.1:8090 ──▶ UAT nginx container ──▶ app, Reverb (wss)
```

```sh
make uat-up EDGE=proxy DEMO_HOST=demo.example.org EDGE_PORT=8090
make edge-host-config | sudo tee /etc/nginx/sites-available/buffalo-demo
sudo ln -s /etc/nginx/sites-available/buffalo-demo /etc/nginx/sites-enabled/
sudoedit /etc/nginx/sites-available/buffalo-demo    # replace the two CHANGE-ME certificate paths
sudo nginx -t && sudo systemctl reload nginx
```

- The UAT nginx container publishes **only** plain HTTP on
  `127.0.0.1:$EDGE_PORT` (default 8090). No other UAT service publishes a
  port, and nothing listens on a public address except your host nginx.
- `X-Forwarded-For` and `X-Forwarded-Proto` are honoured only from the
  Docker gateway address. Connections to the published port arrive from
  it. From any other peer the headers are ignored. Laravel itself trusts no
  proxies.
- `make edge-host-config` prints the host's server block for `DEMO_HOST`.
  It has the WebSocket upgrade, `X-Forwarded-Proto https`, the larger proxy
  buffers BUFFALO's headers need (without them: 502), and
  `client_max_body_size`. If `map $http_upgrade $connection_upgrade` is
  already defined elsewhere in your nginx configuration, delete it from
  the block.
- The certificate for `DEMO_HOST` lives in the host's nginx (for example
  from the host's certbot package). The go-live page shows its item as
  "Check on the host".

`make uat-up DEMO_HOST=... DEMO_PORT=...` (from before edge modes) still means
`EDGE=proxy`.

## Live updates (wss)

In every mode, live updates (notifications, incident and threat events) use
WebSockets at `wss://<host>/app/...` on the page's own host and port. The
frontend takes the WebSocket address from the page, so the same build works
everywhere. Reverb accepts connections only from pages on `DEMO_HOST` (its
allowed origins, set by `make uat-up`). In proxy mode the `Host` header must
be the demo host name, which the generated block passes on.

## A site password (`BASIC_AUTH=1`, UAT only)

```sh
make uat-up BASIC_AUTH=1      # add it (any mode); BASIC_AUTH=0 removes it
```

The UAT nginx then asks for a username and password before anything else,
including the sign-in page. The password is generated on the first start
and **printed once**. Only its hash is stored, in `.uat/secrets/basic_auth`.
`make uat-credentials` issues a new one together with new account
credentials; the change applies at once, with no restart. Production never
has this: its start-up check refuses it.

## Accounts

On the **first** start (and only then), the output lists each demo
account's email, password and TOTP secret, followed by its TOTP setup URI:

```
  sd.lagos@uat.buffalo.test  otpauth://totp/BUFFALO%20UAT:sd.lagos%40uat.buffalo.test?secret=...&issuer=BUFFALO%20UAT
```

Open the URI on a phone, or turn it into a QR code, to add the account to an
authenticator app. **Copy the credentials now**: they are not stored
anywhere and are not shown again. To issue new ones later:

```sh
make uat-credentials
```

## Stop, restart, remove

```sh
make uat-down              # stop the demo; its data and accounts are kept
make uat-up                # start it again (same mode and host, same data, no new credentials)
make uat-down PURGE=1      # stop it and delete its data (volumes)
make uat-up                # then: a fresh demo, new credentials printed once
make uat-down UAT_PURGE=1  # also remove .uat/ (settings, secrets, mode, host)
```

In proxy mode, remove the server block from the host nginx when the demo is
retired.

## Checking a mode end to end

`scripts/uat/edge-check.sh` (test tooling) checks the current mode:

- signing in through the edge;
- a live notification arriving over `wss://`;
- the login history recording the client's address rather than a forged
  `X-Forwarded-For`;
- with `EDGE_BASIC_AUTH=user:password`, the site password asked first.

In proxy mode it runs a stand-in for the host nginx from the
`make edge-host-config` output.

## Notes

- **Synthetic data only.** Never load real data into the demo. The UAT
  seed refuses databases that hold non-UAT accounts.
- **The demo is the UAT build.** It runs the production images with
  `APP_ENV=uat` and passes the same start-up checks (`buffalo:production:check
  --uat`, which also accepts `local` and the site password). It always shows
  the UAT banner, and its accounts are on `uat.buffalo.test`.
- **An unusual Docker set-up.** In proxy mode the UAT nginx trusts forwarded
  headers from the default gateway of its network, found when it starts (its
  log shows "trusting X-Forwarded-* from …"). If your Docker forwards
  published ports from a different address, set
  `BUFFALO_EDGE_GATEWAY=<address>` in `.uat/buffalo.env` and run `make uat-up`
  again.
