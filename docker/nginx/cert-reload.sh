#!/bin/sh
# Reloads nginx when its TLS certificate changes (Phase 9A): ACME renewals
# (certbot) and replaced CA certificates take effect without a restart.
# Runs from /docker-entrypoint.d before nginx starts; the loop continues in
# the background. Checks every 15 minutes.
set -eu
[ -n "${BUFFALO_TLS_CERTIFICATE:-}" ] || exit 0
(
    last=""
    while true; do
        sleep 900
        current=$(sha256sum "$BUFFALO_TLS_CERTIFICATE" 2>/dev/null | cut -d' ' -f1 || true)
        if [ -n "$last" ] && [ -n "$current" ] && [ "$current" != "$last" ]; then
            echo "TLS certificate changed: reloading nginx" >&2
            nginx -s reload || true
        fi
        last=$current
    done
) &
