<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Community;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Communities (Phase 2B). Every ability on a community also checks
 * jurisdiction (defence in depth behind the scope). Creating one is
 * LgaPolicy::addCommunity (it depends on the LGA). Deleting is a soft
 * delete with a reason, for State Directors and above (communities.delete).
 */
final class CommunityPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CommunitiesView->value);
    }

    public function view(User $actor, Community $community): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->canAccess($actor, $community);
    }

    public function update(User $actor, Community $community): bool
    {
        return $actor->checkPermissionTo(Permission::CommunitiesManage->value)
            && $this->jurisdiction->canAccess($actor, $community);
    }

    public function delete(User $actor, Community $community): bool
    {
        return $actor->checkPermissionTo(Permission::CommunitiesDelete->value)
            && $this->jurisdiction->canAccess($actor, $community);
    }
}
