<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Laravel\Horizon\Horizon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (Phase 8B.1).
 *
 * - Content-Security-Policy with a fresh nonce per request, shared with
 *   Vite (script and style tags), Inertia (its injected styles) and
 *   Horizon. Scripts run only from this origin or with the nonce.
 * - Styles: 'self' plus 'unsafe-inline'. shadcn/ui components inject
 *   <style> elements at runtime without a nonce hook (sonner toasts; the
 *   Radix scroll lock through react-style-singleton), and a nonce in
 *   style-src would make browsers ignore 'unsafe-inline'. Scripts stay
 *   nonce-only, so injected CSS cannot run code.
 * - connect-src adds the Reverb WebSocket origin; the Vite dev server (and
 *   its blob: worker) is allowed only when APP_ENV=local and it is running
 *   (hot file). No production page needs workers.
 * - Violations are reported to /csp-report. With
 *   buffalo.security.csp.report_only the policy is only reported.
 * - Strict-Transport-Security when APP_URL is https; Permissions-Policy,
 *   Cross-Origin-Opener-Policy and Cross-Origin-Resource-Policy always.
 *
 * nginx keeps X-Content-Type-Options, X-Frame-Options and Referrer-Policy
 * (not repeated here).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        Horizon::cspNonce($nonce);

        $response = $next($request);

        // A response with its own (stricter) policy keeps it: evidence files
        // are served with `default-src 'none'; sandbox`.
        if (! $response->headers->has('Content-Security-Policy')) {
            $header = config('buffalo.security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, self::policy($nonce));
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('buffalo.security.hsts_seconds').'; includeSubDomains');
        }

        return $response;
    }

    public static function policy(string $nonce): string
    {
        $dev = app()->environment('local') && Vite::isRunningHot() ? self::devServer() : [];
        $websocket = (string) config('buffalo.security.csp.websocket');

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev],
            'style-src' => ["'self'", "'unsafe-inline'", ...$dev],
            'img-src' => ["'self'", 'data:', 'blob:', ...$dev],
            'font-src' => ["'self'", ...$dev],
            'connect-src' => array_values(array_filter(["'self'", $websocket, ...$dev, ...array_map(fn (string $o) => preg_replace('#^http#', 'ws', $o), $dev)])),
            // Vite's dev client runs a blob: worker (development only).
            ...($dev === [] ? [] : ['worker-src' => ["'self'", 'blob:']]),
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
            'report-uri' => ['/csp-report'],
        ];

        return implode('; ', array_map(fn (string $name, array $values) => $name.' '.implode(' ', array_unique($values)), array_keys($directives), $directives));
    }

    /**
     * The Vite dev server origin from public/hot (local development only).
     *
     * @return list<string>
     */
    private static function devServer(): array
    {
        $hot = trim((string) @file_get_contents(public_path('hot')));

        return preg_match('#^https?://[^/]+#', $hot, $m) === 1 ? [$m[0]] : [];
    }
}
