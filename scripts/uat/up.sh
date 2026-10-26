#!/usr/bin/env bash
#
# make uat-up: the UAT environment (Phase 9B.5), a separate Compose project
# (buffalo-uat) using the production images with APP_ENV=uat, seeded with
# synthetic data and one account per role, whose passwords and TOTP secrets
# are printed once.
#
#   UAT_VERSION=uat (image tag; built from this tree unless UAT_REUSE_IMAGES=1)
#   UAT_HTTPS_PORT=19443, UAT_HTTP_PORT=19080 (local mode, bound to 127.0.0.1)
#   UAT_INCIDENTS=3000
#
# Edge mode: how the UAT nginx meets the outside world
# (docs/operations/demo-hosting.md, "Which mode do I need?"):
#   EDGE=local      (default) https://localhost:19443 on this machine only,
#                   self-signed certificate
#   EDGE=direct     a dedicated server: nginx publishes 80 and 443 on all
#                   interfaces and terminates TLS for DEMO_HOST, with
#                     TLS_CERT=fullchain.pem TLS_KEY=privkey.pem  (files), or
#                     TLS=acme ACME_EMAIL=you@example.org [ACME_STAGING=1]
#                     (Let's Encrypt, renewed automatically; needs port 80
#                     reachable from the internet and outbound HTTPS), or
#                     TLS=self-signed (browsers will warn)
#   EDGE=proxy      a shared server, behind its existing nginx: plain HTTP on
#                   127.0.0.1:$EDGE_PORT (default 8090) only;
#                   make edge-host-config prints the host's server block
#   DEMO_HOST=demo.example.org   the public host name (direct and proxy)
#   BASIC_AUTH=1    a site-wide username and password in front of everything
#                   (printed once; only its hash is kept); BASIC_AUTH=0 removes it
#
# Every choice is remembered in .uat/buffalo.env, so a later `make uat-up`
# needs no flags. Older demo settings (DEMO_HOST, DEMO_PORT without EDGE)
# still mean EDGE=proxy.
#
# Settings and secrets live in .uat/ (git-ignored). Refuses if they say
# APP_ENV=production. `make uat-down` stops the environment (PURGE=1 also
# deletes its data).
set -euo pipefail
cd "$(dirname "$0")/../.."

version=${UAT_VERSION:-uat}
https_port=${UAT_HTTPS_PORT:-19443}
http_port=${UAT_HTTP_PORT:-19080}
dir="$PWD/.uat"
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

if [[ -f "$dir/buffalo.env" ]] && grep -q '^APP_ENV=production' "$dir/buffalo.env"; then
    die "$dir/buffalo.env sets APP_ENV=production. The UAT environment never runs in production mode."
fi

if [[ "${UAT_REUSE_IMAGES:-0}" == 1 ]] && docker image inspect "buffalo/app:$version" >/dev/null 2>&1; then
    say "Reusing buffalo/*:$version images"
else
    say "Building the production images ($version)"
    scripts/build-release-images.sh "$version"
fi

if [[ ! -f "$dir/buffalo.env" ]]; then
    say "Preparing settings and secrets in .uat/"
    mkdir -p "$dir/tls"
    sh scripts/ops/generate-secrets.sh --dir "$dir/secrets" >/dev/null
    sed -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=buffalo-uat#" \
        -e "s#^BUFFALO_VERSION=.*#BUFFALO_VERSION=$version#" \
        -e "s#^BUFFALO_PROFILE=.*#BUFFALO_PROFILE=airgapped#" \
        -e "s#^BUFFALO_SECRETS_DIR=.*#BUFFALO_SECRETS_DIR=$dir/secrets#" \
        -e "s#^BUFFALO_CONFIG=.*#BUFFALO_CONFIG=$dir/buffalo.env#" \
        -e "s#^BUFFALO_TLS_CERTIFICATE_FILE=.*#BUFFALO_TLS_CERTIFICATE_FILE=$dir/tls/fullchain.pem#" \
        -e "s#^BUFFALO_EDGE=.*#BUFFALO_EDGE=local#" \
        deploy/buffalo.env.example > "$dir/buffalo.env"
    printf '\n# UAT: its own frontend bridge, so it can run beside a production install.\nBUFFALO_FRONTEND_BRIDGE=bflo-uat-front\n' >> "$dir/buffalo.env"
