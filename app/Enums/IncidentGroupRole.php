<?php

namespace App\Enums;

/**
 * How a criminal group is linked to an incident (Phase 5A).
 */
enum IncidentGroupRole: string
{
    case Suspected = 'suspected';
    case Claimed = 'claimed';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Suspected => 'Suspected',
            self::Claimed => 'Claimed responsibility',
            self::Confirmed => 'Confirmed',
        };
    }
}
