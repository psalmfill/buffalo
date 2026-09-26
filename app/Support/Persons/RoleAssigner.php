<?php

namespace App\Support\Persons;

use App\Enums\PersonRoleType;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\User;

/**
 * Activates or deactivates one role of a person, for modules that manage
 * roles themselves (political offices, persons of interest). Role rows
 * are never deleted, so their history stays.
 */
final class RoleAssigner
{
    public function activate(Person $person, PersonRoleType $type, User $actor): void
    {
        $role = PersonRole::query()->where('person_id', $person->id)->where('role', $type->value)->first();

        if ($role === null) {
            $role = new PersonRole(['role' => $type, 'is_active' => true]);
            $role->forceFill(['person_id' => $person->id, 'created_by' => $actor->id])->save();
        } elseif (! $role->is_active) {
            $role->fill(['is_active' => true])->save();
        }

        $person->unsetRelation('roles');
    }

    public function deactivate(Person $person, PersonRoleType $type): void
    {
        $role = PersonRole::query()->where('person_id', $person->id)->where('role', $type->value)->first();

        if ($role !== null && $role->is_active) {
            $role->fill(['is_active' => false])->save();
        }

        $person->unsetRelation('roles');
    }
}
