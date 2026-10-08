# Hosting the UAT environment as a demo

The UAT environment (synthetic data only, the red "UAT — TEST DATA ONLY"
banner, accounts for every role) can be shown on a public host name behind
a host-level nginx that already terminates TLS. This is a **demo** of
the UAT build: it is never a production deployment, holds no real data, and
production is configured exactly as before (`docs/operations/installation.md`).

```
browser ──https──▶ host nginx (TLS for demo.example.org, :443)
                     │  plain HTTP, X-Forwarded-For / X-Forwarded-Proto
                     ▼
               127.0.0.1:8090 ──▶ UAT nginx container ──▶ app, Reverb (WebSockets)
```

- The UAT nginx container publishes **only** plain HTTP on
  `127.0.0.1:$DEMO_PORT` (default 8090). No other UAT service publishes a
  port, and nothing listens on a public address except your host nginx.
- `X-Forwarded-For` and `X-Forwarded-Proto` are honoured only from the
  Docker gateway address. Connections to the published port arrive from
  it; from any other peer the headers are ignored. Laravel itself trusts no
  proxies.
- Session and XSRF cookies stay `Secure`, so the demo works only over
  HTTPS.
- Live updates (notifications, incident and threat events) use WebSockets
  at `wss://$DEMO_HOST/app/...` on port 443, through the same host nginx.
  The frontend takes the WebSocket host, port and scheme from the page's own
  address, so the same build works on any host name.

## Requirements

- The BUFFALO source tree on the demo server, with Docker and Docker Compose
  (the same as for `make uat-up`, see `docs/uat/README.md`). About 6 GB of
  free memory.
- A DNS name for the demo pointing at the server, and a TLS certificate for
  it in your host nginx.

## 1. Start the demo

From the repository root:

```sh
make uat-up DEMO_HOST=demo.example.org DEMO_PORT=8090
```

This builds the production images from the source, prepares `.uat/`
(settings and secrets, never committed), migrates, seeds synthetic data and
starts every service. `DEMO_HOST` and `DEMO_PORT` are remembered in
`.uat/buffalo.env`, so a later `make uat-up` brings the same demo back.

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

## 2. Point your host nginx at it

Add a server block for the demo host to the host's nginx (for example
`/etc/nginx/sites-available/buffalo-demo`, then link it into
`sites-enabled`), with your certificate paths:

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 80;
    server_name demo.example.org;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name demo.example.org;

    ssl_certificate     /etc/letsencrypt/live/demo.example.org/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/demo.example.org/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;

    # Evidence uploads (keep in step with the application's 100 MB limit).
    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:8090;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        # WebSockets (live notifications) on the same host and port.
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_read_timeout 120s;
        proxy_buffering off;
        # BUFFALO's response headers (security policy, cookies) exceed
        # nginx's default 4-8 KB header buffer: without this, 502.
        proxy_buffer_size 32k;
        proxy_buffers 8 32k;
    }
}
```

If `map $http_upgrade $connection_upgrade` is already defined elsewhere in
your nginx configuration, leave it out here. Then:

```sh
sudo nginx -t && sudo systemctl reload nginx
```

Open `https://demo.example.org` and sign in with one of the printed
accounts.

The `Host` header must be the demo host name. Reverb accepts WebSocket
connections only from pages on `DEMO_HOST` (its allowed origins), and the
application builds its links from it.

## 3. Stop, restart, remove

```sh
make uat-down              # stop the demo; its data and accounts are kept
make uat-up                # start it again (same host, same data, no new credentials)
make uat-down PURGE=1      # stop it and delete its data (volumes)
make uat-up                # then: a fresh demo, new credentials printed once
make uat-down UAT_PURGE=1  # also remove .uat/ (settings, secrets, demo host)
```

Remove the server block from the host nginx when the demo is retired.

## 4. Changing the host name or port

```sh
make uat-down
make uat-up DEMO_HOST=new-demo.example.org DEMO_PORT=8091
```

Update `server_name` and `proxy_pass` in the host nginx to match.

## Notes

- **Synthetic data only.** Never load real data into the demo; the UAT
  seed refuses databases that hold non-UAT accounts.
- **The demo is the UAT build.** It runs the production images with
  `APP_ENV=uat` and passes the same start-up checks (`buffalo:production:check
  --uat`). It always shows the UAT banner, and its accounts are on
  `uat.buffalo.test`.
- **An unusual Docker set-up.** The demo's nginx trusts forwarded headers
  from the default gateway of its network, found when it starts (its log
  shows "trusting X-Forwarded-* from …"). If your Docker forwards published
  ports from a different address, set `BUFFALO_DEMO_GATEWAY=<address>` in
  `.uat/buffalo.env` and run `make uat-up` again.
- **Without `DEMO_HOST`,** `make uat-up` is the local UAT environment on
  `https://localhost:19443` as before. A `.uat/` that already holds a demo
  host keeps it: run `make uat-down UAT_PURGE=1` to start over.
