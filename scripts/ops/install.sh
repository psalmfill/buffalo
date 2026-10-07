#!/bin/sh
#
# BUFFALO installer (Phase 9A.4).
#
# Usage: install.sh --profile airgapped|connected|cloud [options]
#
#   --profile P           Deployment profile (required): airgapped, connected or cloud
#   --release-dir DIR     The release files (default: this script's directory):
#                         SHA256SUMS, SHA256SUMS.sig, buffalo-<v>-bundle.tar.zst,
#                         buffalo-<v>-images.tar.zst (not needed with --registry)
#   --registry PREFIX     Pull images from a registry (connected/cloud), e.g.
#                         registry.example.gov.ng/buffalo; verified against the
#                         signed manifest before use
#   --secrets-dir DIR     Secret files (default /etc/buffalo/secrets; asked if
#                         not given and the terminal is interactive)
#   --config FILE         Installation settings (default /etc/buffalo/buffalo.env)
#   --install-dir DIR     Where releases are unpacked (default /opt/buffalo)
#   --mail-egress         connected/cloud: queue workers may reach the mail relay
#   --clamav-online       connected/cloud: ClamAV updates signatures online
#   --acme                connected/cloud: certificates from ACME (needs port 80)
#   --allow-test-signature  Accept a release signed with the development TEST key
#   --accept-low-memory   Install on a host with less than 16 GB (test systems only)
#   --skip-firewall       Do not apply the frontend egress firewall rule
#   --yes                 Do not ask for confirmations
#   --dry-run             Verify and check, but change nothing
#   --help                This help
#
# Steps: verify the release signature and checksums; check prerequisites;
# unpack the bundle; load (or pull) and verify the images; check the
# settings and secret files; start PostgreSQL, provision roles, migrate as
# the owner role and seed BUFFALO's reference data (no demo data); apply the
# egress firewall rule; start every service and wait for health checks.
# Stops at the first error. Safe to run again for the same release.
#
# First install: check the release signature yourself before trusting this
# script, with the project's public key obtained separately:
#   ssh-keygen -Y verify -f release-signing.pub -I buffalo-release -n buffalo-release \
#       -s SHA256SUMS.sig < SHA256SUMS && sha256sum -c SHA256SUMS
set -eu

# >>> release-signing.pub (embedded; must match security/release-signing.pub)
ALLOWED_SIGNERS='buffalo-release-test namespaces="buffalo-release" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHltdcovo4Nj/ogW+OHm2jV7Di9U+lI0ED9fygQ4Lwck buffalo-release-TEST-KEY (phase 9A development only)'
# <<< release-signing.pub

profile=""
release_dir=$(cd "$(dirname "$0")" && pwd)
registry=""
secrets_dir=""
config=/etc/buffalo/buffalo.env
install_dir=/opt/buffalo
mail_egress=false
clamav_online=false
acme=false
allow_test=false
low_memory=false
skip_firewall=false
assume_yes=false
dry_run=false

say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33mWarning:\033[0m %s\n' "$*" >&2; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }
run() { if $dry_run; then printf '   (dry run) %s\n' "$*"; else "$@"; fi; }
confirm() {
    $assume_yes && return 0
    [ -t 0 ] || die "$1 (run interactively, or pass --yes)"
    printf '%s [y/N] ' "$1"
    read -r answer
    case "$answer" in y|Y|yes|YES) ;; *) die "Cancelled." ;; esac
}

while [ $# -gt 0 ]; do
    case "$1" in
        --profile) profile=$2; shift 2 ;;
        --release-dir) release_dir=$(cd "$2" && pwd); shift 2 ;;
        --registry) registry=$2; shift 2 ;;
        --secrets-dir) secrets_dir=$2; shift 2 ;;
        --config) config=$2; shift 2 ;;
        --install-dir) install_dir=$2; shift 2 ;;
        --mail-egress) mail_egress=true; shift ;;
        --clamav-online) clamav_online=true; shift ;;
        --acme) acme=true; shift ;;
        --allow-test-signature) allow_test=true; shift ;;
        --accept-low-memory) low_memory=true; shift ;;
        --skip-firewall) skip_firewall=true; shift ;;
        --yes) assume_yes=true; shift ;;
        --dry-run) dry_run=true; shift ;;
        -h|--help) sed -n '2,/^set -eu/p' "$0" | sed '$d; s/^# \{0,1\}//'; exit 0 ;;
        *) die "Unknown option: $1 (see --help)" ;;
    esac
done

