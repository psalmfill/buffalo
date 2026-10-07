#!/usr/bin/env bash
# make uat-down: stops the UAT environment and deletes its (synthetic) data.
# The settings and secrets in .uat/ are kept unless UAT_PURGE=1.
set -euo pipefail
cd "$(dirname "$0")/../.."
[[ -f .uat/buffalo.env ]] || { echo "No UAT environment (.uat/buffalo.env)."; exit 0; }
docker compose --env-file .uat/buffalo.env --profile tools down -v --remove-orphans
[[ "${UAT_PURGE:-0}" == 1 ]] && rm -rf .uat && echo "Removed .uat/."
echo "UAT environment removed."
