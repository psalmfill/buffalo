<?php

namespace App\Enums;

/**
 * Status of an agency formation (Phase 4A).
 */
enum FormationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Closed => 'Closed',
        };
    }
}
