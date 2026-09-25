<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Rules\NotRecentlyUsedPassword;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

/**
 * The single password policy, used by every form or command that sets a
 * password (account setup, password change, password reset,
 * buffalo:create-super-admin).
 *
 * No breached-password lookup: Password::uncompromised() calls an external
 * API, which CLAUDE.md forbids. No forced periodic expiry.
 */
final class PasswordRules
{
    public const MIN_LENGTH = 12;

    /** How many previous passwords may not be reused. */
    public const HISTORY = 5;

    public static function password(): Password
    {
        return Password::min(self::MIN_LENGTH)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols();
    }

    /**
     * Rules for a new password field (with a `_confirmation` field).
     *
     * @return list<string|Password|ValidationRule>
     */
    public static function rules(?User $user = null): array
    {
        return ['required', 'string', 'confirmed', self::password(), new NotRecentlyUsedPassword($user)];
    }

    /**
     * Human-readable requirements, shown next to every password form.
     *
     * @return list<string>
     */
    public static function descriptions(): array
    {
        return [
            'At least '.self::MIN_LENGTH.' characters',
            'Upper and lower case letters',
            'At least one number',
            'At least one symbol',
            'Not one of your last '.self::HISTORY.' passwords',
        ];
    }

    /**
     * Value for the HTML `passwordrules` attribute (password managers).
     */
    public static function passwordRulesAttribute(): string
    {
        return 'minlength: '.self::MIN_LENGTH.'; required: lower; required: upper; required: digit; required: special;';
    }
}
