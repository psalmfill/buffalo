<?php

namespace App\Actions\Users;

use App\Models\OfficerPosting;
use App\Models\User;

/**
 * Opens and closes officer postings. Called inside the transactions of
 * CreateUser, UpdateUserJurisdiction, DeactivateUser and ReactivateUser.
 */
final class OfficerPostings
{
    /**
     * Opens a posting for the user's current role and jurisdiction.
     */
    public function open(User $user, ?User $actor = null): ?OfficerPosting
    {
        $role = $user->getRole();

        if ($role === null) {
            return null;
        }

        return OfficerPosting::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'jurisdiction_level' => $user->jurisdiction_level,
            'state_id' => $user->state_id,
            'lga_id' => $user->lga_id,
            'started_at' => now(),
            'created_by' => $actor?->id,
        ]);
    }

    public function closeOpen(User $user): void
    {
        OfficerPosting::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->get()
            ->each(fn (OfficerPosting $posting) => $posting->forceFill(['ended_at' => now()])->save());
    }
}
