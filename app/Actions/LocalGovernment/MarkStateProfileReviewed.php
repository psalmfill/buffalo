<?php

namespace App\Actions\LocalGovernment;

use App\Models\State;
use App\Models\StateProfile;
use App\Models\User;

/**
 * An officer confirms the state profile is current (Phase 11F).
 */
final class MarkStateProfileReviewed
{
    public function __invoke(State $state, User $actor): StateProfile
    {
        $profile = UpdateStateProfile::profileFor($state, $actor);

        $profile->forceFill([
            'last_reviewed_at' => now(),
            'last_reviewed_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $profile;
    }
}
