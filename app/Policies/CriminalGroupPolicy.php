<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CriminalGroup;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Criminal groups (Phase 3B): permission, home jurisdiction AND
 * classification. Areas of operation play no part: they never grant
 * visibility. Areas and members are managed with `update`.
 */
final class CriminalGroupPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CriminalGroupsView->value);
    }

    public function view(User $actor, CriminalGroup $group): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $group);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CriminalGroupsManage->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function update(User $actor, CriminalGroup $group): bool
    {
        return $actor->checkPermissionTo(Permission::CriminalGroupsManage->value) && $this->reachable($actor, $group);
    }

    public function delete(User $actor, CriminalGroup $group): bool
    {
        return $actor->checkPermissionTo(Permission::CriminalGroupsDelete->value) && $this->reachable($actor, $group);
    }

    private function reachable(User $actor, CriminalGroup $group): bool
    {
        return $this->jurisdiction->canAccess($actor, $group) && $this->classification->canSee($actor, $group);
    }
}
