<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\Users\SuperAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a user's role and/or jurisdiction. Zones are cleared for any role
 * other than DDG. The last active super admin keeps their role.
 *
 * Authorisation (UserPolicy::changeRole) is the caller's job.
 */
final class UpdateUserJurisdiction
{
    public function __construct(
        private readonly AssignRole $assignRole,
        private readonly SuperAdmins $superAdmins,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, UserJurisdiction $jurisdiction, ?User $actor = null): User
    {
        $jurisdiction->validate();

        return DB::transaction(function () use ($user, $jurisdiction, $actor): User {
            if ($jurisdiction->role !== Role::SuperAdmin && $this->superAdmins->isLastActive($user, lockForUpdate: true)) {
                throw ValidationException::withMessages([
                    'role' => 'The last active super admin cannot be given another role.',
                ]);
            }

            $user->forceFill([
                ...$jurisdiction->attributes(),
                'updated_by' => $actor?->id,
            ])->save();

            ($this->assignRole)($user, $jurisdiction->role);
            $user->zones()->sync($jurisdiction->zoneIds());

            return $user->load('roles', 'zones');
        });
    }
}
