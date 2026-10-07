#!/bin/sh
# BUFFALO container entrypoint (Phase 8A).
#
# In production (APP_ENV=production, set by the prod image) every container
# built from this image (php-fpm, Horizon, the scheduler, Reverb) refuses to
# start unless the start-up checks pass:
#   - buffalo:production:check  APP_DEBUG off, https APP_URL, Secure cookies,
#                            CSP enforced, Horizon restricted (Phase 9A);
#   - buffalo:secrets:check  every secret set, not a development default,
#                            long enough (prints names only);
#   - buffalo:keys:verify    APP_KEY and CIPHERSWEET_KEY match the active
#                            fingerprints in the database (a misconfigured
#                            restore must not write data with the wrong key).
# Set BUFFALO_SKIP_STARTUP_CHECKS=1 only for one-off maintenance containers
# (for example running buffalo:db:provision-roles before secrets exist).
set -e

if [ "${APP_ENV:-}" = "production" ] && [ "${BUFFALO_SKIP_STARTUP_CHECKS:-0}" != "1" ]; then
    for check in buffalo:production:check buffalo:secrets:check buffalo:keys:verify; do
        php /var/www/html/artisan "$check" --no-interaction --no-ansi || {
            echo "BUFFALO start-up check $check failed: refusing to start." >&2
            exit 1
        }
    done
fi

exec docker-php-entrypoint "$@"
