<?php

namespace App\Enums;

/**
 * Status of an incident (Phase 5A). Transitions arrive in 5C; 5A creates and edits drafts.
 */
enum IncidentStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Verified = 'verified';
    case UnderInvestigation = 'under_investigation';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Invalid = 'invalid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Returned => 'Returned',
            self::Verified => 'Verified',
            self::UnderInvestigation => 'Under investigation',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Invalid => 'Invalid',
        };
    }
}
