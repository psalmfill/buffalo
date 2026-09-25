<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit\AuditLog;

/**
 * Lifts a login lockout early: clears locked_until and the failure
 * counters.
 *
 * Authorisation (UserPolicy::unlock) is the caller's job.
 */
final class UnlockUser
{
    public function __invoke(User $user, ?User $actor = null): User
    {
        $user->forceFill([
            'locked_until' => null,
            'failed_login_count' => 0,
            'lockout_count' => 0,
            'updated_by' => $actor?->id,
        ])->save();

        AuditLog::event('unlocked', "User {$user->name} unlocked", $user, [], $actor);

        return $user;
    }
}
