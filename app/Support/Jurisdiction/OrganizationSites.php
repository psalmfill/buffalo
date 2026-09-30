<?php

namespace App\Support\Jurisdiction;

use App\Models\Organization;
use App\Models\OrganizationSite;

/**
 * Whether an organization has any sites at all (Phase 4B), for the
 * deletion rule: a directory entry with sites cannot be deleted.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction, through the
 * audited runAsSystem()): the answer covers sites in every jurisdiction and
 * of every classification, and is a yes/no only. Callers must never reveal
 * more than "This organization has sites and cannot be deleted".
 */
final class OrganizationSites
{
    public function __construct(private readonly JurisdictionContext $context) {}

    public function anyExist(Organization $organization): bool
    {
        return (bool) $this->context->runAsSystem(
            'Check whether an organization has sites before deletion',
            fn (): bool => OrganizationSite::query()->where('organization_id', $organization->id)->exists(),
        );
    }
}
