#!/bin/sh
#
# BUFFALO upgrade (Phase 9A.4). Run the copy in the INSTALLED release:
#
#   /opt/buffalo/current/scripts/ops/upgrade.sh <new release directory> [options]
#
#   --config FILE           Installation settings (default /etc/buffalo/buffalo.env)
#   --install-dir DIR       Installation directory (default /opt/buffalo)
#   --registry PREFIX       Pull the new images from a registry instead of the
#                           images archive (verified against the signed manifest)
#   --worker-timeout SEC    Time queue workers get to finish jobs (default 300)
#   --skip-backup           Do not take the pre-upgrade backups (test systems only)
#   --allow-test-signature  Accept a release signed with the development TEST key
#   --yes                   Skip the typed confirmation (automation)
#   --dry-run               Verify and show what would happen; change nothing
#   --help                  This help
#
# Steps: verify the new release with the key of the installed release;
# refuse a version older than the installed one; show the release notes;
# ask for typed confirmation; take a full pgBackRest backup and a restic
# snapshot; switch the site to the maintenance page; stop workers (letting
# jobs finish); load and verify the new images; start the new database
# image and run migrations; reseed reference data; start everything; run
# smoke checks; leave maintenance mode.
#
# It stops at the first error and leaves the site on the maintenance page.
# The database is NEVER rolled back automatically: follow the printed
# instructions and docs/operations/rollback.md.
set -eu

new_dir=""
config=/etc/buffalo/buffalo.env
install_dir=/opt/buffalo
registry=""
worker_timeout=300
skip_backup=false
allow_test=false
assume_yes=false
dry_run=false

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
run() { if $dry_run; then printf '   (dry run) %s\n' "$*"; else "$@"; fi; }

while [ $# -gt 0 ]; do
    case "$1" in
        --config) config=$2; shift 2 ;;
        --install-dir) install_dir=$2; shift 2 ;;
        --registry) registry=$2; shift 2 ;;
        --worker-timeout) worker_timeout=$2; shift 2 ;;
        --skip-backup) skip_backup=true; shift ;;
        --allow-test-signature) allow_test=true; shift ;;
        --yes) assume_yes=true; shift ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        -*) die "Unknown option: $1 (see --help)" ;;
        *) new_dir=$1; shift ;;
    esac
