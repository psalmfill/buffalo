#!/bin/sh
# BUFFALO wrapper around the ClamAV image's /init (Phase 8B.4).
# Signatures are updated online (freshclam daemon) only when
# CLAMAV_ONLINE_UPDATES=true. Air-gapped deployments import signature files
# with `make clamav-import-signatures`; clamd then serves those.
set -eu

if [ "${CLAMAV_ONLINE_UPDATES:-false}" = "true" ]; then
    export CLAMAV_NO_FRESHCLAMD=false
else
    export CLAMAV_NO_FRESHCLAMD=true
    if [ ! -f /var/lib/clamav/main.cvd ] && [ ! -f /var/lib/clamav/main.cld ]; then
        echo "No ClamAV signatures and online updates are off: run make clamav-import-signatures." >&2
        exit 1
    fi
fi

exec /init "$@"
