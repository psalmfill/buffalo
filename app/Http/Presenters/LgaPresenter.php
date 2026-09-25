<?php

namespace App\Http\Presenters;

use App\Enums\Terrain;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Support\LocalGovernment\ProfileCompleteness;
use Illuminate\Support\Carbon;

/**
 * LGAs and their profiles for the browser: ULIDs and labels, never ids.
 */
final class LgaPresenter
{
    /**
     * @param  array{completeness: int, communities: int, last_reviewed_at: Carbon|null}  $stats
     * @return array<string, mixed>
     */
    public static function row(Lga $lga, array $stats): array
    {
        return [
            'ulid' => $lga->ulid,
            'name' => $lga->name,
            'state' => $lga->state->name,
            'district' => $lga->senatorialDistrict->name ?? null,
            'headquarters' => $lga->headquarters,
            'communities' => $stats['communities'],
            'completeness' => $stats['completeness'],
            'last_reviewed_at' => $stats['last_reviewed_at']?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function facts(Lga $lga): array
    {
        return [
            'ulid' => $lga->ulid,
            'name' => $lga->name,
            'code' => $lga->code,
            'state' => ['ulid' => $lga->state->ulid, 'name' => $lga->state->name],
            'district' => $lga->senatorialDistrict->name ?? null,
            'headquarters' => $lga->headquarters,
            'area_km2' => $lga->area_km2,
            'population_estimate' => $lga->population_estimate,
            'is_active' => $lga->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(?LgaProfile $profile): array
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

    /**
     * @return array{percent: int, missing: list<string>}
     */
    public static function completeness(?LgaProfile $profile, int $communities): array
    {
        return [
            'percent' => ProfileCompleteness::percent($profile, $communities),
            'missing' => array_map(
                fn (string $item): string => ProfileCompleteness::ITEMS[$item],
                ProfileCompleteness::missing($profile, $communities),
            ),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function terrainOptions(): array
    {
        return array_map(fn (Terrain $terrain): array => ['value' => $terrain->value, 'label' => $terrain->label()], Terrain::cases());
    }
}
