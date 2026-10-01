<?php

namespace App\Support\Threats;

use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by threat reports (Phase 6B).
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(ThreatType::class, 'threat_reports', 'threat_type_id');
    }
}
