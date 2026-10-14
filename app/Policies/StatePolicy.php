<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\State;
use App\Models\User;
use App\Support\Lens\StateLens;

/**
 * States are reference data. Actions: the pilot rollout switch (Phase
 * 9B.3; super admins only), and a national user's view of one state's
 * dashboard or the state lens (Phase 11A.2; states within reach).
 */
class StatePolicy
{
    public function viewDashboard(User $user, State $state): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value) && app(StateLens::class)->canChooseState($user, $state);
    }

    public function manageRollout(User $user): bool
    {
        return $user->checkPermissionTo(Permission::SystemSettings->value);
    }
}
