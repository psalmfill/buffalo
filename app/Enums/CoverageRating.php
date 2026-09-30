<?php

namespace App\Enums;

/**
 * Overall security coverage rating of a coverage report (Phase 4A).
 */
enum CoverageRating: string
{
    case Adequate = 'adequate';
    case Partial = 'partial';
    case Inadequate = 'inadequate';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Adequate => 'Adequate',
            self::Partial => 'Partial',
            self::Inadequate => 'Inadequate',
            self::Critical => 'Critical',
        };
    }

    /** Higher is worse (for comparisons between reports). */
    public function rank(): int
    {
        return match ($this) {
            self::Adequate => 0,
            self::Partial => 1,
            self::Inadequate => 2,
            self::Critical => 3,
        };
    }
}
