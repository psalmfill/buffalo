<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\SessionKeys;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends sessions that are no longer valid:
 * - the user's sessions were terminated (deactivation, two-factor reset);
 * - the account is blocked (inactive, deleted, locked).
 */
class EnforceSessionPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $session = $request->session();

        // Sessions are stamped at login (StartAuthenticatedSession). One
        // without a stamp predates this policy: bind it now.
        if (! $session->has(SessionKeys::VERSION)) {
            $session->put(SessionKeys::VERSION, $user->session_version);
        }

        if ($session->get(SessionKeys::VERSION) !== $user->session_version || $user->isAccessBlocked()) {
            return $this->signOut($request, 'Your session has ended. Please sign in again.');
        }

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('status', $message);
    }
}
