#!/usr/bin/env bash
#
# Restore evidence files from restic (Phase 8C.3).
#
# Usage: scripts/restore/restore-evidence.sh [options]
#
#   --project NAME          Compose project to restore INTO (default: buffalo).
#   --source-project NAME   Project whose backup_repo holds the backups
#                           (default: same as --project).
#   --snapshot ID           Snapshot to restore (default: latest).
#   --path PATH             Restore only this file or directory, relative
#                           to the evidence volume (repeatable), e.g.
#                           original/01m3.../original.jpg. Default: everything.
#   --uid UID / --gid GID   Owner of the restored files (default: DOCKER_UID /
#                           DOCKER_GID from the environment or .env, else 1000).
#   --list                  List the snapshots and exit.
#   --dry-run               Show what would be restored; change nothing.
#   --yes                   Do not ask for confirmation.
#   --help                  This help.
#
# Files are restored as they were backed up (originals unaltered); existing
# files with the same name are overwritten, other files are left alone.
# Check their hashes afterwards with verify-restore.sh (--all-evidence).
set -euo pipefail
source "$(dirname "$0")/common.sh"

SNAPSHOT=latest
PATHS=()
LIST=false
SOURCE_SET=false
RUN_UID=$(env_value DOCKER_UID); RUN_UID=${RUN_UID:-1000}
RUN_GID=$(env_value DOCKER_GID); RUN_GID=${RUN_GID:-1000}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --project) PROJECT=$2; shift 2 ;;
        --source-project) SOURCE_PROJECT=$2; SOURCE_SET=true; shift 2 ;;
        --snapshot) SNAPSHOT=$2; shift 2 ;;
        --path) PATHS+=("/evidence/${2#/}"); shift 2 ;;
        --uid) RUN_UID=$2; shift 2 ;;
        --gid) RUN_GID=$2; shift 2 ;;
        --list) LIST=true; shift ;;
        --dry-run) DRY_RUN=true; shift ;;
        --yes) ASSUME_YES=true; shift ;;
        --help|-h) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option $1 (see --help)" ;;
    esac
done
$SOURCE_SET || SOURCE_PROJECT=$PROJECT

require_volume "$(volume "$SOURCE_PROJECT" backup_repo)"
docker volume create "$(volume "$PROJECT" evidence_data)" >/dev/null
# A new volume is created owned by root: give its top directory to the
# application user (as the app image does), so restic can restore into it.
docker run --rm --network none --user 0 -v "$(volume "$PROJECT" evidence_data)":/evidence --entrypoint sh "$RESTIC_IMAGE" \
    -c "chown $RUN_UID:$RUN_GID /evidence && chmod 0750 /evidence"

restic() {
    local password
    password=$(env_value BACKUP_RESTIC_PASSWORD_FILE)
    local secret=(-e "RESTIC_PASSWORD=$(env_value BACKUP_RESTIC_PASSWORD)")
    [[ -n "$password" ]] && secret=(-v "$password":/run/secrets/restic:ro -e RESTIC_PASSWORD_FILE=/run/secrets/restic)
    docker run --rm --network none --user "$RUN_UID:$RUN_GID" \
        -v "$(volume "$SOURCE_PROJECT" backup_repo)":/backup:ro \
        -v "$(volume "$PROJECT" evidence_data)":/evidence \
        -e RESTIC_CACHE_DIR=/tmp/restic-cache "${secret[@]}" \
        "$RESTIC_IMAGE" -r /backup/restic --no-lock "$@"
}

if $LIST; then
    restic snapshots
    exit 0
fi

includes=()
for p in "${PATHS[@]+"${PATHS[@]}"}"; do includes+=(--include "$p"); done

if $DRY_RUN; then
    say "Dry run: files that would be restored from snapshot $SNAPSHOT"
    restic restore "$SNAPSHOT" --target / "${includes[@]+"${includes[@]}"}" --dry-run --verbose=2 | tail -20
    exit 0
fi

what="all evidence"; [[ ${#PATHS[@]} -gt 0 ]] && what="${#PATHS[@]} path(s)"
confirm "This restores $what from snapshot $SNAPSHOT into project '$PROJECT'."

start=$(date +%s)
restic restore "$SNAPSHOT" --target / "${includes[@]+"${includes[@]}"}"
say "Evidence restored in $(( $(date +%s) - start ))s."
