<?php

namespace App\Enums;

/**
 * Workflow status of a coverage report (Phase 4A): draft → submitted →
 * (returned → submitted)* → reviewed (locked).
 */
enum CoverageReportStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Reviewed = 'reviewed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Returned => 'Returned',
            self::Reviewed => 'Reviewed',
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }

    /** Counts as submitted for compliance: the LGA has reported. */
    public function countsAsSubmitted(): bool
    {
        return $this === self::Submitted || $this === self::Reviewed;
    }
}
