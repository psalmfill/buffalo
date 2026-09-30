<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\User;

/**
 * The organization directory (Phase 4B): a national entry with no location,
 * so permission alone decides (the five intelligence roles; never the super
 * admin). Deleting also requires that the organization has no sites
 * (checked by the controller in system context).
 */
final class OrganizationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsView->value);
    }

    public function view(User $actor, Organization $organization): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsManage->value);
    }

    public function update(User $actor, Organization $organization): bool
    {
        return $this->create($actor);
    }

    public function delete(User $actor, Organization $organization): bool
    {
        return $actor->checkPermissionTo(Permission::OrganizationsDelete->value);
    }
}