fi

# Settings in .uat/buffalo.env: read, set (replaced or appended), remove.
remembered() { sed -n "s/^$1=//p" "$dir/buffalo.env" | tail -n 1; }
setting() {
    local key=$1 value=$2 file="$dir/buffalo.env"
    if grep -q "^$key=" "$file"; then
        sed -i.bak "s#^$key=.*#$key=$value#" "$file" && rm -f "$file.bak"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}
unset_setting() { sed -i.bak "/^$1=/d" "$dir/buffalo.env" && rm -f "$dir/buffalo.env.bak"; }

# A secret file in .uat/secrets (0700): written in place, so a file already
# bind-mounted into a running container sees the new content.
write_secret() { # write_secret NAME MODE < content
    chmod u+w "$dir/secrets"
    [[ -f "$dir/secrets/$1" ]] && chmod u+w "$dir/secrets/$1"
    cat > "$dir/secrets/$1"
    chmod "$2" "$dir/secrets/$1"
    chmod 0700 "$dir/secrets"
}

# --- Edge mode ----------------------------------------------------------------
demo_host=${DEMO_HOST:-$(remembered DEMO_HOST)}
edge=${EDGE:-$(remembered BUFFALO_EDGE)}
if [[ -z "${EDGE:-}" && -n "${DEMO_HOST:-}" && "$edge" != direct ]]; then
    edge=proxy   # `make uat-up DEMO_HOST=...` (before edge modes) meant a proxied demo
fi
if [[ -z "$edge" ]]; then
    # A .uat/ from before edge modes: a remembered demo host meant the proxied demo.
    if [[ -n "$demo_host" ]]; then edge=proxy; else edge=local; fi
fi
case "$edge" in local|direct|proxy) ;; *) die "EDGE must be local, direct or proxy." ;; esac

if [[ "$edge" != local ]]; then
    [[ -n "$demo_host" ]] || die "EDGE=$edge needs DEMO_HOST (the public host name, e.g. demo.example.org)."
    [[ "$demo_host" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] \
        || die "DEMO_HOST must be a host name (e.g. demo.example.org), without scheme or port."
fi

edge_port=${EDGE_PORT:-${DEMO_PORT:-$(remembered BUFFALO_EDGE_PORT)}}
edge_port=${edge_port:-$(remembered DEMO_PORT)}
edge_port=${edge_port:-8090}
[[ "$edge_port" =~ ^[0-9]+$ ]] || die "EDGE_PORT must be a port number."
[[ "$edge" == proxy || -z "${EDGE_PORT:-}${DEMO_PORT:-}" ]] || die "EDGE_PORT (DEMO_PORT) is for EDGE=proxy."

# TLS in BUFFALO's nginx: self-signed (local), files, ACME or self-signed (direct).
tls=self-signed
if [[ "$edge" == direct ]]; then
    if [[ -n "${TLS_CERT:-}${TLS_KEY:-}" ]]; then
        [[ -n "${TLS_CERT:-}" && -n "${TLS_KEY:-}" ]] || die "Give both TLS_CERT and TLS_KEY."
        tls=files
    elif [[ -n "${TLS:-}" ]]; then
        tls=$TLS
    else
        tls=$(remembered UAT_TLS)
        [[ "$tls" == none ]] && tls=""   # remembered from proxy mode
    fi
    case "$tls" in
        files|acme|self-signed) ;;
        "") die "EDGE=direct needs a certificate: TLS_CERT=... TLS_KEY=..., or TLS=acme ACME_EMAIL=..., or TLS=self-signed." ;;
        *) die "TLS must be acme or self-signed (or give TLS_CERT and TLS_KEY)." ;;
    esac
elif [[ -n "${TLS:-}${TLS_CERT:-}${TLS_KEY:-}" ]]; then
    die "TLS, TLS_CERT and TLS_KEY are for EDGE=direct (EDGE=proxy: the host's nginx holds the certificate)."
fi

