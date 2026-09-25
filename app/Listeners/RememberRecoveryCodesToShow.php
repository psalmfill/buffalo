<?php

namespace App\Listeners;

use App\Support\Auth\SessionKeys;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * After enrolment is confirmed, the enrolment page shows the recovery codes
 * once and asks the user to confirm they saved them.
 */
class RememberRecoveryCodesToShow
{
    public function handle(TwoFactorAuthenticationConfirmed $event): void
    {
        session()->put(SessionKeys::SHOW_RECOVERY_CODES, true);
    }
}
