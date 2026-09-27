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
 * Grants (Phase 3C) are read-only: `view` accepts an active grant, but
 * `update`, `delete` and `link` require the user's OWN jurisdiction, so a
 * user whose only access is a grant can change nothing.
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
        return $actor->checkPermissionTo(Permission::PersonsManage->value) && $this->reachableOwn($actor, $person);
    }

    public function delete(User $actor, Person $person): bool
    {
        return $actor->checkPermissionTo(Permission::PersonsDelete->value) && $this->reachableOwn($actor, $person);
    }

    /**
     * Link the person from another record (association, group member,
     * traditional ruler, religious leader): the person must be in the
     * user's own jurisdiction, not only visible under a grant.
     */
    public function link(User $actor, Person $person): bool
    {
        return $this->viewAny($actor) && $this->reachableOwn($actor, $person);
    }

    private function reachable(User $actor, Person $person): bool
    {
        return $this->jurisdiction->canAccess($actor, $person) && $this->classification->canSee($actor, $person);
    }

    private function reachableOwn(User $actor, Person $person): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $person) && $this->classification->canSee($actor, $person);
    }
}
