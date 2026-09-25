<?php

namespace App\Actions\Fortify;

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication as FortifyEnableTwoFactorAuthentication;

/**
 * Fortify's enable action regenerates the secret whenever `force` is sent,
 * even when 2FA is already confirmed: anyone with a session could re-key the
 * second factor. In BUFFALO confirmed 2FA can only be replaced after an
 * administrator resets it (App\Actions\Auth\ResetTwoFactor).
 */
class EnableTwoFactorAuthentication extends FortifyEnableTwoFactorAuthentication
{
    public function __invoke($user, $force = false)
    {
        if ($user->two_factor_confirmed_at !== null) {
            throw new AuthorizationException('Two-factor authentication is already set up. Ask an administrator to reset it.');
        }

        parent::__invoke($user, $force);
    }
}
