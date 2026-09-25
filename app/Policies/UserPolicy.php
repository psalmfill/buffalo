<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Users\SuperAdmins;

/**
 * Who may view and manage user accounts.
 *
 * - users.view: view users within the actor's jurisdiction (a state
 *   director sees users of their state; super admins see all users).
 * - users.manage (super admin only): create, update, change role,
 *   deactivate, reactivate, unlock, reset two-factor, issue setup links.
 *
 * Every ability on a target user also checks jurisdiction (defence in
 * depth, even where the scope has already filtered). Blocked actors are
 * denied everything by the Gate::before hook in AppServiceProvider.
 */
final class UserPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly SuperAdmins $superAdmins,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->has($actor, Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->has($actor, Permission::UsersView)
            && $this->jurisdiction->canAccess($actor, $target);
    }

    public function create(User $actor): bool
    {
        return $this->has($actor, Permission::UsersManage);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    /**
     * Changing role or jurisdiction. The last active super admin keeps
     * their role.
     */
    public function changeRole(User $actor, User $target): bool
    {
        return $this->manages($actor, $target)
            && ! $this->superAdmins->isLastActive($target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->manages($actor, $target)
            && ! $actor->is($target)
            && $target->is_active
            && ! $this->superAdmins->isLastActive($target);
    }

    public function reactivate(User $actor, User $target): bool
    {
        return $this->manages($actor, $target)
            && ! $target->is_active;
    }

    public function resetTwoFactor(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function unlock(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    /**
     * New setup link: for accounts that never completed setup, or to make a
     * user choose a new password. Active accounts only.
     */
    public function issueSetupLink(User $actor, User $target): bool
    {
        return $this->manages($actor, $target) && $target->is_active;
    }

    private function manages(User $actor, User $target): bool
    {
        return $this->has($actor, Permission::UsersManage)
            && $this->jurisdiction->canAccess($actor, $target);
    }

    private function has(User $actor, Permission $permission): bool
    {
        return $actor->checkPermissionTo($permission->value);
    }
}
