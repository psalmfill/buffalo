#!/usr/bin/env bash
#
# make browser-check [target=local|uat] [args="..."]: the Phase 11 scripted
# browser check (Playwright, Chromium). Fails on any console error, failed
# request or CSP violation. Screenshots go to docs/uat/results/phase-11/.
#
#   target=local (default)  the development stack (make up), at APP_URL from
#                           .env. Creates the test accounts if missing and
#                           issues them new passwords and TOTP secrets, written
#                           to .local-check/credentials.txt (owner-only,
#                           git-ignored, never printed).
#   target=uat              the UAT environment (make uat-up), at
#                           https://localhost:19443. Credentials: the table
#                           printed by make uat-up or make uat-credentials,
#                           saved as .uat/credentials.txt (or BUFFALO_CREDENTIALS).
#
# Both prepare the synthetic records the check uses
# (buffalo:uat:browser-fixtures: UAT or local only) and install Playwright
# and Chromium for the check (dev only, tests/browser).
set -euo pipefail
cd "$(dirname "$0")/../.."
say() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
target=${TARGET:-local}

case "$target" in
    local)
        url=$(sed -n 's/^APP_URL=//p' .env | tr -d '"')
        export BUFFALO_URL=${BUFFALO_URL:-$url}
        mkdir -p .local-check
        say "Synthetic records and test accounts on the local stack"
        docker compose exec -T app php artisan buffalo:uat:browser-fixtures --accounts=/var/www/html/.local-check/credentials.txt --no-interaction
        export BUFFALO_CREDENTIALS="$PWD/.local-check/credentials.txt"
        tiles=$(docker compose exec -T nginx sh -c 'test -s /usr/share/nginx/tiles/nigeria.pmtiles && echo yes || echo no')
        ;;
    uat)
        [[ -f .uat/buffalo.env ]] || { echo "No UAT environment: run make uat-up first." >&2; exit 1; }
        creds=${BUFFALO_CREDENTIALS:-.uat/credentials.txt}
        [[ -f "$creds" ]] || { echo "No credentials file ($creds): save the table printed by make uat-up or make uat-credentials there." >&2; exit 1; }
        [[ "$creds" = /* ]] || creds="$PWD/$creds"
        export BUFFALO_CREDENTIALS="$creds" BUFFALO_URL=${BUFFALO_URL:-https://localhost:19443}
        say "Synthetic records for the check (UAT)"
        docker compose --env-file .uat/buffalo.env run --rm -T tools php artisan buffalo:uat:browser-fixtures --no-interaction
        tiles=$(docker compose --env-file .uat/buffalo.env exec -T nginx sh -c 'test -s /usr/share/nginx/tiles/nigeria.pmtiles && echo yes || echo no')
        ;;
    *)
        echo "target must be local or uat." >&2
        exit 1
        ;;
esac

if [[ "$tiles" != yes* ]]; then
    echo "The map tiles extract is not installed (every map would request a missing file): run make tiles first." >&2
    exit 1
fi

say "Playwright (dev only)"
cd tests/browser
npm ci --no-audit --no-fund >/dev/null
npx playwright install chromium >/dev/null

say "Browser check ($target: $BUFFALO_URL)"
npx playwright test "$@"
