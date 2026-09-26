<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Vulnerable population figures (Phase 2C): aggregate counts only. Every ability also checks jurisdiction (defence in depth behind the
 * scope). Creating one is on LgaPolicy (it depends on the LGA). Deleting is
 * a soft delete with a reason, for State Directors and above.
 */
final class VulnerablePopulationStatPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::VulnerableStatsView->value);
    }

    public function view(User $actor, VulnerablePopulationStat $record): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->canAccess($actor, $record);
    }

    public function update(User $actor, VulnerablePopulationStat $record): bool
    {
        return $actor->checkPermissionTo(Permission::VulnerableStatsManage->value)
            && $this->jurisdiction->canAccess($actor, $record);
    }

    public function delete(User $actor, VulnerablePopulationStat $record): bool
    {
        return $actor->checkPermissionTo(Permission::VulnerableStatsDelete->value)
            && $this->jurisdiction->canAccess($actor, $record);
    }
}
