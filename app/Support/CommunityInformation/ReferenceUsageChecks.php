<?php

namespace App\Support\CommunityInformation;

use App\Models\Denomination;
use App\Models\Religion;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by community information (Phase 2C): religious
 * institutions reference religions and denominations.
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(Religion::class, 'religious_institutions', 'religion_id');
        $registry->column(Denomination::class, 'religious_institutions', 'denomination_id');
    }
}
