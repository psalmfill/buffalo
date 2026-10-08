<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Political offices (Phase 3B): the permission, the office's jurisdiction
 * (with the senatorial-district rule for LGA chiefs, see PoliticalOffice)
 * and the holder's visibility (a classified person's offices never reveal
 * them). Where a new office may be placed is checked by the request.
 */
final class PoliticalOfficePolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PoliticalView->value);
    }

    public function view(User $actor, PoliticalOffice $office): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $office);
    }

    /** Record an office for $person. */
    public function create(User $actor, Person $person): bool
    {
        return $actor->checkPermissionTo(Permission::PoliticalManage->value) && $actor->can('update', $person);
    }

    public function update(User $actor, PoliticalOffice $office): bool
    {
        return $actor->checkPermissionTo(Permission::PoliticalManage->value) && $this->reachable($actor, $office);
    }

    public function delete(User $actor, PoliticalOffice $office): bool
    {
        return $actor->checkPermissionTo(Permission::PoliticalDelete->value) && $this->reachable($actor, $office);
    }

    /**
     * Correct the holder of an office recorded against the wrong person
     * (Phase 10B.3): State Directors and national users, on an office they
     * can edit. The new holder is checked by the action (`link`: own
     * jurisdiction).
     */
    public function reassign(User $actor, PoliticalOffice $office): bool
    {
        return $actor->checkPermissionTo(Permission::PoliticalReassign->value) && $this->update($actor, $office);
    }

    private function reachable(User $actor, PoliticalOffice $office): bool
    {
        $person = Person::query()->find($office->person_id);

        return $this->jurisdiction->canAccess($actor, $office) && $person !== null && $actor->can('view', $person);
    }
}
