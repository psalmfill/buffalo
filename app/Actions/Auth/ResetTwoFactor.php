<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Audit\AuditLog;
use App\Support\Auth\SessionTerminator;
use Illuminate\Support\Facades\DB;

/**
 * Clears a user's two-factor authentication (lost device). The user must
 * enrol again at their next login; all their sessions end now. They are
 * told by email when mail is configured.
 *
 * Authorisation (UserPolicy::resetTwoFactor) is the caller's job.
 */
final class ResetTwoFactor
{
    public function __construct(private readonly SessionTerminator $sessions) {}

    public function __invoke(User $user, ?User $actor = null): void
    {
        DB::transaction(function () use ($user, $actor): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'updated_by' => $actor?->id,
            ])->save();

            $this->sessions->terminateAll($user);

            AuditLog::event('two_factor_reset', "Two-factor authentication reset for {$user->name}", $user, [], $actor);
        });

        // In-app always; by email too when mail is enabled (BuffaloNotification).
        $user->notify(new TwoFactorResetNotification);
    }
}
