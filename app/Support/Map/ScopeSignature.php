<?php

namespace App\Support\Map;

use App\Enums\Classification;
use App\Models\User;
use App\Support\Classification\ClassificationGate;

/**
 * Who a cached map layer was computed for (Phase 6C): role, jurisdiction
 * level and place, DDG zones, and clearance. Cached results are never
 * shared between different signatures.
 *
 * Below `secret` clearance a user also sees classified records they
 * created themselves (CLAUDE.md 4a), so for those users the signature
 * includes the user: their results are cached for them alone.
 */
final class ScopeSignature
{
    public static function for(User $user): string
    {
        $clearance = app(ClassificationGate::class)->maxLevelFor($user);
        $zones = $user->zones()->pluck('geopolitical_zones.id')->sort()->values()->all();

        $parts = [
            'role' => $user->getRole()?->value,
            'level' => $user->jurisdiction_level->value,
            'state' => $user->state_id,
            'lga' => $user->lga_id,
            'zones' => $zones,
            'clearance' => $clearance?->value,
            'user' => $clearance === Classification::Secret ? null : $user->id,
        ];

        return hash('sha256', (string) json_encode($parts));
    }
}
