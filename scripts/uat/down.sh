#!/usr/bin/env bash
# make uat-down: stops the UAT environment (or the hosted demo). Its data
# (volumes) and the settings and secrets in .uat/ are kept, so `make uat-up`
# brings it back as it was.
#
#   PURGE=1      also deletes its volumes (the synthetic data and accounts);
#                the next `make uat-up` seeds afresh and prints new credentials
#   UAT_PURGE=1  as PURGE=1, and also removes .uat/ (settings, secrets,
#                certificate, demo host)
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ -f .uat/buffalo.env ]] || { echo "No UAT environment (.uat/buffalo.env)."; exit 0; }

purge=${PURGE:-0}
[[ "${UAT_PURGE:-0}" == 1 ]] && purge=1

if [[ "$purge" == 1 ]]; then
    docker compose --env-file .uat/buffalo.env --profile tools down -v --remove-orphans
    echo "UAT environment stopped; its volumes were deleted."
else
    docker compose --env-file .uat/buffalo.env --profile tools down --remove-orphans
    echo "UAT environment stopped; its data is kept (PURGE=1 deletes it)."
fi

if [[ "${UAT_PURGE:-0}" == 1 ]]; then
    rm -rf .uat
    echo "Removed .uat/."
fi
