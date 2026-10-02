<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RecordFailedLogin;
use App\Enums\LoginEvent;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Auth\StepUp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Step-up authentication (Phase 8B.2).
 *
 * - `GET /step-up?intended=…`: the page shown when a plain link (for
 *   example a download) needs a fresh TOTP code; it continues to the
 *   intended address afterwards.
 * - `POST /step-up`: checks a 6-digit TOTP code with Fortify's provider
 *   (which refuses a code already used). Recovery codes are not accepted.
 *   Success opens the step-up window and is logged (`step_up_success`); a
 *   wrong code is logged (`step_up_failed`) and counts toward the lockout.
 */
class StepUpController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/step-up', [
            'intended' => self::intended($request->query('intended')),
            'minutes' => StepUp::minutes(),
        ]);
    }

    public function store(Request $request, TwoFactorAuthenticationProvider $provider, LoginHistoryRecorder $history, RecordFailedLogin $recordFailure): JsonResponse|SymfonyResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'intended' => ['nullable', 'string', 'max:2000'],
        ], ['code.regex' => 'Enter the 6-digit code from your authenticator app. Recovery codes cannot be used here.']);

        /** @var User $user */
        $user = $request->user();
        $secret = $user->two_factor_secret === null ? null : Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        if (! is_string($secret) || ! $provider->verify($secret, $data['code'])) {
            $history->record(LoginEvent::StepUpFailed, $user);
            $recordFailure($user);

            throw ValidationException::withMessages(['code' => 'That code is not valid. Try the current code from your authenticator app.']);
        }

        StepUp::confirm($request);
        $history->record(LoginEvent::StepUpSuccess, $user);

        if ($request->header('X-Inertia') !== null) {
            // The step-up page: a full page visit, so a download starts normally.
            return Inertia::location(self::intended($data['intended'] ?? null) ?? route('dashboard'));
        }

        return response()->json(['confirmed' => true, 'expires_at' => StepUp::expiresAt($request)]);
    }

    /** Only a path on this site (never another origin). */
    private static function intended(mixed $value): ?string
    {
        return is_string($value) && str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_contains($value, '\\') ? $value : null;
    }
}
