<?php

namespace App\Concerns;

use App\Models\User;
use App\Support\Auth\PasswordRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Rules for a new password: see App\Support\Auth\PasswordRules.
     *
     * @return list<string|Password|ValidationRule>
     */
    protected function passwordRules(?User $user = null): array
    {
        return PasswordRules::rules($user);
    }

    /**
     * Rules for the current password.
     *
     * @return list<string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
