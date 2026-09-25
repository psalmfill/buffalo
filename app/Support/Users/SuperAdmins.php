<?php

namespace App\Support\Users;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Counts active super admins, independent of the jurisdiction context
 * (a plain query-builder count of accounts; no records are returned).
 * Protects the invariant that the system always keeps at least one active
 * super admin.
 */
final class SuperAdmins
{
    public function activeCount(bool $lockForUpdate = false): int
    {
        return $this->activeQuery($lockForUpdate)->count();
    }

    /**
     * True if $user is an active super admin and no other active super admin
     * exists. Use $lockForUpdate inside a transaction that changes users.
     */
    public function isLastActive(User $user, bool $lockForUpdate = false): bool
    {
        if (! $user->hasBuffaloRole(Role::SuperAdmin) || ! $user->is_active || $user->trashed()) {
            return false;
        }

        return ! $this->activeQuery($lockForUpdate)->where('users.id', '!=', $user->id)->exists();
    }

    private function activeQuery(bool $lockForUpdate): Builder
    {
        $query = DB::table('users')
            ->join('model_has_roles', function ($join): void {
                $join->on('model_has_roles.model_id', '=', 'users.id')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass());
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', Role::SuperAdmin->value)
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->select('users.id');

        return $lockForUpdate ? $query->lockForUpdate() : $query;
    }
}
