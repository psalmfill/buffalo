<?php

namespace App\Support\Persons;

use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
use App\Models\User;

/**
 * Activates or deactivates one role of a person, for modules that manage
 * roles themselves (political offices, persons of interest). Role rows
 * are never deleted, so their history stays.
 */
final class RoleAssigner
{
    /** $code: a PersonRoleType code constant. */
    public function activate(Person $person, string $code, User $actor): void
    {
        $typeId = PersonRoleType::idFor($code);
        $role = PersonRole::query()->where('person_id', $person->id)->where('person_role_type_id', $typeId)->first();

        if ($role === null) {
            $role = new PersonRole(['is_active' => true]);
            $role->forceFill(['person_id' => $person->id, 'person_role_type_id' => $typeId, 'created_by' => $actor->id])->save();
        } elseif (! $role->is_active) {
            $role->fill(['is_active' => true])->save();
        }

        $person->unsetRelation('roles');
    }

    public function deactivate(Person $person, string $code): void
    {
        $role = PersonRole::query()->where('person_id', $person->id)->where('person_role_type_id', PersonRoleType::idFor($code))->first();

        if ($role !== null && $role->is_active) {
            $role->fill(['is_active' => false])->save();
        }

        $person->unsetRelation('roles');
    }
}
