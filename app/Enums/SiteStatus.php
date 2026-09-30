<?php

namespace App\Enums;

/**
 * Status of an organization site (Phase 4B).
 */
enum SiteStatus: string
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
