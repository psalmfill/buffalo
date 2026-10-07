<?php

namespace App\Support\Rollout;

use App\Enums\JurisdictionLevel;
use App\Models\State;
use App\Models\User;

/**
 * Pilot rollout (Phase 9B.3). A state- or LGA-level user may use BUFFALO
 * only once their state is live. National users and super admins are never
 * affected. Data for states that are not live can still be loaded.
 */
final class Rollout
{
    public const NOT_LIVE_MESSAGE = 'BUFFALO is not yet active for your state.';

    public static function enforced(): bool
    {
        return (bool) config('buffalo.rollout.enforce', true);
    }

    public static function blocks(User $user): bool
    {
        if (! self::enforced()) {
            return false;
        }

        if (! in_array($user->jurisdiction_level, [JurisdictionLevel::State, JurisdictionLevel::Lga], true)) {
            return false;
        }

        // A state- or LGA-level account without a state is misconfigured:
        // fail closed.
        if ($user->state_id === null) {
            return true;
        }

        return ! State::query()->whereKey($user->state_id)->where('is_live', true)->exists();
    }
}