done
[ -n "$new_dir" ] || die "Give the directory holding the new release (see --help)."
new_dir=$(cd "$new_dir" && pwd)
case "$config" in /*) ;; *) config="$(pwd)/$config" ;; esac
case "$install_dir" in /*) ;; *) install_dir="$(pwd)/$install_dir" ;; esac
[ -f "$config" ] || die "No settings file at $config."
setting() { sed -n "s/^$1=//p" "$config" | tail -n 1; }
current_version=$(setting BUFFALO_VERSION)
[ -n "$current_version" ] || die "BUFFALO_VERSION is not set in $config: is BUFFALO installed?"
current_dir="$install_dir/releases/$current_version"
[ -d "$current_dir" ] || die "The installed release $current_version is not in $current_dir."

# --- Verify with the installed release's key -----------------------------------
say "Verifying the new release with the key of installed release $current_version"
cd "$new_dir"
[ -f SHA256SUMS ] && [ -f SHA256SUMS.sig ] || die "SHA256SUMS and SHA256SUMS.sig are required in $new_dir."
signer=""
for principal in buffalo-release buffalo-release-test; do
    if ssh-keygen -q -Y verify -f "$current_dir/security/release-signing.pub" -I "$principal" -n buffalo-release \
        -s SHA256SUMS.sig < SHA256SUMS >/dev/null 2>&1; then
        signer=$principal
        break
    fi
done
[ -n "$signer" ] || die "The release signature is INVALID (altered, or not signed by the key trusted by $current_version). Refusing."
if [ "$signer" = buffalo-release-test ]; then
    $allow_test || die "This release is signed with the development TEST key. Refusing (--allow-test-signature on test systems only)."
    warn "Release signed with the development TEST key (accepted: --allow-test-signature)."
fi
sha256() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }
while read -r sum file; do
    file=${file#\*}
    if [ ! -f "$file" ]; then
        case "$file" in *-images.tar.zst) [ -n "$registry" ] && continue ;; esac
        die "$file is listed in SHA256SUMS but missing."
    fi
    [ "$(sha256 "$file")" = "$sum" ] || die "Checksum mismatch for $file: the release is damaged or has been altered. Refusing."
done < SHA256SUMS
bundle=$(ls buffalo-*-bundle.tar.zst 2>/dev/null | head -n 1)
[ -n "$bundle" ] && grep -q " ${bundle}\$" SHA256SUMS || die "No signed bundle in $new_dir."
version=${bundle#buffalo-}; version=${version%-bundle.tar.zst}
say "Release $version: signature ($signer) and checksums OK"

# --- Version order ------------------------------------------------------------------
older() { # older A B: true when A < B (x.y.z; a pre-release sorts before its release)
    [ "$1" != "$2" ] || return 1
    a=${1%%-*}; b=${2%%-*}
    if [ "$a" = "$b" ]; then
        case "$1" in *-*) case "$2" in *-*) [ "$(printf '%s\n%s\n' "$1" "$2" | sort | head -n 1)" = "$1" ]; return ;; esac; return 0 ;; esac
        return 1
    fi
    [ "$(printf '%s\n%s\n' "$a" "$b" | sort -t. -k1,1n -k2,2n -k3,3n | head -n 1)" = "$a" ]
}
[ "$version" != "$current_version" ] || die "Release $version is already installed."
if older "$version" "$current_version"; then
    die "Release $version is OLDER than the installed $current_version. Refusing: going back is a restore (docs/operations/rollback.md), not an upgrade."
fi

# --- Notes and confirmation ---------------------------------------------------------
echo
sed 's/^/   /' RELEASE-NOTES.md
echo
if ! $assume_yes; then
    [ -t 0 ] || die "Confirmation needed: run interactively, or pass --yes."
    printf 'The site will be unavailable during the upgrade. Type "upgrade to %s" to continue: ' "$version"
    read -r answer
    [ "$answer" = "upgrade to $version" ] || die "Cancelled."
fi

# From the release directory: COMPOSE_FILE (in the settings file) is relative to it.
compose() { (cd "$install_dir/current" && docker compose --env-file "$config" "$@"); }
project=$(setting COMPOSE_PROJECT_NAME)
project=${project:-buffalo}
prefix=${registry:-$(setting BUFFALO_IMAGE_PREFIX)}
prefix=${prefix:-buffalo}
current_prefix=$(setting BUFFALO_IMAGE_PREFIX)
# A small image the installed release already has (busybox sh, runs as root here).
helper_image="${current_prefix:-buffalo}/valkey:$current_version"

maintenance() { # maintenance on|off: the flag file nginx checks (no application needed)
    if [ "$1" = on ]; then cmd='touch /m/on'; else cmd='rm -f /m/on'; fi
    run docker run --rm --network none --user 0 --entrypoint sh -v "${project}_maintenance:/m" "$helper_image" -c "$cmd"
}

stage="before the maintenance page"
backup_label=""
maintenance_on=false
on_exit() {
    status=$?
    [ "$status" -eq 0 ] && return
    $dry_run && return
    cat >&2 <<FAILED

UPGRADE FAILED $stage.
$($maintenance_on && echo "The site is still on the maintenance page." || echo "The site was not switched to the maintenance page; release $current_version is still running.") Nothing has been rolled back.

Follow docs/operations/rollback.md ($current_dir/docs/operations/rollback.md):
  - Failed BEFORE migrations: the database is unchanged. Return to $current_version:
        ln -sfn releases/$current_version $install_dir/current
        sed -i 's/^BUFFALO_VERSION=.*/BUFFALO_VERSION=$current_version/' $config
        cd $install_dir/current && docker compose --env-file $config up -d --wait
    then leave maintenance mode (rollback.md, step 5).
  - Failed DURING or AFTER migrations: restore the pre-upgrade backup${backup_label:+ (set $backup_label)}
    with scripts/restore/restore-database.sh --set ${backup_label:-<label>} --backup-only,
    then return to $current_version as above.
Last migration output and service logs: docker compose ... logs --tail 200
FAILED
}
trap on_exit EXIT

# --- Backups --------------------------------------------------------------------
if $skip_backup; then
    warn "Skipping the pre-upgrade backups (--skip-backup)."
else
    stage="during the pre-upgrade backups"
    say "Full database backup (pgBackRest)"
    run compose exec -T pgbackrest backup-entrypoint.sh pgbackrest-scheduler.sh now full
    if ! $dry_run; then
        backup_label=$(compose exec -T pgbackrest backup-entrypoint.sh pgbackrest --stanza=buffalo --output=json info \
            | tr ',' '\n' | sed -n 's/.*"label":"\([^"]*F\)".*/\1/p' | tail -n 1)
        echo "   backup set: ${backup_label:-unknown}"
    fi
    say "Evidence snapshot (restic)"
    run compose exec -T backup restic-scheduler.sh now backup
fi

# --- Maintenance page, stop workers ------------------------------------------------
stage="while entering maintenance mode"
say "Switching the site to the maintenance page"
maintenance on
maintenance_on=true
stage="while stopping the application"
say "Stopping queue workers and the scheduler (up to ${worker_timeout}s for running jobs)"
run compose stop -t "$worker_timeout" horizon scheduler
run compose stop app reverb

