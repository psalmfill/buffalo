<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Religious institutions (Phase 2C). Every ability also checks jurisdiction (defence in depth behind the
 * scope). Creating one is on LgaPolicy (it depends on the LGA). Deleting is
 * a soft delete with a reason, for State Directors and above.
 */
final class ReligiousInstitutionPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ReligiousInstitutionsView->value);
    }

    public function view(User $actor, ReligiousInstitution $record): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->canAccess($actor, $record);
    }

    public function update(User $actor, ReligiousInstitution $record): bool
    {
        return $actor->checkPermissionTo(Permission::ReligiousInstitutionsManage->value)
            && $this->jurisdiction->canAccess($actor, $record);
    }

    public function delete(User $actor, ReligiousInstitution $record): bool
    {
        return $actor->checkPermissionTo(Permission::ReligiousInstitutionsDelete->value)
            && $this->jurisdiction->canAccess($actor, $record);
    }
}
