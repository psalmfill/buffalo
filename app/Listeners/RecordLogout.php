<?php

namespace App\Listeners;

use App\Enums\LoginEvent;
use App\Http\Middleware\EnforceSessionPolicy;
use App\Models\User;
use App\Support\Auth\LoginHistoryRecorder;
use Illuminate\Auth\Events\Logout;

/**
 * A user signing out. Sign-outs forced by EnforceSessionPolicy record their
 * own event (session_expired, signed_out_elsewhere) instead.
 */
class RecordLogout
{
    public function __construct(private readonly LoginHistoryRecorder $history) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User || request()->attributes->get(EnforceSessionPolicy::FORCED_LOGOUT) === true) {
            return;
        }

        $this->history->record(LoginEvent::Logout, $event->user);
    }
}
