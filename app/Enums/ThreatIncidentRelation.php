<?php

namespace App\Enums;

/**
 * How an incident relates to a threat report (Phase 6B).
 */
enum ThreatIncidentRelation: string
{
    case MaterialisedAs = 'materialised_as';
    case Related = 'related';

    public function label(): string
    {
        return match ($this) {
            self::MaterialisedAs => 'Materialised as',
            self::Related => 'Related',
        };
    }
}
