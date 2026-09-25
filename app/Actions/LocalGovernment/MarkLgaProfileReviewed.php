<?php

namespace App\Actions\LocalGovernment;

use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\User;

/**
 * An officer confirms the LGA profile is current.
 */
final class MarkLgaProfileReviewed
{
    public function __invoke(Lga $lga, User $actor): LgaProfile
    {
        $profile = UpdateLgaProfile::profileFor($lga, $actor);

        $profile->forceFill([
            'last_reviewed_at' => now(),
            'last_reviewed_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $profile;
    }
}
