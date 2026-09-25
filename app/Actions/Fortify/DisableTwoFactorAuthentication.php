<?php

namespace App\Actions\Fortify;

use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;

/**
 * Two-factor authentication is mandatory and cannot be disabled by users.
 * Fortify still registers the DELETE route; this makes it refuse. Only an
 * administrator can reset 2FA (App\Actions\Auth\ResetTwoFactor).
 */
class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    public function __invoke($user)
    {
        throw new AuthorizationException('Two-factor authentication cannot be disabled.');
    }
}
