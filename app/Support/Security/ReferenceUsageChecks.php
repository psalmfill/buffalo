<?php

namespace App\Support\Security;

use App\Models\OrganizationSector;
use App\Models\SecurityAgency;
use App\Support\ReferenceData\ReferenceUsageRegistry;

/**
 * Lookup values used by the security module: formations and coverage
 * report deployments reference security agencies (4A); organizations
 * reference organization sectors (4B).
 */
final class ReferenceUsageChecks
{
    public static function register(ReferenceUsageRegistry $registry): void
    {
        $registry->column(SecurityAgency::class, 'agency_formations', 'security_agency_id');
        $registry->column(SecurityAgency::class, 'coverage_report_deployments', 'security_agency_id');
        $registry->column(OrganizationSector::class, 'organizations', 'organization_sector_id');
    }
}
