<?php

namespace App\Http\Middleware;

use App\Support\Keys\KeyFingerprints;
use App\Support\Uat\Uat;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Production backstop for the start-up key check (Phase 8A.3): if the
 * container was started without docker/php/entrypoint.sh, the first
 * request verifies the running keys against the active fingerprints and
 * refuses to serve on a mismatch. A successful check is cached for ten
 * minutes per set of running keys (by fingerprint, never the keys).
 */
class VerifyKeyFingerprints
{
    public function handle(Request $request, Closure $next): Response
    {
        // Production and UAT (Phase 9B.5).
        if (! Uat::productionLike()) {
            return $next($request);
        }

        $signature = hash('sha256', implode('|', array_map(
            fn (string $name) => KeyFingerprints::fingerprint($name, KeyFingerprints::runningKey($name)),
            KeyFingerprints::NAMES,
        )));

        $ok = Cache::get("buffalo:keys-verified:{$signature}") === true;

        if (! $ok) {
            $results = KeyFingerprints::verify();
            $ok = collect($results)->every(fn (array $r) => $r['ok']);

            if (! $ok) {
                Log::critical('Key fingerprint mismatch: refusing to serve requests', ['keys' => array_map(fn (array $r) => $r['state'], $results)]);
                abort(503, 'BUFFALO is not available: its encryption keys do not match this database.');
            }

            Cache::put("buffalo:keys-verified:{$signature}", true, now()->addMinutes(10));
        }

        return $next($request);
    }
}
