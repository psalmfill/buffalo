<?php

namespace App\Support\Persons;

/**
 * Nigerian phone numbers, normalised to +234 followed by the 10-digit
 * national number before encryption and blind indexing, so 0803…, 234803…
 * and +234 803… all match.
 */
final class PhoneNumber
{
    /** Normalised form, or null when $input is not a Nigerian phone number. */
    public static function normalise(string $input): ?string
    {
        $compact = preg_replace('/[\s().\-\/]/', '', trim($input)) ?? '';

        $national = match (true) {
            // +234 803…, 234 803…, and the common +234 0803… mistake.
            preg_match('/^(?:\+|00)?2340?(\d{10})$/', $compact, $matches) === 1 => $matches[1],
            // 0803… (trunk prefix).
            preg_match('/^0(\d{10})$/', $compact, $matches) === 1 => $matches[1],
            default => null,
        };

        if ($national === null || $national[0] === '0') {
            return null;
        }

        return '+234'.$national;
    }
}
