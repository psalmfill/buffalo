#!/usr/bin/env bash
#
# make uat-up: the UAT environment (Phase 9B.5), a separate Compose project
# (buffalo-uat) using the production images with the airgapped profile and
# APP_ENV=uat, seeded with synthetic data and one account per role, whose
# passwords and TOTP secrets are printed once.
#
#   UAT_VERSION=uat (image tag; built from this tree unless UAT_REUSE_IMAGES=1)
#   UAT_HTTPS_PORT=19443, UAT_HTTP_PORT=19080 (bound to 127.0.0.1)
#   UAT_INCIDENTS=3000
#
# Hosted demo (docs/operations/demo-hosting.md), behind a host-level nginx
# that terminates TLS:
#   DEMO_HOST=demo.example.org   APP_URL=https://$DEMO_HOST, Reverb's allowed
#                                origins; adds compose.uat-demo.yml
#   DEMO_PORT=8090               nginx publishes plain HTTP on
#                                127.0.0.1:$DEMO_PORT only (nothing else)
# Both are remembered in .uat/buffalo.env for later runs.
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

if [[ -f "$dir/buffalo.env" ]] && grep -q '^APP_ENV=production' "$dir/buffalo.env"; then
    echo "Refusing: $dir/buffalo.env sets APP_ENV=production. The UAT environment never runs in production mode." >&2
    exit 1
fi

if [[ "${UAT_REUSE_IMAGES:-0}" == 1 ]] && docker image inspect "buffalo/app:$version" >/dev/null 2>&1; then
    say "Reusing buffalo/*:$version images"
else
    say "Building the production images ($version)"
    scripts/build-release-images.sh "$version"
fi

if [[ ! -f "$dir/buffalo.env" ]]; then
    say "Preparing settings, secrets and a self-signed certificate in .uat/"
    mkdir -p "$dir/tls"
    sh scripts/ops/generate-secrets.sh --dir "$dir/secrets" >/dev/null
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 365 -subj /CN=localhost \
        -addext "subjectAltName=DNS:localhost" -keyout "$dir/tls.key" -out "$dir/tls/fullchain.pem" 2>/dev/null
    chmod u+w "$dir/secrets"; mv "$dir/tls.key" "$dir/secrets/tls_key"; chmod 0444 "$dir/secrets/tls_key"; chmod 0700 "$dir/secrets"
    sed -e "s#^COMPOSE_PROJECT_NAME=.*#COMPOSE_PROJECT_NAME=buffalo-uat#" \
        -e "s#^COMPOSE_FILE=.*#COMPOSE_FILE=compose.yml:compose.prod.yml:compose.profile-airgapped.yml:compose.uat.yml#" \
        -e "s#^BUFFALO_VERSION=.*#BUFFALO_VERSION=$version#" \
        -e "s#^BUFFALO_PROFILE=.*#BUFFALO_PROFILE=airgapped#" \
        -e "s#^BUFFALO_SECRETS_DIR=.*#BUFFALO_SECRETS_DIR=$dir/secrets#" \
        -e "s#^BUFFALO_CONFIG=.*#BUFFALO_CONFIG=$dir/buffalo.env#" \
        -e "s#^BUFFALO_SERVER_NAME=.*#BUFFALO_SERVER_NAME=localhost#" \
        -e "s#^APP_URL=.*#APP_URL=https://localhost:$https_port#" \
        -e "s#^BUFFALO_TLS_CERTIFICATE_FILE=.*#BUFFALO_TLS_CERTIFICATE_FILE=$dir/tls/fullchain.pem#" \
        -e "s#^BUFFALO_BIND_ADDRESS=.*#BUFFALO_BIND_ADDRESS=127.0.0.1#" \
        -e "s#^BUFFALO_HTTP_PORT=.*#BUFFALO_HTTP_PORT=$http_port#" \
        -e "s#^BUFFALO_HTTPS_PORT=.*#BUFFALO_HTTPS_PORT=$https_port#" \
        -e "s#^REVERB_ALLOWED_ORIGINS=.*#REVERB_ALLOWED_ORIGINS=localhost#" \
        deploy/buffalo.env.example > "$dir/buffalo.env"
    printf '\n# UAT: its own frontend bridge, so it can run beside a production install.\nBUFFALO_FRONTEND_BRIDGE=bflo-uat-front\n' >> "$dir/buffalo.env"
fi

# Sets KEY=VALUE in .uat/buffalo.env (replaced, or appended).
setting() {
    local key=$1 value=$2 file="$dir/buffalo.env"
    if grep -q "^$key=" "$file"; then
        sed -i.bak "s#^$key=.*#$key=$value#" "$file" && rm -f "$file.bak"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

demo_host=${DEMO_HOST:-}
if [[ -n "$demo_host" ]]; then
    if [[ ! "$demo_host" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
        echo "DEMO_HOST must be a host name (e.g. demo.example.org), without scheme or port." >&2
        exit 1
    fi
    demo_port=${DEMO_PORT:-8090}
    [[ "$demo_port" =~ ^[0-9]+$ ]] || { echo "DEMO_PORT must be a port number." >&2; exit 1; }
    say "Demo mode: https://$demo_host (TLS at the host's nginx -> http://127.0.0.1:$demo_port)"
    setting COMPOSE_FILE compose.yml:compose.prod.yml:compose.profile-airgapped.yml:compose.uat.yml:compose.uat-demo.yml
    setting APP_URL "https://$demo_host"
    setting BUFFALO_SERVER_NAME "$demo_host"
    setting REVERB_ALLOWED_ORIGINS "$demo_host"
    setting DEMO_HOST "$demo_host"
    setting DEMO_PORT "$demo_port"
elif [[ -n "${DEMO_PORT:-}" ]]; then
    echo "DEMO_PORT needs DEMO_HOST." >&2
    exit 1
elif ! grep -q '^DEMO_HOST=' "$dir/buffalo.env"; then
    # Live updates (Reverb) accept the address the browser uses: earlier
    # .uat/ settings kept the example host and refused localhost.
    setting REVERB_ALLOWED_ORIGINS localhost
fi
demo_host=$(sed -n 's/^DEMO_HOST=//p' "$dir/buffalo.env")
demo_port=$(sed -n 's/^DEMO_PORT=//p' "$dir/buffalo.env")

dc() { docker compose --env-file "$dir/buffalo.env" "$@"; }

say "Starting PostgreSQL and Valkey"
dc up -d --wait postgres valkey
say "Database roles and migrations"
dc run --rm -T tools php artisan buffalo:db:provision-roles --database=buffalo --no-interaction >/dev/null
dc run --rm -T tools php artisan migrate --force --no-interaction >/dev/null

say "Seeding synthetic data and the UAT accounts (email, password and TOTP setup URI printed ONCE, on the first run)"
dc run --rm -T tools php artisan buffalo:uat:seed --if-empty --incidents="${UAT_INCIDENTS:-3000}" --no-interaction

say "Starting every service"
dc up -d --wait
# A first go-live result for System health (it then runs daily): in UAT it
# reports "not ready" by design (demo data, test accounts).
dc run --rm -T tools php artisan buffalo:go-live:check --record --no-interaction >/dev/null
echo
if [[ -n "$demo_host" ]]; then
    say "Demo ready: https://$demo_host (proxied to http://127.0.0.1:$demo_port). Banner: UAT — TEST DATA ONLY."
else
    say "UAT ready: https://localhost:$https_port (self-signed certificate). Banner: UAT — TEST DATA ONLY."
fi