abspath() { case "$1" in /*) printf '%s' "$1" ;; *) printf '%s/%s' "$(pwd)" "$1" ;; esac; }
config=$(abspath "$config")
install_dir=$(abspath "$install_dir")
[ -z "$secrets_dir" ] || secrets_dir=$(abspath "$secrets_dir")

case "$profile" in
    airgapped|connected|cloud) ;;
    *) die "--profile must be airgapped, connected or cloud." ;;
esac
if [ "$profile" = airgapped ]; then
    $mail_egress || $clamav_online || $acme || [ -n "$registry" ] && \
        die "The airgapped profile has no egress: --mail-egress, --clamav-online, --acme and --registry are not available."
fi

# --- 1. Signature and checksums ---------------------------------------------
say "Verifying the release signature and checksums"
cd "$release_dir"
[ -f SHA256SUMS ] && [ -f SHA256SUMS.sig ] || die "SHA256SUMS and SHA256SUMS.sig are required in $release_dir."
signers=$(mktemp)
trap 'rm -f "$signers"' EXIT
printf '%s\n' "$ALLOWED_SIGNERS" > "$signers"
signer=""
for principal in buffalo-release buffalo-release-test; do
    if ssh-keygen -q -Y verify -f "$signers" -I "$principal" -n buffalo-release -s SHA256SUMS.sig < SHA256SUMS >/dev/null 2>&1; then
        signer=$principal
        break
    fi
done
[ -n "$signer" ] || die "The release signature is INVALID (SHA256SUMS altered, or not signed by the BUFFALO release key). Refusing."
if [ "$signer" = buffalo-release-test ]; then
    $allow_test || die "This release is signed with the development TEST key. Refusing (pass --allow-test-signature on test systems only)."
    warn "Release signed with the development TEST key (accepted: --allow-test-signature)."
fi
fingerprint=$(printf '%s\n' "$ALLOWED_SIGNERS" | grep "^$signer " | cut -d' ' -f3- | ssh-keygen -lf - | awk '{print $2}')
say "Signed by '$signer', key $fingerprint"

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
[ -n "$bundle" ] || die "No bundle in $release_dir."
grep -q " ${bundle}\$" SHA256SUMS || die "The bundle is not covered by SHA256SUMS."
version=${bundle#buffalo-}; version=${version%-bundle.tar.zst}
say "Release $version: signature and checksums OK"
confirm "Confirm the key fingerprint $fingerprint matches the one published by the BUFFALO project. Continue?"

# --- 2. Prerequisites --------------------------------------------------------
say "Checking prerequisites"
for tool in docker zstd ssh-keygen tar; do command -v "$tool" >/dev/null || die "$tool is required."; done
version_ge() { [ "$(printf '%s\n%s\n' "$2" "$1" | sort -t. -k1,1n -k2,2n -k3,3n | head -n 1)" = "$2" ]; }
docker_v=$(docker version --format '{{.Server.Version}}' 2>/dev/null) || die "Cannot reach the Docker daemon."
compose_v=$(docker compose version --short 2>/dev/null) || die "Docker Compose v2 is required."
version_ge "${docker_v%%-*}" 28.0.0 || die "Docker 28 or newer is required (network gateway priority) (found $docker_v)."
version_ge "${compose_v#v}" 2.33.1 || die "Docker Compose 2.33.1 or newer is required (found $compose_v)."
mem_kb=$(awk '/MemTotal/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)
[ "$mem_kb" -gt 0 ] || mem_kb=$(( $(docker info --format '{{.MemTotal}}') / 1024 ))
if [ "$mem_kb" -lt 15500000 ]; then
    $low_memory || die "At least 16 GB of RAM is required (found $((mem_kb / 1024 / 1024)) GB). Use --accept-low-memory on test systems only."
    warn "Only $((mem_kb / 1024 / 1024)) GB of RAM (accepted: --accept-low-memory). Lower the BUFFALO_MEM_* limits."
fi
root_dir=$(docker info --format '{{.DockerRootDir}}')
free_gb=$(df -Pk "$root_dir" 2>/dev/null | awk 'NR==2 {print int($4/1024/1024)}' || echo 0)
[ "${free_gb:-0}" -ge 40 ] || warn "Only ${free_gb:-?} GB free under $root_dir; plan disk space with docs/operations/sizing.md."
if command -v timedatectl >/dev/null 2>&1; then
    [ "$(timedatectl show -p NTPSynchronized --value 2>/dev/null)" = yes ] \
        || die "The clock is not synchronised (timedatectl: NTPSynchronized=no). TOTP sign-in and the audit trail need an accurate clock: enable NTP (chrony or systemd-timesyncd)."
else
    warn "Cannot check clock synchronisation here (no timedatectl). Make sure NTP is active: TOTP and the audit trail depend on it."
fi

# --- 3. Bundle ---------------------------------------------------------------
target="$install_dir/releases/$version"
say "Unpacking the bundle to $target"
if [ -d "$target" ]; then
    echo "   already unpacked"
else
    run mkdir -p "$install_dir/releases"
    if ! $dry_run; then zstd -dc "$bundle" | tar -C "$install_dir/releases" -xf - && mv "$install_dir/releases/buffalo-$version" "$target"; fi
fi
run ln -sfn "releases/$version" "$install_dir/current"
lock="$target/images.lock"
$dry_run && [ ! -f "$lock" ] && lock=""

# --- 4. Images -----------------------------------------------------------------
prefix=${registry:-buffalo}
if [ -n "$registry" ]; then
    say "Pulling images from $registry"
else
    say "Loading images"
    images=$(ls "buffalo-$version-images.tar.zst" 2>/dev/null) || die "buffalo-$version-images.tar.zst is missing (or use --registry)."
    if ! $dry_run; then zstd -dc "$images" | docker load -q >/dev/null; fi
fi
if [ -n "$lock" ]; then
    while read -r name id platform; do
        ref="$prefix/$name:$version"
        if [ -n "$registry" ]; then run docker pull -q --platform "$platform" "$ref" >/dev/null; fi
        $dry_run && continue
        actual=$(docker image inspect --format '{{.Id}}' "$ref" 2>/dev/null) || die "Image $ref is missing."
        if [ "$actual" != "$id" ]; then
            [ -n "$registry" ] && docker image rm "$ref" >/dev/null 2>&1 || true
            die "Image $ref does not match the signed manifest ($actual != $id). Refusing."
        fi
        echo "   $ref verified"
    done < "$lock"
fi

# --- 5. Settings and secrets -----------------------------------------------------
files="compose.yml:compose.prod.yml:compose.profile-$profile.yml"
$mail_egress && files="$files:compose.egress-mail.yml"
$clamav_online && files="$files:compose.egress-clamav.yml"
$acme && files="$files:compose.acme.yml"
if [ ! -f "$config" ]; then
    run mkdir -p "$(dirname "$config")"
    run cp "$target/deploy/buffalo.env.example" "$config"
    run chmod 0640 "$config"
    $dry_run || die "Created $config from the template. Edit it (server name, APP_URL, mail, certificate path), then run this installer again."
fi
if [ -z "$secrets_dir" ]; then
    secrets_dir=$(sed -n 's/^BUFFALO_SECRETS_DIR=//p' "$config" 2>/dev/null | tail -n 1)
    if [ -t 0 ] && ! $assume_yes; then
        printf 'Secrets directory [%s]: ' "${secrets_dir:-/etc/buffalo/secrets}"
        read -r answer
        [ -n "$answer" ] && secrets_dir=$answer
    fi
    secrets_dir=${secrets_dir:-/etc/buffalo/secrets}
fi
set_setting() { # set_setting KEY VALUE (in the settings file)
    if $dry_run; then printf '   (dry run) set %s\n' "$1"; return 0; fi
    if grep -q "^$1=" "$config"; then
        sed -i.bak "s#^$1=.*#$1=$2#" "$config" && rm -f "$config.bak"
    else
        printf '%s=%s\n' "$1" "$2" >> "$config"
    fi
}
say "Recording the deployment in $config"
set_setting COMPOSE_FILE "$files"
set_setting BUFFALO_VERSION "$version"
set_setting BUFFALO_PROFILE "$profile"
set_setting BUFFALO_SECRETS_DIR "$secrets_dir"
set_setting BUFFALO_CONFIG "$config"
set_setting BUFFALO_IMAGE_PREFIX "$prefix"
[ "$(sed -n 's/^APP_URL=//p' "$config")" != "https://buffalo.example.gov.ng" ] || $dry_run \
    || die "$config still has the template's APP_URL. Set your server name, APP_URL and certificate path first."

say "Checking secret files in $secrets_dir (names only)"
missing=""
for name in app_key ciphersweet_key db_password db_migrations_password db_readonly_password db_admin_password \
    redis_password valkey_admin_password reverb_app_key reverb_app_secret mail_password health_token \
    pgbackrest_passphrase restic_password tls_key; do
    [ -f "$secrets_dir/$name" ] || missing="$missing $name"
done
if [ -n "$missing" ]; then
    $acme && [ "$missing" = " tls_key" ] && { run sh -c ": > '$secrets_dir/tls_key'"; run chmod 0444 "$secrets_dir/tls_key"; missing=""; }
fi
[ -z "$missing" ] || die "Missing secret files:$missing. Create them with scripts/ops/generate-secrets.sh --dir $secrets_dir (tls_key: your certificate's private key)."
dir_mode=$(stat -c '%a' "$secrets_dir" 2>/dev/null || stat -f '%Lp' "$secrets_dir")
[ "$dir_mode" = 700 ] || warn "$secrets_dir should be mode 0700 (it is $dir_mode)."

# From the release directory: COMPOSE_FILE (in the settings file) is relative to it.
compose() { (cd "$install_dir/current" && docker compose --env-file "$config" "$@"); }

# --- 6. Database, migrations, reference data ----------------------------------------
say "Starting PostgreSQL and Valkey"
run compose up -d --wait postgres valkey
say "Checking secrets (buffalo:secrets:check)"
run compose run --rm -T tools php artisan buffalo:secrets:check --no-interaction
say "Provisioning database roles"
run compose run --rm -T tools php artisan buffalo:db:provision-roles --database="$(sed -n 's/^DB_DATABASE=//p' "$config")" --no-interaction
say "Running migrations (as the owner role)"
run compose run --rm -T tools php artisan migrate --force --no-interaction
say "Recording the release's security scan (for the go-live check)"
if [ -f "$target/security-scan.json" ]; then
    run sh -c "cd '$install_dir/current' && docker compose --env-file '$config' run --rm -T tools php artisan buffalo:release:record < '$target/security-scan.json'"
else
    warn "This release has no security-scan.json; the go-live check will report it."
fi
say "Seeding BUFFALO's reference data (roles and permissions, zones and states, reference lists; no demo data)"
for seeder in RolesAndPermissionsSeeder ZonesAndStatesSeeder ReferenceDataSeeder; do
    run compose run --rm -T tools php artisan db:seed --class="Database\\Seeders\\$seeder" --force --no-interaction
done

if $acme; then
    say "Preparing ACME certificates (temporary self-signed certificate until the first issuance)"
    run docker run --rm --network none --user 0 --entrypoint sh -v "$(sed -n 's/^COMPOSE_PROJECT_NAME=//p' "$config")_letsencrypt":/etc/letsencrypt \
        certbot/certbot:v5.8.0@sha256:f70ad0adbb7e117f0fe42a63c553f28ea451edabc0148757b6efcd9735acaa20 -c \
        'mkdir -p /etc/letsencrypt/buffalo; [ -s /etc/letsencrypt/buffalo/fullchain.pem ] || openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 7 -subj /CN=placeholder -keyout /etc/letsencrypt/buffalo/privkey.pem -out /etc/letsencrypt/buffalo/fullchain.pem 2>/dev/null; chown -R 101:101 /etc/letsencrypt'
fi

# --- 7. Firewall, services --------------------------------------------------------------
if $skip_firewall; then
    warn "Skipping the frontend egress firewall rule (--skip-firewall): nginx could open outbound connections."
elif [ "$(uname -s)" = Linux ] && [ "$(id -u)" = 0 ]; then
    say "Applying the frontend egress firewall rule"
    run "$install_dir/current/scripts/ops/egress-firewall.sh" apply
    if command -v systemctl >/dev/null 2>&1 && ! $dry_run; then
        cat > /etc/systemd/system/buffalo-egress-firewall.service <<UNIT
[Unit]
Description=BUFFALO: no outbound connections from the frontend network
# Re-applied whenever Docker (re)starts, not only at boot.
After=docker.service
Requires=docker.service
PartOf=docker.service

[Service]
Type=oneshot
ExecStart=$install_dir/current/scripts/ops/egress-firewall.sh apply
RemainAfterExit=yes

[Install]
WantedBy=docker.service
UNIT
        systemctl daemon-reload && systemctl enable -q buffalo-egress-firewall.service
    fi
else
    warn "Not applying the frontend egress firewall rule (Linux and root required): see docs/operations/host-hardening.md."
fi

say "Starting all services"
run compose up -d --wait
$dry_run || compose ps --format 'table {{.Service}}\t{{.Status}}'

cat <<NEXT

BUFFALO $version is installed ($profile profile).

Next steps (docs/operations/runbook.md):
  1. Create the first super admin:
       cd $install_dir/current && docker compose --env-file $config \\
           exec app php artisan buffalo:create-super-admin
  2. Import the owner-supplied hierarchy (buffalo:import-hierarchy) and boundaries
     (buffalo:import-boundaries).
  3. Configure backup repository 2 (BACKUP_REPO2_ENABLED, BACKUP_REPO2_PATH).
  4. Run the first restore drill: $install_dir/current/scripts/restore/drill.sh
  5. Escrow the keys and backup passphrases in $secrets_dir (key-management.md).
NEXT
