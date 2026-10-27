<?php

namespace App\Http\Presenters;

use App\Models\State;
use App\Models\StateProfile;
use Illuminate\Support\Carbon;

/**
 * States and their profiles for the browser (Phase 11F): ULIDs and labels,
 * never ids.
 */
final class StatePresenter
{
    /**
     * @param  array{completeness: int, last_reviewed_at: Carbon|null, director: string|null, incidents30: int|null, immediateThreats: int|null}  $figures
     * @param  array{lgas: int}  $facts
     * @return array<string, mixed>
     */
    public static function row(State $state, array $figures, array $facts): array
    {
        return [
            'ulid' => $state->ulid,
            'name' => $state->name,
            'code' => $state->code,
            'zone' => ['ulid' => $state->geopoliticalZone->ulid, 'name' => $state->geopoliticalZone->name],
            'capital' => $state->capital,
            'lgas' => $facts['lgas'],
            'director' => $figures['director'],
            'completeness' => $figures['completeness'],
            'last_reviewed_at' => $figures['last_reviewed_at']?->toIso8601String(),
            'incidents30' => $figures['incidents30'],
            'immediate_threats' => $figures['immediateThreats'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function facts(State $state): array
    {
        return [
            'ulid' => $state->ulid,
            'name' => $state->name,
            'code' => $state->code,
            'zone' => ['ulid' => $state->geopoliticalZone->ulid, 'name' => $state->geopoliticalZone->name],
            'capital' => $state->capital,
            'is_active' => $state->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(?StateProfile $profile): array
    {
        return [
            'exists' => $profile !== null,
            'history' => $profile?->history,
            'economic_activities' => $profile?->economic_activities,
            'terrain' => $profile->terrain ?? [],
            'major_roads' => $profile?->major_roads,
            'waterways' => $profile?->waterways,
            'borders' => $profile?->borders,
            'general_notes' => $profile?->general_notes,
            'last_reviewed_at' => $profile?->last_reviewed_at?->toIso8601String(),
            'last_reviewed_by' => $profile?->reviewer?->name,
        ];
    }
}
