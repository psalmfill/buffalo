<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest as FortifyTwoFactorLoginRequest;

/**
 * Fortify's two-factor challenge looks the user up with `$model::find()`,
 * which the jurisdiction scope blocks while the request is still
 * unauthenticated. This version resolves the challenged user through the
 * authentication user provider instead (App\Support\Jurisdiction\
 * AuthenticationUserProvider). Bound in FortifyServiceProvider.
 */
class TwoFactorLoginRequest extends FortifyTwoFactorLoginRequest
{
    public function hasChallengedUser()
    {
        if ($this->challengedUser) {
            return true;
        }

        return $this->session()->has('login.id')
            && $this->usableUser($this->provider()->retrieveById($this->session()->get('login.id'))) !== null;
    }

    public function challengedUser()
    {
        if ($this->challengedUser) {
            return $this->challengedUser;
        }

        $user = $this->session()->has('login.id')
            ? $this->usableUser($this->provider()->retrieveById($this->session()->get('login.id')))
            : null;

        if ($user === null) {
            throw new HttpResponseException(
                app(FailedTwoFactorLoginResponse::class)->toResponse($this)
            );
        }

        return $this->challengedUser = $user;
    }

    /**
     * An account locked (e.g. by failed codes), deactivated or deleted
     * during the challenge cannot complete it.
     */
    private function usableUser(mixed $user): ?User
    {
        return $user instanceof User && ! $user->isAccessBlocked() ? $user : null;
    }

    private function provider(): UserProvider
    {
        return app(StatefulGuard::class)->getProvider();
    }
}
