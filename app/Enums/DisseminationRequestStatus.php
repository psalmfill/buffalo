<?php

namespace App\Enums;

/**
 * Status of an LGA chief's request to disseminate a record (Phase 7A).
 */
enum DisseminationRequestStatus: string
{
    case Pending = 'pending';
    case Actioned = 'actioned';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Actioned => 'Actioned',
            self::Declined => 'Declined',
        };
    }
}
