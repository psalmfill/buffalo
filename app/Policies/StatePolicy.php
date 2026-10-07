<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * States are reference data; the only action on them is the pilot rollout
 * switch (Phase 9B.3): system administration, super admins only.
 */
class StatePolicy
{
    public function manageRollout(User $user): bool
    {
        return $user->checkPermissionTo(Permission::SystemSettings->value);
    }
}
