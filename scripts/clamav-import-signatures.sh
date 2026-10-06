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

for db in main daily bytecode; do
    if [[ ! -f "$dir/$db.cvd" && ! -f "$dir/$db.cld" ]]; then
        echo "Missing $db.cvd (or $db.cld) in $dir." >&2
        exit 1
    fi
done

# A throwaway container on no network: verify, then copy into the volume.
docker compose run --rm --no-deps -T --network none \
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
        chown clamav:clamav /var/lib/clamav/*
        echo "Signatures copied."
    '

if docker compose ps --status running --services | grep -qx clamav; then
    docker compose exec -T clamav clamdscan --reload
else
    docker compose up -d clamav
fi

echo "Done. Check with: make artisan cmd=\"buffalo:malware:check-signatures\""
