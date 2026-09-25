<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-factor authentication is mandatory. A signed-in user without
 * confirmed 2FA can only reach the enrolment flow and log out.
 */
class EnsureTwoFactorEnrolled
{
    /**
     * Routes usable before enrolment.
     */
    public const ALLOWED_ROUTES = [
        'two-factor.setup',
        'two-factor.setup.complete',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'logout',
        'account-setup.show',
        'account-setup.store',
        'session.ping',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasEnabledTwoFactorAuthentication() || $request->routeIs(...self::ALLOWED_ROUTES)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Two-factor authentication must be set up first.'], 403);
        }

        return redirect()->route('two-factor.setup');
    }
}
