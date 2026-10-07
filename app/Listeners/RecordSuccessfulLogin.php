<?php

namespace App\Listeners;

use App\Enums\LoginEvent;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use App\Support\Rollout\Rollout;
use Illuminate\Auth\Events\Login;

/**
 * A completed login (after the 2FA challenge where applicable): reset the
 * failure counter, record when and from where, and log the event.
 */
class RecordSuccessfulLogin
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        // A user whose state is not live yet: EnsureStateIsLive ends the
        // session on the next request and records `state_not_live` instead
        // (Phase 9B.3).
        if (! $user instanceof User || Rollout::blocks($user)) {
            return;
        }

        $user->forceFill([
            'failed_login_count' => 0,
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();

        $this->history->record(LoginEvent::LoginSuccess, $user);
    }
}
