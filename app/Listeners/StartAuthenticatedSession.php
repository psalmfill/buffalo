<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\Auth\SessionKeys;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Str;

/**
 * Stamps a new session with the user's session version, a fresh
 * single-session token and its start time (EnforceSessionPolicy).
 */
class StartAuthenticatedSession
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $token = Str::random(64);

        $user->forceFill(['session_token' => $token])->saveQuietly();

        session()->put([
            SessionKeys::VERSION => $user->session_version,
            SessionKeys::TOKEN => $token,
            SessionKeys::STARTED_AT => now()->getTimestamp(),
        ]);
    }
}
