<?php

namespace App\Console\Commands;

use App\Support\Rollout\Rollout;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;

/**
 * Production defaults (Phase 9A.1), checked by the production entrypoint
 * before buffalo:secrets:check and buffalo:keys:verify; the container
 * refuses to start when any fails:
 *
 * - APP_ENV=production and APP_DEBUG=false;
 * - APP_URL is https, and session cookies are Secure, HttpOnly, SameSite;
 * - the Content Security Policy is enforced (not report-only);
 * - Horizon's dashboard requires the viewHorizon gate (no anonymous access);
 * - Inertia DevTools are off.
 *
 * Prints the setting names only, never values.
 */
class ProductionCheckCommand extends Command
{
    protected $signature = 'buffalo:production:check';

    protected $description = 'Refuse to run in production with unsafe settings';

    public function handle(): int
    {
        $problems = array_keys(array_filter([
            'APP_ENV must be production' => config('app.env') !== 'production',
            'APP_DEBUG must be false' => (bool) config('app.debug'),
            'APP_URL must start with https://' => ! str_starts_with((string) config('app.url'), 'https://'),
            'Session cookies must be Secure (SESSION_SECURE_COOKIE)' => ! (bool) config('session.secure'),
            'Session cookies must be HttpOnly' => ! (bool) config('session.http_only'),
            'SESSION_SAME_SITE must be lax or strict' => ! in_array(config('session.same_site'), ['lax', 'strict'], true),
            'The Content Security Policy must be enforced (BUFFALO_CSP_REPORT_ONLY=false)' => (bool) config('buffalo.security.csp.report_only'),
            'The Horizon dashboard must require the viewHorizon gate' => ! self::horizonRestricted(),
            'Inertia DevTools must be off (INERTIA_DEVTOOLS_ENABLED)' => (bool) config('inertia.devtools.enabled'),
            'Pilot rollout gating must be enforced (BUFFALO_ROLLOUT_ENFORCE=true)' => ! Rollout::enforced(),
        ]));

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }
            $this->components->error('Unsafe production settings: refusing to start.');

            return self::FAILURE;
        }

        $this->components->info('Production settings are safe.');

        return self::SUCCESS;
    }

    /** An anonymous request must not pass Horizon's authorisation. */
    private static function horizonRestricted(): bool
    {
        try {
            return Horizon::check(Request::create('/'.config('horizon.path'))) === false;
        } catch (\Throwable) {
            return true; // The gate refused (no user): restricted.
        }
    }
}
