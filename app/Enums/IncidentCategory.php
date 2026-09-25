<?php

namespace App\Enums;

/**
 * Broad category of an incident type or threat type.
 */
enum IncidentCategory: string
{
    case Crime = 'crime';
    case ViolenceConflict = 'violence_conflict';
    case Accident = 'accident';
    case DisasterEnvironmental = 'disaster_environmental';
    case SecurityAgency = 'security_agency';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Crime => 'Crime',
            self::ViolenceConflict => 'Violence and conflict',
            self::Accident => 'Accident',
            self::DisasterEnvironmental => 'Disaster and environmental',
            self::SecurityAgency => 'Security agency',
            self::Other => 'Other',
        };
    }
}
