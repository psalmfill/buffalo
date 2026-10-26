#!/usr/bin/env bash
#
# make uat-credentials: new passwords and TOTP secrets for the UAT accounts
# and, with BASIC_AUTH=1, a new site password. Everything is printed once.
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ -f .uat/buffalo.env ]] || { echo "No UAT environment: run make uat-up first." >&2; exit 1; }

docker compose --env-file .uat/buffalo.env run --rm -T tools php artisan buffalo:uat:seed --reset-credentials
if [[ "$(sed -n 's/^UAT_BASIC_AUTH=//p' .uat/buffalo.env | tail -n 1)" == 1 ]]; then
    scripts/uat/basic-auth.sh issue
fi
