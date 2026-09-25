<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;

/**
 * Gives a user exactly one role. Always syncs, never adds (a unique index
 * on model_has_roles also makes a second role impossible).
 *
 * Callers are responsible for keeping the user's jurisdiction consistent
 * with the role; use CreateUser / UpdateUserJurisdiction.
 */
final class AssignRole
{
    public function __invoke(User $user, Role $role): void
    {
        $user->syncRoles([$role->value]);
        $user->load('roles');
    }
}
