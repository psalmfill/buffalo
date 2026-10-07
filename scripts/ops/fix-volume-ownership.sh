#!/bin/sh
#
# One-time fix for installations created before Phase 8C (Phase 9A.1).
#
# Phase 8C runs Valkey as `valkey` (999:1000) and ClamAV as `clamav`
# (1000:1000) from their first process. Volumes created earlier hold files
# owned by root and must be handed over once. Idempotent: files already
# owned correctly are left alone, so it is safe to run again.
#
# Usage: scripts/ops/fix-volume-ownership.sh [--project NAME] [--dry-run]
#
#   --project NAME  Compose project name (default: buffalo)
#   --dry-run       Show what would change; change nothing
#
# Stop Valkey and ClamAV first (docker compose stop valkey clamav).
set -eu

project=buffalo
dry_run=false
while [ $# -gt 0 ]; do
    case "$1" in
        --project) project=$2; shift 2 ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
    esac
done

# An image every installation already has, with a busybox chown/find.
image=$(docker image ls --format '{{.Repository}}:{{.Tag}}' | grep -E '/valkey:|^buffalo/valkey:' | head -n 1)
[ -n "$image" ] || { echo "No BUFFALO valkey image found; load the release images first." >&2; exit 1; }

fix() { # fix <volume> <uid:gid>
    volume="${project}_$1"
    owner=$2
    if ! docker volume inspect "$volume" >/dev/null 2>&1; then
        echo "skip  $volume (does not exist)"
        return 0
    fi
    wrong=$(docker run --rm --network none --user 0 --entrypoint sh -v "$volume":/d "$image" \
        -c "find /d \( ! -user ${owner%:*} -o ! -group ${owner#*:} \) | wc -l")
    if [ "$wrong" -eq 0 ]; then
        echo "ok    $volume (already owned by $owner)"
    elif $dry_run; then
        echo "would $volume: chown -R $owner ($wrong paths)"
    else
        docker run --rm --network none --user 0 --entrypoint chown -v "$volume":/d "$image" -R "$owner" /d
        echo "fixed $volume: $wrong paths now owned by $owner"
    fi
}

fix valkey_data 999:1000
fix clamav_data 1000:1000
