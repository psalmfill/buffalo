<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use App\Support\Auth\MailConfiguration;
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
        });

        if (MailConfiguration::isConfigured()) {
            $user->notify(new TwoFactorResetNotification);
        }
    }
}
