<?php

namespace App\Enums;

/** Status of a person merge request (Phase 10A.2). */
enum MergeRequestStatus: string
{
    case Pending = 'pending';
    case Executed = 'executed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Executed => 'Merged',
            self::Declined => 'Declined',
            self::Cancelled => 'Cancelled',
        };
    }
}
