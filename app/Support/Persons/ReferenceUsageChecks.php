<?php

namespace App\Support\Persons;

use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by the persons module: POI offence categories are
 * threat type codes (Phase 3B).
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->jsonArray(ThreatType::class, 'persons_of_interest', 'offence_categories', 'code');
    }
}
