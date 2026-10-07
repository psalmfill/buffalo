#!/usr/bin/env bash
# Imports ClamAV signature files into the clamav volume (Phase 8B.4), for
# servers without internet access. Prepare the files on a connected machine
# (see docs/security/malware-scanning.md), copy them here, then:
#
#   make clamav-import-signatures dir=/path/to/signatures
#
# The directory must hold main, daily and bytecode databases (.cvd or .cld;
# matching .cvd.sign files are copied too). Each file is checked with
# sigtool before anything is replaced; clamd then reloads them.
set -euo pipefail

dir="${1:-}"
if [[ -z "$dir" || ! -d "$dir" ]]; then
    echo "Usage: make clamav-import-signatures dir=/path/to/signatures" >&2
    exit 1
fi
dir="$(cd "$dir" && pwd)"

# Development: compose.yml and .env in the repository. A production
# installation (Phase 9B): the release directory and its settings file
# (/etc/buffalo/buffalo.env, or BUFFALO_CONFIG), whose COMPOSE_FILE lists
# the installation's compose files.
cd "$(dirname "$0")/.."
config="${BUFFALO_CONFIG:-}"
if [[ -z "$config" && ! -f .env && -f /etc/buffalo/buffalo.env ]]; then
    config=/etc/buffalo/buffalo.env
fi
compose() {
    if [[ -n "$config" ]]; then docker compose --env-file "$config" "$@"; else docker compose "$@"; fi
}

for db in main daily bytecode; do
    if [[ ! -f "$dir/$db.cvd" && ! -f "$dir/$db.cld" ]]; then
        echo "Missing $db.cvd (or $db.cld) in $dir." >&2
        exit 1
    fi
done

# A throwaway clamav container (its networks are internal: no route out):
# verify, then copy into the volume.
compose run --rm --no-deps -T \
    --entrypoint sh -v "$dir":/import:ro clamav -c '
        set -e
        for f in /import/*.cvd /import/*.cld; do
            [ -f "$f" ] || continue
            sigtool --info "$f" | grep -E "^(File|Build time|Version|Signatures|Verification)" || { echo "Invalid: $f" >&2; exit 1; }
        done
        for f in /import/*.cvd /import/*.cld /import/*.cvd.sign; do
            [ -f "$f" ] || continue
            cp "$f" /var/lib/clamav/
        done
        echo "Signatures copied."
    '

if compose ps --status running --services | grep -qx clamav; then
    compose exec -T clamav clamdscan --reload
else
    compose up -d clamav
fi

echo "Done. Check with: buffalo:malware:check-signatures (make artisan in development)."
