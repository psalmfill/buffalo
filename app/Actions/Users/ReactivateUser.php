<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reactivates a deactivated account (reason required), clears any login
 * lock and opens a new posting with the user's current role and
 * jurisdiction.
 *
 * Authorisation (UserPolicy::reactivate) is the caller's job.
 */
final class ReactivateUser
{
    public function __construct(private readonly OfficerPostings $postings) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $user, string $reason, ?User $actor = null): User
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most 1000 characters is required to reactivate a user.']);
        }

        if ($user->is_active) {
            throw ValidationException::withMessages(['user' => 'This user is already active.']);
        }

        return DB::transaction(function () use ($user, $reason, $actor): User {
            $user->forceFill([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivation_reason' => null,
                'deactivated_by' => null,
                'failed_login_count' => 0,
                'locked_until' => null,
                'updated_by' => $actor?->id,
            ])->save();

            $this->postings->open($user, $actor);

            AuditLog::event('reactivated', "User {$user->name} reactivated", $user, ['reason' => $reason], $actor);

            return $user;
        });
    }
}
