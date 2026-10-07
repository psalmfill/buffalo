#!/usr/bin/env bash
#
# Restore the BUFFALO database from pgBackRest (Phase 8C.3).
#
# Usage: scripts/restore/restore-database.sh [options]
#
#   --project NAME          Compose project to restore INTO (default: buffalo).
#                           Its postgres_data volume is overwritten.
#   --source-project NAME   Compose project whose backup_repo holds the
#                           backups (default: same as --project).
#   --target-time TIME      Point-in-time recovery, e.g. "2026-10-06 14:31:00+01".
#                           Default: the latest state (all archived WAL).
#   --set LABEL             Restore this backup set (default: the newest
#                           that can reach the target).
#   --backup-only           Stop recovery as soon as the backup is consistent
#                           (no later WAL is replayed). With --set: return to
#                           the state of that backup, e.g. the pre-upgrade
#                           backup after a failed upgrade (Phase 9A).
#   --compose-file FILE     Extra compose file(s) for the target project
#                           (repeatable; the drill uses this).
#   --dry-run               Show the backups and what would be done; change nothing.
#   --yes                   Do not ask for confirmation.
#   --help                  This help.
#
# Steps: stops the target project's database and every service that uses
# it, restores the data directory (pgBackRest --delta), then starts
# PostgreSQL, which replays WAL up to the target and opens for writes.
# Afterwards run scripts/restore/verify-restore.sh before starting the
# application again. Full procedure: docs/security/backup-and-recovery.md.
set -euo pipefail
source "$(dirname "$0")/common.sh"

TARGET_TIME=""
SET=""
BACKUP_ONLY=false
COMPOSE_FILES=()
SOURCE_SET=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --project) PROJECT=$2; shift 2 ;;
        --source-project) SOURCE_PROJECT=$2; SOURCE_SET=true; shift 2 ;;
        --target-time) TARGET_TIME=$2; shift 2 ;;
        --set) SET=$2; shift 2 ;;
        --backup-only) BACKUP_ONLY=true; shift ;;
        --compose-file) COMPOSE_FILES+=(-f "$2"); shift 2 ;;
        --dry-run) DRY_RUN=true; shift ;;
        --yes) ASSUME_YES=true; shift ;;
        --help|-h) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option $1 (see --help)" ;;
    esac
done
$SOURCE_SET || SOURCE_PROJECT=$PROJECT
# Default: the installation's compose files (production: COMPOSE_FILE in the
# settings file; development: compose.yml).
[[ ${#COMPOSE_FILES[@]} -gt 0 || -n "$CONFIG_FILE" ]] || COMPOSE_FILES=(-f compose.yml)
compose() { docker compose -p "$PROJECT" "${ENV_ARGS[@]+"${ENV_ARGS[@]}"}" "${COMPOSE_FILES[@]+"${COMPOSE_FILES[@]}"}" "$@"; }

require_volume "$(volume "$SOURCE_PROJECT" backup_repo)"

say "Backups in $(volume "$SOURCE_PROJECT" backup_repo)"
pgbackrest_repo pgbackrest --stanza=buffalo --repo=1 info

args=(--stanza=buffalo --repo=1 --delta --log-level-console=info)
[[ -n "$SET" ]] && args+=(--set="$SET")
if $BACKUP_ONLY; then
    [[ -z "$TARGET_TIME" ]] || die "--backup-only and --target-time cannot be combined."
    args+=(--type=immediate --target-action=promote)
    say "Recovery to the end of backup ${SET:-(newest)} only (no later WAL)"
elif [[ -n "$TARGET_TIME" ]]; then
    args+=(--type=time "--target=$TARGET_TIME" --target-action=promote)
    say "Point-in-time recovery to $TARGET_TIME"
else
    say "Recovery to the latest archived state"
fi

if $DRY_RUN; then
    say "Dry run: would stop project $PROJECT, then pgbackrest ${args[*]} restore into $(volume "$PROJECT" postgres_data), then start postgres."
    exit 0
fi

confirm "This overwrites the database of project '$PROJECT'."

say "Stopping $PROJECT's database and the services that use it"
compose stop app horizon scheduler reverb pgbackrest backup nginx postgres >/dev/null 2>&1 || true

say "Restoring"
start=$(date +%s)
export_secret BACKUP_PGBACKREST_PASSPHRASE
# Created by Docker on first use; seeded with the image's layout.
docker volume create "$(volume "$PROJECT" postgres_data)" >/dev/null
# shellcheck disable=SC2046
docker run --rm --platform linux/amd64 --user 70:70 --network none \
    -v "$(volume "$PROJECT" postgres_data)":/var/lib/postgresql \
    -v "$(volume "$SOURCE_PROJECT" backup_repo)":/backup:ro \
    $(secret_args BACKUP_PGBACKREST_PASSPHRASE) -e APP_ENV="$(env_value APP_ENV)" \
    --entrypoint backup-entrypoint.sh "$POSTGRES_IMAGE" \
    sh -c 'mkdir -p /var/lib/postgresql/18/docker && chmod 0700 /var/lib/postgresql/18/docker && exec pgbackrest "$@" restore' pgbackrest "${args[@]}"
say "Data directory restored in $(( $(date +%s) - start ))s; starting PostgreSQL (WAL replay)"

start=$(date +%s)
compose up -d --no-deps --wait postgres
say "PostgreSQL open for writes after $(( $(date +%s) - start ))s."
say "Next: scripts/restore/verify-restore.sh --project $PROJECT --source-project $SOURCE_PROJECT"
