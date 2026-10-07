#!/bin/sh
#
# Creates BUFFALO's secret files with strong random values (Phase 9A).
# Never prints a value, never overwrites an existing file (re-running only
# fills gaps). Files: mode 0444 inside a directory only root may enter
# (0700): Docker bind-mounts each file into the containers that list it,
# where non-root users must read it.
#
# Usage: scripts/ops/generate-secrets.sh [--dir /etc/buffalo/secrets] [--dry-run]
#
# Escrow app_key, ciphersweet_key, pgbackrest_passphrase and restic_password
# with the keys (docs/security/key-management.md) as soon as they exist:
# without them, encrypted data and backups cannot be recovered.
#
# Not generated here: tls_key (from your CA, or a placeholder with ACME),
# mail_password (from your mail relay; empty when mail is off).
set -eu

dir=/etc/buffalo/secrets
dry_run=false
while [ $# -gt 0 ]; do
    case "$1" in
        --dir) dir=$2; shift 2 ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) echo "Unknown option: $1 (see --help)" >&2; exit 2 ;;
    esac
done

rand() { # rand <bytes>: hex
    od -An -tx1 -N "$1" /dev/urandom | tr -d ' \n'
}

put() { # put <name> <value>
    if [ -e "$dir/$1" ]; then
        echo "keep  $1"
        return 0
    fi
    if $dry_run; then
        echo "would create $1"
        return 0
    fi
    umask 0333
    printf '%s' "$2" > "$dir/$1"
    chmod 0444 "$dir/$1"
    echo "made  $1"
}

$dry_run || { mkdir -p "$dir"; chmod 0700 "$dir"; }

put app_key "base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
put ciphersweet_key "$(rand 32)"
for name in db_password db_migrations_password db_readonly_password db_admin_password \
    redis_password valkey_admin_password reverb_app_key health_token; do
    put "$name" "$(rand 24)"
done
put reverb_app_secret "$(rand 32)"
put pgbackrest_passphrase "$(rand 32)"
put restic_password "$(rand 32)"
put mail_password ""
