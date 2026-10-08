<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\PersonMerge;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Executed person merges (Phase 10A). Listed on the survivor's page for
 * anyone who sees that page in their own jurisdiction; undone by holders of
 * persons.merge in the merge's state.
 */
final class PersonMergePolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function view(User $actor, PersonMerge $merge): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsView->value) && $this->jurisdiction->canAccessOwn($actor, $merge);
    }

    public function undo(User $actor, PersonMerge $merge): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsMerge->value) && $this->jurisdiction->canAccessOwn($actor, $merge);
    }
}
