<?php

namespace App\Rules;

use App\Models\User;
use App\Support\Auth\PasswordRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * Rejects the user's current password and their last few passwords
 * (PasswordRules::HISTORY). Passes when there is no user yet.
 */
final class NotRecentlyUsedPassword implements ValidationRule
{
    public function __construct(private readonly ?User $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null || ! is_string($value) || $value === '') {
            return;
        }

        $hashes = $this->user->passwordHistories()
            ->latest('id')
            ->limit(PasswordRules::HISTORY)
            ->pluck('password')
            ->push($this->user->getAuthPassword());

        foreach ($hashes as $hash) {
            if (is_string($hash) && $hash !== '' && Hash::check($value, $hash)) {
                $fail('You cannot reuse one of your last '.PasswordRules::HISTORY.' passwords.');

                return;
            }
        }
    }
}
