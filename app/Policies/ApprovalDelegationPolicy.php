<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\ApprovalDelegation;
use App\Models\User;

/**
 * Approval delegations (Phase 3C): only a State Director manages them, for
 * their own state. Revoking: the State Director of the delegation's state.
 */
final class ApprovalDelegationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isDirector($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isDirector($actor);
    }

    public function revoke(User $actor, ApprovalDelegation $delegation): bool
    {
        return $this->isDirector($actor) && $actor->state_id === $delegation->state_id && $delegation->revoked_at === null;
    }

    private function isDirector(User $actor): bool
    {
        return ! $actor->isAccessBlocked()
            && $actor->getRole() === Role::StateDirector
            && $actor->state_id !== null
            && $actor->checkPermissionTo(Permission::DelegationsManage->value);
    }
}
