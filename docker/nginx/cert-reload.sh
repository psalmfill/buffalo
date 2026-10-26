#!/bin/sh
# Reloads nginx when its TLS certificate changes (Phase 9A): ACME issuance
# and renewals (certbot) and replaced CA certificates take effect without a
# restart. Runs from /docker-entrypoint.d before nginx starts; the loop
# continues in the background. Checks every minute (one small file hashed).
#
# The hash at start is the baseline: the first ACME issuance replaces the
# placeholder seconds after nginx starts, and must be noticed (found by the
# edge mode tests: with no baseline, the first check only recorded the new
# certificate and the placeholder stayed until the next renewal).
set -eu
[ -n "${BUFFALO_TLS_CERTIFICATE:-}" ] || exit 0
hash() { sha256sum "$BUFFALO_TLS_CERTIFICATE" 2>/dev/null | cut -d' ' -f1 || true; }
(
    last=$(hash)
    while true; do
        sleep "${BUFFALO_CERT_RELOAD_SECONDS:-60}"
        current=$(hash)
        if [ -n "$current" ] && [ "$current" != "$last" ]; then
            echo "TLS certificate changed: reloading nginx" >&2
            nginx -s reload || true
        fi
        [ -z "$current" ] || last=$current
    done
) &
