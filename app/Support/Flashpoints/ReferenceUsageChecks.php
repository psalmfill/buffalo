<?php

namespace App\Support\Flashpoints;

use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by flashpoints and blackspots (Phase 6A).
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(ThreatType::class, 'flashpoints', 'threat_type_id');
    }
}
