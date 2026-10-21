<?php

namespace App\Support\Exports;

use Carbon\CarbonImmutable;

/**
 * An approximate age band for sensitive list exports (Phase 11E), e.g.
 * "30–39": computed from the date of birth (or the recorded approximate
 * age) without revealing either.
 */
final class AgeBand
{
    public static function for(?string $dateOfBirth, ?int $approximateAge, ?CarbonImmutable $today = null): string
    {
        $age = $approximateAge;

        if ($dateOfBirth !== null && $dateOfBirth !== '') {
            $born = CarbonImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
            $age = $born === null ? $age : (int) $born->diffInYears($today ?? CarbonImmutable::today(), absolute: true);
        }

        if ($age === null || $age < 0) {
            return 'Unknown';
        }

        if ($age < 18) {
            return 'Under 18';
        }

        if ($age < 20) {
            return '18–19';
        }

        $low = intdiv($age, 10) * 10;

        return $age >= 80 ? '80 and over' : "{$low}–".($low + 9);
    }
}
