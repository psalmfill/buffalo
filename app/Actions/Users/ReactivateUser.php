<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reactivates a deactivated account and clears any login lock.
 *
 * Authorisation (UserPolicy::reactivate) is the caller's job.
 */
final class ReactivateUser
{
    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, ?User $actor = null): User
    {
        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already active.']);
        }

        $user->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivation_reason' => null,
            'deactivated_by' => null,
            'failed_login_count' => 0,
            'locked_until' => null,
            'updated_by' => $actor?->id,
        ])->save();

        return $user;
    }
}
