<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Organization sites (Phase 4B): permission, the site's own jurisdiction
 * AND classification. `organizations.manage` covers sites, their
 * government-security links and their contacts; deleting a site needs
 * `organization_sites.delete`.
 */
final class OrganizationSitePolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsView->value);
    }

    public function view(User $actor, OrganizationSite $site): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $site);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsManage->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function createIn(User $actor, Lga $lga): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsManage->value)
            && $this->options->lgaQuery($actor)->active()->whereKey($lga->id)->exists();
    }

    public function update(User $actor, OrganizationSite $site): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsManage->value) && $this->reachable($actor, $site);
    }

    public function delete(User $actor, OrganizationSite $site): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationSitesDelete->value) && $this->reachable($actor, $site);
    }

    private function reachable(User $actor, OrganizationSite $site): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $site) && $this->classification->canSee($actor, $site);
    }
}
