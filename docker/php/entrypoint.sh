#!/bin/sh
# BUFFALO container entrypoint (Phase 8A).
#
# In production (APP_ENV=production, set by the prod image) every container
# built from this image (php-fpm, Horizon, the scheduler, Reverb) refuses to
# start unless the start-up checks pass:
#   - buffalo:secrets:check  every secret set, not a development default,
#                            long enough (prints names only).
# Set BUFFALO_SKIP_STARTUP_CHECKS=1 only for one-off maintenance containers
# (for example running buffalo:db:provision-roles before secrets exist).
set -e

if [ "${APP_ENV:-}" = "production" ] && [ "${BUFFALO_SKIP_STARTUP_CHECKS:-0}" != "1" ]; then
    php /var/www/html/artisan buffalo:secrets:check --no-interaction --no-ansi || {
        echo "BUFFALO start-up checks failed: refusing to start." >&2
        exit 1
    }
fi

exec docker-php-entrypoint "$@"
