<?php

namespace App\Enums;

/**
 * Status of a criminal group (Phase 3B).
 */
enum CriminalGroupStatus: string
{
    case Active = 'active';
    case Dormant = 'dormant';
    case Dismantled = 'dismantled';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Dormant => 'Dormant',
            self::Dismantled => 'Dismantled',
            self::Unknown => 'Unknown',
        };
    }
}
