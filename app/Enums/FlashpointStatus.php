<?php

namespace App\Enums;

/**
 * Status of a flashpoint or blackspot (Phase 6A). Active and monitoring
 * areas are matched against new incidents.
 */
enum FlashpointStatus: string
{
    case Active = 'active';
    case Monitoring = 'monitoring';
    case Dormant = 'dormant';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Monitoring => 'Monitoring',
            self::Dormant => 'Dormant',
            self::Resolved => 'Resolved',
        };
    }

    public function isWatched(): bool
    {
        return $this === self::Active || $this === self::Monitoring;
    }
}
