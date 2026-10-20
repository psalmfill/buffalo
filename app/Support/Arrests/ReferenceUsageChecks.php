<?php

namespace App\Support\Arrests;

use App\Models\OffenceType;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by arrests (Phase 11C): the arresting agency, the
 * offences, and the threat category an offence type maps to.
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(SecurityAgency::class, 'arrests', 'security_agency_id');
        $registry->column(OffenceType::class, 'arrest_person_offences', 'offence_type_id');
        $registry->column(ThreatType::class, 'offence_types', 'threat_type_id');
    }
}