basic_auth=${BASIC_AUTH:-$(remembered UAT_BASIC_AUTH)}
basic_auth=${basic_auth:-0}
[[ "$basic_auth" =~ ^[01]$ ]] || die "BASIC_AUTH must be 1 or 0."

extra=${UAT_EXTRA_COMPOSE:-$(remembered UAT_EXTRA_COMPOSE)}
[[ "$extra" == none ]] && extra=""

# --- Certificate ----------------------------------------------------------------
tls_host=localhost
[[ "$edge" == direct ]] && tls_host=$demo_host
case "$tls" in
    self-signed)
        if [[ "$edge" != proxy ]] && { [[ "$(remembered UAT_TLS)" != self-signed || "$(remembered UAT_TLS_HOST)" != "$tls_host" ]] || [[ ! -s "$dir/tls/fullchain.pem" ]]; }; then
            say "Self-signed certificate for $tls_host"
            mkdir -p "$dir/tls"
            openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 365 -subj "/CN=$tls_host" \
                -addext "subjectAltName=DNS:$tls_host" -keyout "$dir/tls.key" -out "$dir/tls/fullchain.pem" 2>/dev/null
            write_secret tls_key 0444 < "$dir/tls.key"
            rm -f "$dir/tls.key"
            setting UAT_TLS_HOST "$tls_host"
        fi
        ;;
    files)
        if [[ -n "${TLS_CERT:-}" ]]; then
            [[ -r "$TLS_CERT" && -r "$TLS_KEY" ]] || die "Cannot read TLS_CERT or TLS_KEY."
            [[ "$(openssl x509 -noout -pubkey -in "$TLS_CERT" 2>/dev/null)" == "$(openssl pkey -pubout -in "$TLS_KEY" 2>/dev/null)" ]] \
                || die "TLS_KEY is not the private key of TLS_CERT."
            say "Installing the certificate from TLS_CERT and TLS_KEY"
            mkdir -p "$dir/tls"
            cp "$TLS_CERT" "$dir/tls/fullchain.pem"
            write_secret tls_key 0444 < "$TLS_KEY"
            setting UAT_TLS_HOST "$demo_host"
        fi
        ;;
    acme)
        acme_email=${ACME_EMAIL:-$(remembered ACME_EMAIL)}
        [[ -n "$acme_email" ]] || die "TLS=acme needs ACME_EMAIL (Let's Encrypt's expiry notices go there)."
        setting ACME_EMAIL "$acme_email"
        if [[ "${ACME_STAGING:-0}" == 1 ]]; then
            setting ACME_SERVER https://acme-staging-v02.api.letsencrypt.org/directory
        elif [[ -n "${ACME_SERVER:-}" ]]; then
            setting ACME_SERVER "$ACME_SERVER"
        elif [[ "$(remembered UAT_TLS)" != acme ]]; then
            setting ACME_SERVER ""
        fi
        ;;
esac

# Inputs read: from here on, settings come from .uat/buffalo.env only. make
# passes every input, empty when not given, and Compose lets an exported
# variable override the --env-file (an empty ACME_SERVER would silently
# replace the remembered staging CA with Let's Encrypt's production CA).
unset EDGE DEMO_HOST EDGE_PORT DEMO_PORT TLS TLS_CERT TLS_KEY ACME_EMAIL ACME_STAGING ACME_SERVER BASIC_AUTH UAT_EXTRA_COMPOSE

# --- Settings -------------------------------------------------------------------
profile=airgapped
[[ "$tls" == acme ]] && profile=connected   # certbot and OCSP need outbound HTTPS
files="compose.yml:compose.prod.yml:compose.profile-$profile.yml:compose.uat.yml"
[[ "$edge" == proxy ]] && files="$files:compose.edge-proxy.yml"
[[ "$tls" == acme ]] && files="$files:compose.acme.yml"
[[ "$basic_auth" == 1 ]] && files="$files:compose.uat-basic-auth.yml"
[[ -n "$extra" ]] && files="$files:$extra"

