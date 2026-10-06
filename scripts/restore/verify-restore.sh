#!/usr/bin/env bash
#
# Verify a restored database and evidence volume (Phase 8C.3): runs
# buffalo:restore:verify in the restored project with the backup's
# manifest (key fingerprints).
#
# Usage: scripts/restore/verify-restore.sh [options]
#
#   --project NAME          Restored project (default: buffalo).
#   --source-project NAME   Project whose backup_repo holds the manifest
#                           (default: same as --project).
#   --manifest FILE         Use this manifest instead of the newest one.
#   --compose-file FILE     Extra compose file(s) (repeatable).
#   --all-evidence          Check every evidence file, not a sample.
#   --json                  JSON output (used by the drill).
#   --help                  This help.
#
# Refuses (exit 1) when the running keys do not match the backup's keys.
set -euo pipefail
source "$(dirname "$0")/common.sh"

MANIFEST=""
COMPOSE_FILES=()
EXTRA=()
SOURCE_SET=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --project) PROJECT=$2; shift 2 ;;
        --source-project) SOURCE_PROJECT=$2; SOURCE_SET=true; shift 2 ;;
        --manifest) MANIFEST=$(cat "$2"); shift 2 ;;
        --compose-file) COMPOSE_FILES+=(-f "$2"); shift 2 ;;
        --all-evidence) EXTRA+=(--all-evidence); shift ;;
        --json) EXTRA+=(--json); shift ;;
        --help|-h) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option $1 (see --help)" ;;
    esac
done
$SOURCE_SET || SOURCE_PROJECT=$PROJECT
[[ ${#COMPOSE_FILES[@]} -gt 0 ]] || COMPOSE_FILES=(-f compose.yml)

[[ -n "$MANIFEST" ]] || MANIFEST=$(latest_manifest) || true
[[ -n "$MANIFEST" ]] || die "No backup manifest found in $(volume "$SOURCE_PROJECT" backup_repo)."

docker compose -p "$PROJECT" "${COMPOSE_FILES[@]}" run --rm --no-deps -T app \
    php artisan buffalo:restore:verify --manifest-json="$MANIFEST" "${EXTRA[@]+"${EXTRA[@]}"}"
