<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Users\SuperAdmins;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deactivates an account (never deletes it). Requires a reason. A user
 * cannot deactivate themselves and the last active super admin cannot be
 * deactivated. A deactivated user immediately sees nothing (jurisdiction
 * scope) and may do nothing (Gate::before).
 *
 * Authorisation (UserPolicy::deactivate) is the caller's job.
 */
final class DeactivateUser
{
    public function __construct(private readonly SuperAdmins $superAdmins) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, string $reason, ?User $actor = null): User
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 1000 characters is required to deactivate a user.']);
        }

        if ($actor !== null && $actor->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot deactivate your own account.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already deactivated.']);
        }

        return DB::transaction(function () use ($user, $reason, $actor): User {
            if ($this->superAdmins->isLastActive($user, lockForUpdate: true)) {
                throw ValidationException::withMessages(['user' => 'The last active super admin cannot be deactivated.']);
            }

            $user->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivation_reason' => $reason,
                'deactivated_by' => $actor?->id,
                'updated_by' => $actor?->id,
                'remember_token' => null,
            ])->save();

            return $user;
        });
    }
}
