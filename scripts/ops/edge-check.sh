#!/bin/sh
#
# edge-check.sh <settings file> [<directory with the compose files>]:
# refuses (exit 1) when the merged Compose configuration publishes anything
# the edge mode does not allow (buffalo:edge:check-compose). Run before
# starting services, by install.sh, upgrade.sh and make uat-up. Needs the
# release's app image (the tools service) but no running services.
set -eu
config=${1:?usage: edge-check.sh <settings file> [compose directory]}
cd "${2:-.}"

docker compose --env-file "$config" config --format json \
    | docker compose --env-file "$config" run --rm -T --no-deps -e BUFFALO_SKIP_STARTUP_CHECKS=1 tools \
        php artisan buffalo:edge:check-compose --no-interaction --no-ansi
