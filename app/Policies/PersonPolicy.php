<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Person;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Persons (Phase 3A): every ability on a person checks the permission,
 * jurisdiction AND classification, in depth behind both scopes. Roles,
 * aliases, associations and photos are managed with `update`.
 *
 * Phase 3C: grants are read-only, so `update` and `delete` must then
 * require the user's own jurisdiction, not a grant.
 */
final class PersonPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsView->value);
    }

    public function view(User $actor, Person $person): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $person);
    }

    /** Needs at least one LGA in the user's jurisdiction to own the record. */
    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsManage->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    /** Edit, reclassify, roles, aliases, associations and photos. */
    public function update(User $actor, Person $person): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsManage->value) && $this->reachable($actor, $person);
    }

    public function delete(User $actor, Person $person): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsDelete->value) && $this->reachable($actor, $person);
    }

    private function reachable(User $actor, Person $person): bool
    {
        return $this->jurisdiction->canAccess($actor, $person) && $this->classification->canSee($actor, $person);
    }
}
