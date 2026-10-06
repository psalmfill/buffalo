#!/bin/sh
# BUFFALO wrapper around the ClamAV image's /init-unprivileged (Phase 8B.4, 8C.5).
#
# - Signatures are updated online (freshclam daemon) only when
#   CLAMAV_ONLINE_UPDATES=true. Air-gapped deployments import signature
#   files with `make clamav-import-signatures`; clamd then serves those.
# - Supervision: the image's /init starts clamd in the background and then
#   waits forever, so a clamd that dies (e.g. killed when memory runs out)
#   would leave the container running without a scanner. This wrapper exits
#   when clamd stops, so Docker's restart policy starts a fresh one.
#   Uploads are refused (evidence quarantined) in the meantime.
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

# A live (not zombie) clamd process. /init's `tail` never reaps it, so a
# dead clamd stays in /proc as a zombie: check the process state too.
clamd_running() {
    for stat in /proc/[0-9]*/stat; do
        case "$(cat "$stat" 2>/dev/null)" in
            *" (clamd) Z "*) ;;
            *" (clamd) "*) return 0 ;;
        esac
    done
    return 1
}

# Unprivileged (Phase 8C.5): the container runs as clamav with a read-only
# root filesystem; configuration comes from the mounted clamd.conf.
/init-unprivileged "$@" &
init_pid=$!
trap 'kill "$init_pid" 2>/dev/null; exit 0' TERM INT

# Wait for clamd to start (loading signatures takes up to a minute).
until clamd_running; do
    if ! kill -0 "$init_pid" 2>/dev/null; then
        echo "ClamAV failed to start." >&2
        exit 1
    fi
    sleep 5
done

while clamd_running; do
    sleep 10
done

echo "clamd stopped; exiting so the container is restarted." >&2
exit 1
