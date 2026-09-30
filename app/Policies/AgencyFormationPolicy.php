<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AgencyFormation;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Agency formations (Phase 4A): permission, the formation's own
 * jurisdiction AND classification. Formations are never grantable, so
 * writes and reads use the same jurisdiction check.
 */
final class AgencyFormationPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::FormationsView->value);
    }

    public function view(User $actor, AgencyFormation $formation): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $formation);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::FormationsManage->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function update(User $actor, AgencyFormation $formation): bool
    {
        return $actor->checkPermissionTo(Permission::FormationsManage->value) && $this->reachable($actor, $formation);
    }

    public function delete(User $actor, AgencyFormation $formation): bool
    {
        return $actor->checkPermissionTo(Permission::FormationsDelete->value) && $this->reachable($actor, $formation);
    }

    private function reachable(User $actor, AgencyFormation $formation): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $formation) && $this->classification->canSee($actor, $formation);
    }
}
