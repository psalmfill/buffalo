<?php

namespace App\Support\Audit;

/**
 * Attributes that are never written to the activity log, on any model and
 * in any named event's properties.
 */
final class AuditExclusions
{
    /**
     * @var list<string>
     */
    public const NAMES = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'session_token',
        'token',
        'token_hash',
    ];

    /**
     * Suffixes of columns holding encrypted values or blind indexes
     * (spatie/laravel-ciphersweet, Phase 3).
     *
     * @var list<string>
     */
    public const SUFFIXES = ['_encrypted', '_bidx'];

    public static function isExcluded(string $attribute): bool
    {
        if (in_array($attribute, self::NAMES, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($attribute, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function scrub(array $values): array
    {
        return array_filter(
            $values,
            fn ($key): bool => ! is_string($key) || ! self::isExcluded($key),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