setting COMPOSE_FILE "$files"
setting BUFFALO_PROFILE "$profile"
setting BUFFALO_EDGE "$edge"
setting UAT_TLS "$([[ "$edge" == proxy ]] && echo none || echo "$tls")"
setting UAT_BASIC_AUTH "$basic_auth"
setting UAT_EXTRA_COMPOSE "${extra:-none}"
unset_setting DEMO_PORT
if [[ -n "$(remembered BUFFALO_DEMO_GATEWAY)" ]]; then
    setting BUFFALO_EDGE_GATEWAY "$(remembered BUFFALO_DEMO_GATEWAY)"
    unset_setting BUFFALO_DEMO_GATEWAY
fi
case "$edge" in
    local)
        setting APP_URL "https://localhost:$https_port"
        setting BUFFALO_SERVER_NAME localhost
        setting REVERB_ALLOWED_ORIGINS localhost
        setting BUFFALO_BIND_ADDRESS 127.0.0.1
        setting BUFFALO_HTTP_PORT "$http_port"
        setting BUFFALO_HTTPS_PORT "$https_port"
        unset_setting DEMO_HOST
        ;;
    direct|proxy)
        setting APP_URL "https://$demo_host"
        setting BUFFALO_SERVER_NAME "$demo_host"
        setting REVERB_ALLOWED_ORIGINS "$demo_host"
        setting DEMO_HOST "$demo_host"
        setting BUFFALO_BIND_ADDRESS "$([[ "$edge" == direct ]] && echo 0.0.0.0 || echo 127.0.0.1)"
        setting BUFFALO_HTTP_PORT 80
        setting BUFFALO_HTTPS_PORT 443
        setting BUFFALO_EDGE_PORT "$edge_port"
        ;;
esac

dc() { docker compose --env-file "$dir/buffalo.env" "$@"; }
project=$(remembered COMPOSE_PROJECT_NAME)

# --- Site password (BASIC_AUTH=1) -----------------------------------------------------
if [[ "$basic_auth" == 1 && ! -s "$dir/secrets/basic_auth" ]]; then
    scripts/uat/basic-auth.sh issue
elif [[ "$basic_auth" == 0 && -e "$dir/secrets/basic_auth" ]]; then
    chmod u+w "$dir/secrets" && rm -f "$dir/secrets/basic_auth" && chmod 0700 "$dir/secrets"
fi

case "$edge" in
    local) say "Edge mode local: https://localhost:$https_port (self-signed)" ;;
    direct) say "Edge mode direct: https://$demo_host (ports 80 and 443 on all interfaces; certificate: $tls)" ;;
    proxy) say "Edge mode proxy: https://$demo_host (TLS at this host's nginx -> http://127.0.0.1:$edge_port)" ;;
esac
say "Checking the published ports against the edge mode"
scripts/ops/edge-check.sh "$dir/buffalo.env"
[[ "$tls" == acme ]] && scripts/ops/acme-prepare.sh "$project"

say "Starting PostgreSQL and Valkey"
dc up -d --wait postgres valkey
say "Database roles and migrations"
dc run --rm -T tools php artisan buffalo:db:provision-roles --database=buffalo --no-interaction >/dev/null
dc run --rm -T tools php artisan migrate --force --no-interaction >/dev/null

say "Seeding synthetic data and the UAT accounts (email, password and TOTP setup URI printed ONCE, on the first run)"
dc run --rm -T tools php artisan buffalo:uat:seed --if-empty --incidents="${UAT_INCIDENTS:-3000}" --no-interaction

say "Starting every service"
# --remove-orphans: services of an earlier mode (certbot after TLS=acme).
dc up -d --wait --remove-orphans
# A first go-live result for System health (it then runs daily): in UAT it
# reports "not ready" by design (demo data, test accounts).
dc run --rm -T tools php artisan buffalo:go-live:check --record --no-interaction >/dev/null
echo
case "$edge" in
    local) say "UAT ready: https://localhost:$https_port (self-signed certificate). Banner: UAT — TEST DATA ONLY." ;;
    direct) say "Demo ready: https://$demo_host. Banner: UAT — TEST DATA ONLY." ;;
    proxy)
        say "Demo ready behind this host's nginx: https://$demo_host -> http://127.0.0.1:$edge_port. Banner: UAT — TEST DATA ONLY."
        say "The host's nginx server block: make edge-host-config"
        ;;
esac
