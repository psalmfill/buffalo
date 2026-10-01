<?php

namespace App\Enums;

/**
 * Where a threat report came from (Phase 6B). Informants are never
 * identified in the record.
 */
enum ThreatSourceType: string
{
    case Informant = 'informant';
    case Community = 'community';
    case AgencyReport = 'agency_report';
    case OpenSource = 'open_source';
    case OfficerObservation = 'officer_observation';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Informant => 'Informant',
            self::Community => 'Community',
            self::AgencyReport => 'Agency report',
            self::OpenSource => 'Open source',
            self::OfficerObservation => 'Officer observation',
            self::Other => 'Other',
        };
    }
}
