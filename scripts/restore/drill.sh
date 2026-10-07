#!/usr/bin/env bash
#
# Restore drill (Phase 8C.3): restores the latest backups into an ISOLATED
# Compose project, verifies them, reports timings against the RTO, and
# tears the project down. Run monthly (docs/security/backup-and-recovery.md).
#
# Usage: scripts/restore/drill.sh [options]     (or: make restore-drill)
#
#   --source-project NAME  Project whose backups are drilled (default: buffalo).
#   --target-time TIME     Point-in-time recovery instead of the latest state.
#   --wrong-keys           Negative test: run the checks with a random
#                          CIPHERSWEET_KEY; the drill passes only if the
#                          key check REFUSES the restore.
#   --all-evidence         Check every evidence file (default: a sample).
#   --keep                 Leave the drill project running (inspect it with
#                          docker compose -p buffalo-drill ...); remove it
#                          later with --teardown.
#   --teardown             Remove a kept drill project and exit.
#   --no-report            Do not write docs/drills/<date>.md.
#   --help                 This help.
#
# The report holds only results and timings, never data.
set -euo pipefail
source "$(dirname "$0")/common.sh"

DRILL=buffalo-drill
TARGET_TIME=""
WRONG_KEYS=false
KEEP=false
REPORT=true
VERIFY_ARGS=()

while [[ $# -gt 0 ]]; do
    case "$1" in
        --source-project) SOURCE_PROJECT=$2; shift 2 ;;
        --target-time) TARGET_TIME=$2; shift 2 ;;
        --wrong-keys) WRONG_KEYS=true; shift ;;
        --all-evidence) VERIFY_ARGS+=(--all-evidence); shift ;;
        --keep) KEEP=true; shift ;;
        --teardown) TEARDOWN_ONLY=true; shift ;;
        --no-report) REPORT=false; shift ;;
        --help|-h) sed -n '2,/^set -euo/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option $1 (see --help)" ;;
    esac
done

export DRILL_SOURCE_PROJECT=$SOURCE_PROJECT
# Production: the release images and secrets, never any egress overlay.
if [[ -n "$CONFIG_FILE" ]]; then
    BASE_FILES=(compose.yml compose.prod.yml compose.profile-airgapped.yml)
else
    BASE_FILES=(compose.yml)
fi
FILES=(); RESTORE_FILES=()
for f in "${BASE_FILES[@]}" docker/drill/compose.drill.yml; do FILES+=(-f "$f"); RESTORE_FILES+=(--compose-file "$f"); done
[[ "$(env_value APP_ENV)" == "local" ]] && FILES+=(-f docker/drill/compose.drill-dev.yml)
drill() { docker compose -p "$DRILL" "${ENV_ARGS[@]+"${ENV_ARGS[@]}"}" "${FILES[@]}" "$@"; }
teardown() { drill down -v --remove-orphans >/dev/null 2>&1 || true; }

if [[ "${TEARDOWN_ONLY:-false}" == true ]]; then
    teardown; say "Drill project removed."; exit 0
fi

rto_hours=${BUFFALO_RTO_HOURS:-4}
started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
t0=$(date +%s)


say "Restore drill: $SOURCE_PROJECT -> $DRILL (isolated)"
teardown

step_start=$(date +%s)
pit_args=()
[[ -n "$TARGET_TIME" ]] && pit_args=(--target-time "$TARGET_TIME")
scripts/restore/restore-database.sh --project "$DRILL" --source-project "$SOURCE_PROJECT" \
    "${RESTORE_FILES[@]}" "${pit_args[@]+"${pit_args[@]}"}" --yes
t_database=$(( $(date +%s) - step_start ))

step_start=$(date +%s)
scripts/restore/restore-evidence.sh --project "$DRILL" --source-project "$SOURCE_PROJECT" --yes
t_evidence=$(( $(date +%s) - step_start ))

drill up -d --no-deps --wait valkey >/dev/null

