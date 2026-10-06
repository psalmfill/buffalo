# Shared helpers for the restore scripts (Phase 8C.3). Sourced, not run.
# shellcheck shell=bash

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

PROJECT="${COMPOSE_PROJECT_NAME:-buffalo}"
SOURCE_PROJECT="${COMPOSE_PROJECT_NAME:-buffalo}"
DRY_RUN=false
ASSUME_YES=false
POSTGRES_IMAGE="${BUFFALO_POSTGRES_IMAGE:-buffalo-postgres:dev}"
RESTIC_IMAGE="restic/restic:0.19.1@sha256:136600b6ff6843d61d355f7f71f460a166429f35de6fd11b568fece3c9a4d510"

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# A value from the environment or .env (never printed).
env_value() {
    local name=$1 value
    value="${!name:-}"
    if [[ -z "$value" && -f .env ]]; then
        value=$(grep -E "^${name}=" .env | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
    fi
    printf '%s' "$value"
}

# docker run arguments that pass a backup secret: NAME_FILE (mounted) or NAME.
secret_args() {
    local name=$1 file
    file=$(env_value "${name}_FILE")
    if [[ -n "$file" ]]; then
        printf -- '-v %s:/run/secrets/%s:ro -e %s_FILE=/run/secrets/%s' "$file" "$name" "$name" "$name"
    else
        [[ -n "$(env_value "$name")" ]] || die "$name (or ${name}_FILE) is not set."
        printf -- '-e %s' "$name"
    fi
}

export_secret() {
    local name=$1
    [[ -n "$(env_value "${name}_FILE")" ]] || export "$name=$(env_value "$name")"
}

volume() { printf '%s_%s' "$1" "$2"; }

require_volume() {
    docker volume inspect "$1" >/dev/null 2>&1 || die "Docker volume $1 does not exist."
}

confirm() {
    $ASSUME_YES && return 0
    $DRY_RUN && return 0
    read -r -p "$1 Type 'yes' to continue: " answer
    [[ "$answer" == "yes" ]] || die "Cancelled."
}

# pgBackRest against the source repository (read-only), as postgres.
pgbackrest_repo() {
    export_secret BACKUP_PGBACKREST_PASSPHRASE
    # shellcheck disable=SC2046
    docker run --rm --platform linux/amd64 --user 70:70 --network none \
        -v "$(volume "$SOURCE_PROJECT" backup_repo)":/backup:ro \
        $(secret_args BACKUP_PGBACKREST_PASSPHRASE) -e APP_ENV="$(env_value APP_ENV)" \
        --entrypoint backup-entrypoint.sh "$POSTGRES_IMAGE" "$@"
}

latest_manifest() {
    docker run --rm --platform linux/amd64 --network none -v "$(volume "$SOURCE_PROJECT" backup_repo)":/backup:ro --entrypoint sh "$POSTGRES_IMAGE" \
        -c 'f=$(ls -t /backup/manifests/*.json 2>/dev/null | head -1); [ -n "$f" ] && cat "$f"'
}
