#!/bin/sh
# pgBackRest schedule (Phase 8C.1), run by the `pgbackrest` sidecar as the
# postgres user, sharing the data volume and Unix socket with `postgres`.
#
# Africa/Lagos times (TZ): full backup Sunday 01:00, differential the other
# days 01:00, `check` daily 03:30, `verify` Saturday 04:00. WAL is archived
# continuously by PostgreSQL itself (archive_command). Every minute the WAL
# archiving state is recorded.
#
# Status for the application (System health, alerts) is written as JSON to
# /backup-status/pgbackrest (a volume the app mounts read-only): no data,
# only times, results, sizes and the key fingerprints of each backup.
#
# After each backup, /backup/manifests/<label>.json records the active key
# fingerprints (Phase 8A), so a restore can confirm it has matching keys.
set -u

STANZA=buffalo
STATUS=/backup-status/pgbackrest
PGDATA_DIR=/var/lib/postgresql/18/docker
export PGHOST=/var/run/postgresql PGUSER="${POSTGRES_USER:-buffalo}" PGDATABASE="${POSTGRES_DB:-buffalo}"

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
json_str() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g' | tr -d '\n' | cut -c1-500; }

# record <task> <ok:true|false> <message>
record() {
    printf '{"task":"%s","at":"%s","ok":%s,"message":"%s"}\n' "$1" "$(now)" "$2" "$(json_str "$3")" > "$STATUS/$1.json.tmp"
    mv "$STATUS/$1.json.tmp" "$STATUS/$1.json"
}

run_task() { # run_task <task> <command...>
    task=$1; shift
    echo "[$(now)] $task: starting"
    if output=$("$@" 2>&1); then
        record "$task" true "ok"
        echo "[$(now)] $task: ok"
        return 0
    fi
    echo "$output" | tail -20
    record "$task" false "$(echo "$output" | grep -iE 'error|fatal' | tail -1)"
    echo "[$(now)] $task: FAILED"
    return 1
}

write_info() {
    pgbackrest --stanza=$STANZA --output=json info > "$STATUS/info.json.tmp" 2>/dev/null \
        && mv "$STATUS/info.json.tmp" "$STATUS/info.json"
    repo1=$(du -sk /backup/pgbackrest 2>/dev/null | cut -f1)
    repo2=null
    [ "${BACKUP_REPO2_ENABLED:-false}" = "true" ] && repo2=$(du -sk /backup-repo2/pgbackrest 2>/dev/null | cut -f1)
    printf '{"at":"%s","repo1_kb":%s,"repo2_kb":%s}\n' "$(now)" "${repo1:-null}" "${repo2:-null}" > "$STATUS/sizes.json"
}

write_manifest() {
    label=$(pgbackrest --stanza=$STANZA --output=json info | grep -o '"label":"[^"]*"' | tail -1 | cut -d'"' -f4)
    [ -n "$label" ] || return 0
    keys=$(psql -XAtqc "SELECT coalesce(json_object_agg(key_name, fingerprint), '{}') FROM key_fingerprints WHERE status = 'active'" 2>/dev/null || echo '{}')
    printf '{"backup":"%s","written_at":"%s","key_fingerprints":%s}\n' "$label" "$(now)" "$keys" > "/backup/manifests/$label.json"
    [ "${BACKUP_REPO2_ENABLED:-false}" = "true" ] && mkdir -p /backup-repo2/manifests && cp "/backup/manifests/$label.json" /backup-repo2/manifests/
    return 0
}

# WAL archiving: last archived segment, failures, and the age of the oldest
# segment waiting to be archived (the lag alert's input).
write_wal() {
    stats=$(psql -XAtqc "SELECT json_build_object('archived_count', archived_count, 'last_archived_wal', last_archived_wal, 'last_archived_time', last_archived_time, 'failed_count', failed_count, 'last_failed_time', last_failed_time) FROM pg_stat_archiver" 2>/dev/null || echo null)
    oldest=$(ls -tr "$PGDATA_DIR/pg_wal/archive_status/" 2>/dev/null | grep '\.ready$' | head -1)
    age=0
    if [ -n "$oldest" ]; then
        age=$(( $(date +%s) - $(stat -c %Y "$PGDATA_DIR/pg_wal/archive_status/$oldest") ))
    fi
    printf '{"at":"%s","oldest_ready_seconds":%s,"stats":%s}\n' "$(now)" "$age" "${stats:-null}" > "$STATUS/wal.json"
}

backup() { # backup <full|diff>
    if run_task "backup-$1" pgbackrest --stanza=$STANZA --type="$1" backup; then
        write_manifest
    fi
    write_info
}

# On demand (operators, the restore drill, CI):
#   docker compose exec pgbackrest pgbackrest-scheduler.sh now full|diff|check|verify|status
if [ "${1:-}" = "now" ]; then
    case "${2:-}" in
        full|diff) backup "$2" ;;
        check|verify) run_task "$2" pgbackrest --stanza=$STANZA "$2" ;;
        status) write_wal; write_info ;;
        *) echo "Usage: $0 now full|diff|check|verify|status" >&2; exit 2 ;;
    esac
    exit $?
fi

# Wait for PostgreSQL, then make sure the stanza exists.
until pg_isready -q; do sleep 5; done
run_task stanza-create pgbackrest --stanza=$STANZA stanza-create
write_info

# First start: take a full backup now rather than waiting for Sunday.
if ! grep -q '"type":"full"' "$STATUS/info.json" 2>/dev/null; then
    backup full
fi

last=""
while true; do
    write_wal
    minute=$(date +%H:%M)
    if [ "$minute" != "$last" ]; then
        last=$minute
        case "$minute" in
            01:00) if [ "$(date +%u)" = 7 ]; then backup full; else backup diff; fi ;;
            03:30) run_task check pgbackrest --stanza=$STANZA check ;;
            04:00) [ "$(date +%u)" = 6 ] && run_task verify pgbackrest --stanza=$STANZA verify ;;
        esac
        # Hourly refresh of sizes and backup list.
        case "$minute" in *:15) write_info ;; esac
    fi
    sleep 20
done
