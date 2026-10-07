# Shared helpers for the restore scripts (Phase 8C.3). Sourced, not run.
# shellcheck shell=bash

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

DRY_RUN=false
ASSUME_YES=false

# Production installations (Phase 9A) keep their settings in
# /etc/buffalo/buffalo.env (BUFFALO_CONFIG to override) and their secrets as
# files; development uses .env in the repository.
CONFIG_FILE="${BUFFALO_CONFIG:-}"
if [[ -z "$CONFIG_FILE" && ! -f .env && -f /etc/buffalo/buffalo.env ]]; then
    CONFIG_FILE=/etc/buffalo/buffalo.env
fi
[[ -z "$CONFIG_FILE" || -r "$CONFIG_FILE" ]] || { echo "Cannot read $CONFIG_FILE (run as root)." >&2; exit 1; }
# Arguments for every `docker compose` call. Production: the settings file,
# whose COMPOSE_FILE names the installation's compose files.
ENV_ARGS=()
[[ -n "$CONFIG_FILE" ]] && ENV_ARGS=(--env-file "$CONFIG_FILE")

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

# A value from the environment, else the settings file or .env (never printed).
env_value() {
    local name=$1 value file=${CONFIG_FILE:-.env}
    value="${!name:-}"
    if [[ -z "$value" && -f "$file" ]]; then
        value=$(grep -E "^${name}=" "$file" | tail -1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/')
    fi
    printf '%s' "$value"
}

# The file holding a backup secret: NAME_FILE, else the installation's
# secret file in BUFFALO_SECRETS_DIR (production). Empty if neither.
secret_file() {
    local name=$1 file dir base=""
    file=$(env_value "${name}_FILE")
    if [[ -z "$file" ]]; then
        dir=$(env_value BUFFALO_SECRETS_DIR)
        case "$name" in
            BACKUP_PGBACKREST_PASSPHRASE) base=pgbackrest_passphrase ;;
            BACKUP_RESTIC_PASSWORD) base=restic_password ;;
        esac
        [[ -n "$dir" && -n "$base" ]] && file="$dir/$base"
    fi
    printf '%s' "$file"
}

# The release's own images (no download needed on an air-gapped host).
IMAGE_PREFIX=$(env_value BUFFALO_IMAGE_PREFIX)
IMAGE_TAG=$(env_value BUFFALO_VERSION)
POSTGRES_IMAGE="${BUFFALO_POSTGRES_IMAGE:-${IMAGE_PREFIX:-buffalo}/postgres:${IMAGE_TAG:-dev}}"
RESTIC_IMAGE="${BUFFALO_RESTIC_IMAGE:-${IMAGE_PREFIX:-buffalo}/restic:${IMAGE_TAG:-dev}}"
PROJECT="${COMPOSE_PROJECT_NAME:-$(env_value COMPOSE_PROJECT_NAME)}"
PROJECT="${PROJECT:-buffalo}"
SOURCE_PROJECT="$PROJECT"

# docker run arguments that pass a backup secret: a mounted file, or NAME.
secret_args() {
    local name=$1 file
    file=$(secret_file "$name")
    if [[ -n "$file" ]]; then
        printf -- '-v %s:/run/secrets/%s:ro -e %s_FILE=/run/secrets/%s' "$file" "$name" "$name" "$name"
    else
        [[ -n "$(env_value "$name")" ]] || die "$name (or ${name}_FILE) is not set."
        printf -- '-e %s' "$name"
    fi
}

export_secret() {
    local name=$1
    [[ -n "$(secret_file "$name")" ]] || export "$name=$(env_value "$name")"
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
