<?php

namespace App\Actions\Users;

use App\Models\User;

/**
 * Updates a user's name, email, phone, service number and rank (not role
 * or jurisdiction). Validate with UpdateUserProfileRequest first. Recorded
 * in the audit log by the User model.
 *
 * Authorisation (UserPolicy::update) is the caller's job.
 */
final class UpdateUserProfile
{
    /**
     * @param  array{name: string, email: string, phone?: string|null, service_number?: string|null, rank?: string|null}  $data
     */
    public function __invoke(User $user, array $data, ?User $actor = null): User
    {
        $user->fill([
            'name' => $data['name'],
            'email' => strtolower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'service_number' => $data['service_number'] ?? null,
            'rank' => $data['rank'] ?? null,
        ]);
        $user->forceFill(['updated_by' => $actor?->id])->save();

        return $user;
    }
}