# --- New release -------------------------------------------------------------------
stage="while installing the new release files and images (before migrations)"
target="$install_dir/releases/$version"
say "Unpacking release $version"
if [ ! -d "$target" ] && ! $dry_run; then
    zstd -dc "$new_dir/$bundle" | tar -C "$install_dir/releases" -xf - && mv "$install_dir/releases/buffalo-$version" "$target"
fi
if [ -n "$registry" ]; then say "Pulling images from $registry"; else
    say "Loading images"
    $dry_run || zstd -dc "$new_dir/buffalo-$version-images.tar.zst" | docker load -q >/dev/null
fi
if ! $dry_run; then
    while read -r name id platform; do
        ref="$prefix/$name:$version"
        [ -n "$registry" ] && docker pull -q --platform "$platform" "$ref" >/dev/null
        actual=$(docker image inspect --format '{{.Id}}' "$ref" 2>/dev/null) || die "Image $ref is missing."
        [ "$actual" = "$id" ] || die "Image $ref does not match the signed manifest. Refusing."
    done < "$target/images.lock"
    echo "   all images match the signed manifest"
fi

say "Switching to release $version"
run ln -sfn "releases/$version" "$install_dir/current"
if ! $dry_run; then
    sed -i.bak -e "s/^BUFFALO_VERSION=.*/BUFFALO_VERSION=$version/" "$config" && rm -f "$config.bak"
    grep -q '^BUFFALO_PREVIOUS_VERSION=' "$config" \
        && sed -i.bak -e "s/^BUFFALO_PREVIOUS_VERSION=.*/BUFFALO_PREVIOUS_VERSION=$current_version/" "$config" && rm -f "$config.bak" \
        || printf 'BUFFALO_PREVIOUS_VERSION=%s\n' "$current_version" >> "$config"
fi

say "Starting the new PostgreSQL and Valkey images"
run compose up -d --wait postgres valkey pgbackrest

# --- Migrations ----------------------------------------------------------------
stage="during or after the database migrations"
say "Running migrations (as the owner role)"
run compose run --rm -T tools php artisan buffalo:db:provision-roles --database="$(setting DB_DATABASE)" --no-interaction
run compose run --rm -T tools php artisan migrate --force --no-interaction
say "Updating BUFFALO's reference data"
for seeder in RolesAndPermissionsSeeder ZonesAndStatesSeeder ReferenceDataSeeder; do
    run compose run --rm -T tools php artisan db:seed --class="Database\\Seeders\\$seeder" --force --no-interaction
done

# --- Start and smoke checks -----------------------------------------------------------
stage="while starting release $version (migrations already applied)"
say "Starting all services"
run compose up -d --wait --remove-orphans
say "Smoke checks"
if ! $dry_run; then
    pending=$(compose run --rm -T tools php artisan migrate:status --pending --no-interaction 2>&1 || true)
    echo "$pending" | grep -q "Pending" && die "Migrations are still pending."
    compose exec -T app php artisan buffalo:production:check --no-interaction >/dev/null || die "Production configuration check failed."
    compose exec -T app php artisan buffalo:keys:verify --no-interaction >/dev/null || die "Encryption key verification failed."
    # Through PHP-FPM directly (nginx still shows the maintenance page):
    # /up checks the database and Valkey and answers exactly "up".
    body=$(compose exec -T app sh -c 'SCRIPT_NAME=/index.php SCRIPT_FILENAME=/var/www/html/public/index.php REQUEST_METHOD=GET REQUEST_URI=/up QUERY_STRING= SERVER_NAME=localhost HTTPS=on cgi-fcgi -bind -connect 127.0.0.1:9000 2>/dev/null | tail -n 1' || true)
    [ "$body" = up ] || die "The application health route did not answer 'up' (got '$body')."
    # nginx answers TLS (the maintenance page: 503).
    code=$(compose exec -T nginx sh -c 'wget -q -S -O /dev/null --no-check-certificate https://127.0.0.1:8443/login 2>&1 | sed -n "s/^  HTTP\/[0-9.]* \([0-9]*\).*/\1/p" | tail -n 1' || true)
    [ "$code" = 503 ] || die "nginx did not answer HTTPS with the maintenance page (got '$code')."
    echo "   migrations complete; production checks, keys, database and Valkey OK; HTTPS answering"
fi

stage="while leaving maintenance mode"
say "Leaving maintenance mode"
maintenance off
trap - EXIT
echo
say "BUFFALO upgraded from $current_version to $version."
echo "   Previous release kept in $current_dir (rollback.md). Check System health in the application."
