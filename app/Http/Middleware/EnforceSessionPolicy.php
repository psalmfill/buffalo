<?php

namespace App\Http\Middleware;

use App\Enums\LoginEvent;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Auth\SessionKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends sessions that are no longer allowed:
 * - the user's sessions were terminated (deactivation, two-factor reset);
 * - the account is blocked (inactive, deleted, locked);
 * - the account signed in elsewhere (BUFFALO_SINGLE_SESSION);
 * - the session is older than BUFFALO_SESSION_MAX_HOURS.
 * The idle timeout is the session lifetime (SESSION_LIFETIME).
 */
class EnforceSessionPolicy
{
    public const FORCED_LOGOUT = 'buffalo.forced_logout';

    public const SIGNED_OUT_ELSEWHERE = 'You were signed out because your account signed in elsewhere.';

    public const SESSION_EXPIRED = 'Your session has expired. Please sign in again.';

    public const SESSION_ENDED = 'Your session has ended. Please sign in again.';

    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $this->bindUnstampedSession($request, $user);
        $session = $request->session();

        if ($session->get(SessionKeys::VERSION) !== $user->session_version || $user->isAccessBlocked()) {
            return $this->signOut($request, $user, LoginEvent::SessionExpired, self::SESSION_ENDED);
        }

        if (config('buffalo.auth.single_session') && $session->get(SessionKeys::TOKEN) !== $user->session_token) {
            return $this->signOut($request, $user, LoginEvent::SignedOutElsewhere, self::SIGNED_OUT_ELSEWHERE);
        }

        $maxAge = (int) config('buffalo.auth.session_max_hours', 12) * 3600;

        if (now()->getTimestamp() - (int) $session->get(SessionKeys::STARTED_AT) > $maxAge) {
            return $this->signOut($request, $user, LoginEvent::SessionExpired, self::SESSION_EXPIRED);
        }

        return $next($request);
    }

    /**
     * Sessions are stamped at login (StartAuthenticatedSession). One without
     * a stamp predates this policy: bind it to the current state now.
     */
    private function bindUnstampedSession(Request $request, User $user): void
    {
        $session = $request->session();

        if (! $session->has(SessionKeys::VERSION)) {
            $session->put(SessionKeys::VERSION, $user->session_version);
        }

        if (! $session->has(SessionKeys::STARTED_AT)) {
            $session->put(SessionKeys::STARTED_AT, now()->getTimestamp());
        }

        if (! $session->has(SessionKeys::TOKEN) && $user->session_token === null) {
            $token = Str::random(64);
            $user->forceFill(['session_token' => $token])->saveQuietly();
            $session->put(SessionKeys::TOKEN, $token);
        }
    }

    private function signOut(Request $request, User $user, LoginEvent $event, string $message): Response
    {
        $this->history->record($event, $user);

        $request->attributes->set(self::FORCED_LOGOUT, true);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
