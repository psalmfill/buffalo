<?php

namespace App\Support\Persons;

use App\Models\PersonRoleType;
use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by the persons module: POI offence categories are
 * threat type codes (Phase 3B); person roles are person role types (11A.1).
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->jsonArray(ThreatType::class, 'persons_of_interest', 'offence_categories', 'code');
        // Person role types (Phase 11A.1).
        $registry->column(PersonRoleType::class, 'person_roles', 'person_role_type_id');
    }
}
