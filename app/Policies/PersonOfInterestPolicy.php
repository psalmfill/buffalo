<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Persons of interest (Phase 3B). Visibility follows the person: the POI's
 * jurisdiction AND the person policy (jurisdiction and classification).
 * Setting status "cleared" also needs poi.clear; deleting needs poi.delete
 * (State Director and above).
 */
final class PersonOfInterestPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::PoiView->value);
    }

    public function view(User $actor, PersonOfInterest $poi): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $poi);
    }

    /** Designate $person as a person of interest. */
    public function create(User $actor, Person $person): bool
    {
        return $actor->checkPermissionTo(Permission::PoiManage->value) && $actor->can('update', $person);
    }

    /** Details, status, risk and reviews. */
    public function update(User $actor, PersonOfInterest $poi): bool
    {
        return $actor->checkPermissionTo(Permission::PoiManage->value) && $this->reachable($actor, $poi);
    }

    public function clear(User $actor, PersonOfInterest $poi): bool
    {
        return $this->update($actor, $poi) && $actor->checkPermissionTo(Permission::PoiClear->value);
    }

    public function delete(User $actor, PersonOfInterest $poi): bool
    {
        return $actor->checkPermissionTo(Permission::PoiDelete->value) && $this->reachable($actor, $poi);
    }

    private function reachable(User $actor, PersonOfInterest $poi): bool
    {
        $person = Person::query()->find($poi->person_id);

        return $this->jurisdiction->canAccess($actor, $poi) && $person !== null && $actor->can('view', $person);
    }
}
