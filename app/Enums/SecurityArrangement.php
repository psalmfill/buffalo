<?php

namespace App\Enums;

/**
 * How a government formation protects an organization site (Phase 4B).
 */
enum SecurityArrangement: string
{
    case StationedOnSite = 'stationed_on_site';
    case RegularPatrol = 'regular_patrol';
    case OnCall = 'on_call';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::StationedOnSite => 'Stationed on site',
            self::RegularPatrol => 'Regular patrol',
            self::OnCall => 'On call',
            self::Other => 'Other',
        };
    }
}
