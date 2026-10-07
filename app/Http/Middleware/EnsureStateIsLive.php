<?php

namespace App\Http\Middleware;

use App\Enums\LoginEvent;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Rollout\Rollout;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pilot rollout (Phase 9B.3): after a state- or LGA-level user has signed
 * in (password and 2FA), the first request ends their session if their
 * state is not live yet, records `state_not_live` in login history and
 * shows "BUFFALO is not yet active for your state." on the sign-in page.
 * It also ends existing sessions when a state is switched off.
 *
 * Account setup and two-factor enrolment stay possible, so officers can be
 * onboarded in training sessions before their state goes live.
 */
class EnsureStateIsLive
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User
            || $request->routeIs(...EnsureTwoFactorEnrolled::ALLOWED_ROUTES) && ! $user->hasEnabledTwoFactorAuthentication()
            || $request->routeIs('logout', 'session.ping')
            || ! Rollout::blocks($user)) {
            return $next($request);
        }

        $this->history->record(LoginEvent::StateNotLive, $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => Rollout::NOT_LIVE_MESSAGE], 403);
        }

        return redirect()->route('login')->with('status', Rollout::NOT_LIVE_MESSAGE);
    }
}
