<?php

namespace App\Listeners;

use App\Actions\Auth\RecordFailedLogin;
use App\Enums\LoginEvent;
use App\Support\Auth\LoginHistoryRecorder;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * A wrong two-factor code counts toward the lockout like a wrong password.
 */
class RecordFailedTwoFactorAttempt
{
    public function __construct(
        private readonly RecordFailedLogin $recordFailure,
        private readonly LoginHistoryRecorder $history,
    ) {}

    public function handle(TwoFactorAuthenticationFailed $event): void
    {
        $user = $event->user;

        $this->history->record(LoginEvent::TwoFactorFailed, $user);
        ($this->recordFailure)($user);
    }
}
