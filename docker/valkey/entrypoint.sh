#!/bin/sh
# Valkey with ACL users (Phase 8A). The default user is disabled, so
# unauthenticated clients are refused.
#
#   VALKEY_APP_USERNAME / VALKEY_APP_PASSWORD   the application (Laravel)
#   VALKEY_ADMIN_PASSWORD                       optional operator account
#
# Passwords may be given as NAME_FILE (a Docker secret) instead of NAME.
# The ACL file stores SHA-256 hashes, never the passwords. The application
# user may not run FLUSHALL, FLUSHDB, CONFIG, KEYS, DEBUG or other
# administrative commands (so `php artisan cache:clear` needs the operator
# account).
set -eu

secret() {
    eval "file=\${${1}_FILE:-}"
    if [ -n "$file" ]; then
        tr -d '\r\n' < "$file"
    else
        eval "printf '%s' \"\${${1}:-}\""
    fi
}

hash() {
    printf '%s' "$1" | sha256sum | cut -d' ' -f1
}

APP_USER=${VALKEY_APP_USERNAME:-buffalo}
APP_PASSWORD=$(secret VALKEY_APP_PASSWORD)
ADMIN_PASSWORD=$(secret VALKEY_ADMIN_PASSWORD)

if [ -z "$APP_PASSWORD" ]; then
    echo "valkey: VALKEY_APP_PASSWORD (or VALKEY_APP_PASSWORD_FILE) is required." >&2
    exit 1
fi

BLOCKED="-flushall -flushdb -config -keys -debug -shutdown -monitor -acl -module -replicaof -slaveof -save -bgsave -bgrewriteaof -failover -cluster -migrate -swapdb -sync -psync"

ACL=/tmp/users.acl
umask 077
{
    echo "user default off resetchannels -@all"
    echo "user ${APP_USER} on #$(hash "$APP_PASSWORD") ~* &* +@all ${BLOCKED}"
    if [ -n "$ADMIN_PASSWORD" ]; then
        echo "user admin on #$(hash "$ADMIN_PASSWORD") ~* &* +@all"
    fi
} > "$ACL"

exec valkey-server --aclfile "$ACL" "$@"