step_start=$(date +%s)
verify_env=()
$WRONG_KEYS && verify_env=(-e "CIPHERSWEET_KEY=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')")
manifest=$(latest_manifest)
set +e
verify_output=$(drill run --rm --no-deps -T "${verify_env[@]+"${verify_env[@]}"}" app \
    php artisan buffalo:restore:verify --manifest-json="$manifest" --json "${VERIFY_ARGS[@]+"${VERIFY_ARGS[@]}"}" 2>/dev/null)
verify_code=$?
set -e
t_verify=$(( $(date +%s) - step_start ))
total=$(( $(date +%s) - t0 ))

echo "$verify_output"
if $WRONG_KEYS; then
    if [[ $verify_code -ne 0 ]] && printf '%s' "$verify_output" | tr -d '\n ' | grep -q '"keys":{"ok":false'; then
        result=pass; summary="Wrong CIPHERSWEET_KEY refused by the key fingerprint check, as required."
    else
        result=fail; summary="A wrong CIPHERSWEET_KEY was NOT refused."
    fi
else
    if [[ $verify_code -eq 0 ]]; then result=pass; summary="Restore verified."; else result=fail; summary="Restore verification failed."; fi
fi
within_rto=$([[ $total -le $(( rto_hours * 3600 )) ]] && echo yes || echo no)

backup_label=$(printf '%s' "$manifest" | sed -n 's/.*"backup":"\([^"]*\)".*/\1/p')
checks=$(printf '%s' "$verify_output" | python3 -c '
import json,sys
try:
    d=json.load(sys.stdin)
    for k,v in d["checks"].items(): print("| %s | %s | %s |" % (k.replace("_"," "), "pass" if v["ok"] else "**fail**", v["detail"].replace("|","/")))
except Exception: print("| verify | **no output** | |")' 2>/dev/null)

report=$(cat <<MD
# Restore drill $(date -u +%Y-%m-%d) ($result)

- Started: $started_at (UTC); source project: \`$SOURCE_PROJECT\`; drill project: \`$DRILL\` (isolated, torn down afterwards)
- Backup restored: \`${backup_label:-unknown}\`$([[ -n "$TARGET_TIME" ]] && echo "; point in time: $TARGET_TIME")
- Mode: $($WRONG_KEYS && echo "negative test with a wrong CIPHERSWEET_KEY" || echo "normal")
- Result: **$result**. $summary

| Step | Seconds |
|---|---|
| Database restore (pgBackRest restore + WAL replay) | ${t_database} |
| Evidence restore (restic) | ${t_evidence} |
| Verification (buffalo:restore:verify) | ${t_verify} |
| **Total** | **$total** |

Within the RTO of ${rto_hours} hours: **$within_rto**.

| Check | Result | Detail |
|---|---|---|
$checks
MD
)
printf '\n%s\n' "$report"

if $REPORT; then
    suffix=""
    $WRONG_KEYS && suffix="-wrong-keys"
    [[ -n "$TARGET_TIME" ]] && suffix="$suffix-pitr"
    file="docs/drills/$(date -u +%Y-%m-%d-%H%M)$suffix.md"
    mkdir -p docs/drills
    printf '%s\n' "$report" > "$file"
    say "Report: $file"
fi

# Last drill, for System health (backup_status volume of the source project).
# The host name lets the go-live check show where the drill ran (Phase 9B.6).
printf '{"at":"%s","result":"%s","seconds":%s,"within_rto":%s,"mode":"%s","host":"%s"}\n' \
    "$started_at" "$result" "$total" "$([[ $within_rto == yes ]] && echo true || echo false)" \
    "$($WRONG_KEYS && echo wrong-keys || ([[ -n "$TARGET_TIME" ]] && echo pitr || echo full))" \
    "$(hostname | tr -cd 'A-Za-z0-9.-')" |
    docker run --rm -i --platform linux/amd64 --network none --user 0 -v "$(volume "$SOURCE_PROJECT" backup_status)":/s --entrypoint sh "$POSTGRES_IMAGE" \
        -c 'mkdir -p /s/drill && cat > /s/drill/last.json && chmod 0755 /s/drill && chmod 0644 /s/drill/last.json'

if $KEEP; then
    say "Drill project kept: docker compose -p $DRILL ${FILES[*]} ...; remove with $0 --teardown"
else
    teardown
    say "Drill project removed."
fi

[[ $result == pass ]]
