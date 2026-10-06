#!/bin/sh
# Prepares the pgBackRest environment, then runs the given command
# (Phase 8C.1). Used by the `postgres` service (archive-push inherits the
# environment of the server) and the `pgbackrest` sidecar.
#
# - Cipher passphrase: BACKUP_PGBACKREST_PASSPHRASE or *_FILE (a Docker
#   secret). Required: archiving refuses to run without it.
# - Repository 2: only when BACKUP_REPO2_ENABLED=true; the deployment mounts
#   its disk, NFS share or removable media at /backup-repo2 (BACKUP_REPO2_PATH
#   in compose). Same cipher, its own retention.
set -eu

secret() {
    file_var="${1}_FILE"
    eval "file=\${$file_var:-}"
    if [ -n "$file" ]; then
        cat "$file"
    else
        eval "printf '%s' \"\${$1:-}\""
    fi
}

PGBACKREST_REPO1_CIPHER_PASS="$(secret BACKUP_PGBACKREST_PASSPHRASE)"
if [ -z "$PGBACKREST_REPO1_CIPHER_PASS" ]; then
    echo "BACKUP_PGBACKREST_PASSPHRASE (or _FILE) is required for encrypted backups." >&2
    exit 1
fi
case "$PGBACKREST_REPO1_CIPHER_PASS" in
    buffalo-dev-*)
        if [ "${APP_ENV:-production}" = "production" ]; then
            echo "BACKUP_PGBACKREST_PASSPHRASE is the development value; refused in production." >&2
            exit 1
        fi ;;
esac
if [ "${#PGBACKREST_REPO1_CIPHER_PASS}" -lt 24 ]; then
    echo "BACKUP_PGBACKREST_PASSPHRASE must be at least 24 characters." >&2
    exit 1
fi
export PGBACKREST_REPO1_CIPHER_PASS
export PGBACKREST_REPO1_RETENTION_FULL="${BACKUP_PG_RETENTION_FULL:-4}"
export PGBACKREST_PG1_USER="${POSTGRES_USER:-buffalo}"

if [ "${BACKUP_REPO2_ENABLED:-false}" = "true" ]; then
    export PGBACKREST_REPO2_PATH=/backup-repo2/pgbackrest
    export PGBACKREST_REPO2_CIPHER_TYPE=aes-256-cbc
    export PGBACKREST_REPO2_CIPHER_PASS="$PGBACKREST_REPO1_CIPHER_PASS"
    export PGBACKREST_REPO2_RETENTION_FULL="${BACKUP_PG_RETENTION_FULL:-4}"
    export PGBACKREST_REPO2_RETENTION_FULL_TYPE=count
fi

# Never hand secrets to anything but pgBackRest and PostgreSQL.
unset BACKUP_PGBACKREST_PASSPHRASE BACKUP_PGBACKREST_PASSPHRASE_FILE

exec "$@"
