<?php

namespace App\Support\Incidents;

use App\Models\IncidentType;
use App\Models\PropertyType;
use App\Models\SecurityAgency;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by incidents (Phase 5A): incident types, security
 * agencies (casualties and responding agencies) and property types.
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(IncidentType::class, 'incidents', 'incident_type_id');
        $registry->column(SecurityAgency::class, 'incident_casualties', 'security_agency_id');
        $registry->column(SecurityAgency::class, 'incident_agencies', 'security_agency_id');
        $registry->column(PropertyType::class, 'incident_properties', 'property_type_id');
    }
}
