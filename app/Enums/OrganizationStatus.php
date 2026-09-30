<?php

namespace App\Enums;

/**
 * Status of an organization (Phase 4B).
 */
enum OrganizationStatus: string
{
    case Active = 'active';
    case Dormant = 'dormant';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Dormant => 'Dormant',
            self::Closed => 'Closed',
        };
    }
}
