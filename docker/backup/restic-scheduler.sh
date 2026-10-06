#!/bin/sh
# Evidence backups with restic (Phase 8C.2), run by the `backup` service as
# the application user (who owns the evidence files), with the evidence
# volume mounted read-only.
#
# Africa/Lagos times (TZ): backup nightly 02:00, then retention (daily 14,
# weekly 8, monthly 12; BACKUP_EVIDENCE_KEEP_*) and prune; `restic check`
# Sunday 05:00; `restic check --read-data-subset=5%` on the 1st at 05:30.
#
# Repository 1: /backup/restic (backup_repo volume). Repository 2:
# /backup-repo2/restic when BACKUP_REPO2_ENABLED=true. Both encrypted with
# the password from BACKUP_RESTIC_PASSWORD(_FILE), escrowed with the keys.
#
# Status JSON (times, results, sizes, snapshot list; never file names) goes
# to /backup-status/restic for System health and alerts.
#
# On demand: docker compose exec backup restic-scheduler.sh now backup|forget|check|check-data|status
set -u

STATUS=/backup-status/restic
SOURCE=/evidence
export RESTIC_CACHE_DIR=/tmp/restic-cache
export RESTIC_HOST=buffalo

if [ -n "${BACKUP_RESTIC_PASSWORD_FILE:-}" ]; then
    RESTIC_PASSWORD="$(cat "$BACKUP_RESTIC_PASSWORD_FILE")"
else
    RESTIC_PASSWORD="${BACKUP_RESTIC_PASSWORD:-}"
fi
unset BACKUP_RESTIC_PASSWORD BACKUP_RESTIC_PASSWORD_FILE
if [ "${#RESTIC_PASSWORD}" -lt 24 ]; then
    echo "BACKUP_RESTIC_PASSWORD (or _FILE) must be set, at least 24 characters." >&2
    exit 1
fi
case "$RESTIC_PASSWORD" in
    buffalo-dev-*) [ "${APP_ENV:-production}" = "production" ] && { echo "BACKUP_RESTIC_PASSWORD is the development value; refused in production." >&2; exit 1; } ;;
esac
export RESTIC_PASSWORD

REPOS="/backup/restic"
[ "${BACKUP_REPO2_ENABLED:-false}" = "true" ] && REPOS="$REPOS /backup-repo2/restic"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n' | cut -c1-500; }

record() { # record <task> <ok> <message>
    printf '{"task":"%s","at":"%s","ok":%s,"message":"%s"}\n' "$1" "$(now)" "$2" "$(json_str "$3")" > "$STATUS/$1.json.tmp"
    mv "$STATUS/$1.json.tmp" "$STATUS/$1.json"
}

# run_task <task> <command...>: runs the command against every repository.
run_task() {
    task=$1; shift
    echo "[$(now)] $task: starting"
    for repo in $REPOS; do
        if ! output=$(restic -r "$repo" "$@" 2>&1); then
            echo "$output" | tail -20
            record "$task" false "$repo: $(echo "$output" | grep -iE 'error|fatal' | tail -1)"
            echo "[$(now)] $task: FAILED ($repo)"
            return 1
        fi
    done
    record "$task" true "ok"
    echo "[$(now)] $task: ok"
}

write_info() {
    restic -r /backup/restic snapshots --json --compact > "$STATUS/snapshots.json.tmp" 2>/dev/null \
        && mv "$STATUS/snapshots.json.tmp" "$STATUS/snapshots.json"
    repo1=$(du -sk /backup/restic 2>/dev/null | cut -f1)
    source=$(du -sk "$SOURCE" 2>/dev/null | cut -f1)
    repo2=null
    [ "${BACKUP_REPO2_ENABLED:-false}" = "true" ] && repo2=$(du -sk /backup-repo2/restic 2>/dev/null | cut -f1)
    printf '{"at":"%s","repo1_kb":%s,"repo2_kb":%s,"evidence_kb":%s}\n' "$(now)" "${repo1:-null}" "${repo2:-null}" "${source:-null}" > "$STATUS/sizes.json"
}

heartbeat() { printf '{"at":"%s"}\n' "$(now)" > "$STATUS/heartbeat.json"; }

backup() {
    run_task backup backup "$SOURCE" --tag evidence --one-file-system \
        && run_task forget forget --tag evidence --prune \
            --keep-daily "${BACKUP_EVIDENCE_KEEP_DAILY:-14}" \
            --keep-weekly "${BACKUP_EVIDENCE_KEEP_WEEKLY:-8}" \
            --keep-monthly "${BACKUP_EVIDENCE_KEEP_MONTHLY:-12}"
    write_info
}

# Initialise repositories that do not exist yet.
for repo in $REPOS; do
    if ! restic -r "$repo" cat config >/dev/null 2>&1; then
        mkdir -p "$repo" && restic -r "$repo" init || { record init false "could not initialise $repo"; exit 1; }
    fi
done

if [ "${1:-}" = "now" ]; then
    case "${2:-}" in
        backup) backup ;;
        forget) run_task forget forget --tag evidence --prune --keep-daily "${BACKUP_EVIDENCE_KEEP_DAILY:-14}" --keep-weekly "${BACKUP_EVIDENCE_KEEP_WEEKLY:-8}" --keep-monthly "${BACKUP_EVIDENCE_KEEP_MONTHLY:-12}" ;;
        check) run_task check check ;;
        check-data) run_task check-data check --read-data-subset=5% ;;
        status) heartbeat; write_info ;;
        *) echo "Usage: $0 now backup|forget|check|check-data|status" >&2; exit 2 ;;
    esac
    exit $?
fi

# First start: back up now rather than waiting for 02:00.
write_info
grep -q '"time"' "$STATUS/snapshots.json" 2>/dev/null || backup

last=""
while true; do
    heartbeat
    minute=$(date +%H:%M)
    if [ "$minute" != "$last" ]; then
        last=$minute
        case "$minute" in
            02:00) backup ;;
            05:00) [ "$(date +%u)" = 7 ] && run_task check check ;;
            05:30) [ "$(date +%d)" = 01 ] && run_task check-data check --read-data-subset=5% ;;
            *:45) write_info ;;
        esac
    fi
    sleep 20
done
