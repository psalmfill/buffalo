<?php

namespace App\Support\LocalGovernment;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A state's computed facts (Phase 11F). Area and population are not stored
 * on the state: they are the sums of its active LGAs' area_km2 and
 * population_estimate, with the number of LGAs missing each figure, so the
 * reader knows how far to trust the total. LGAs and districts are
 * reference data (not scoped).
 */
final class StateFacts
{
    /**
     * @return array{area_km2: float|null, area_missing: int, population: int|null, population_missing: int, lgas: int, districts: int}
     */
    public static function for(State $state): array
    {
        return self::forStates([$state->id])->get($state->id) ?? self::empty();
    }

    /**
     * @param  list<int>  $stateIds
     * @return Collection<int, array{area_km2: float|null, area_missing: int, population: int|null, population_missing: int, lgas: int, districts: int}> keyed by state id
     */
    public static function forStates(array $stateIds): Collection
    {
        if ($stateIds === []) {
            return collect();
        }

        $lgas = Lga::query()->active()->whereIn('state_id', $stateIds)->toBase()
            ->groupBy('state_id')
            ->get([
                'state_id',
                DB::raw('count(*) AS lgas'),
                DB::raw('sum(area_km2) AS area'),
                DB::raw('count(*) FILTER (WHERE area_km2 IS NULL) AS area_missing'),
                DB::raw('sum(population_estimate) AS population'),
                DB::raw('count(*) FILTER (WHERE population_estimate IS NULL) AS population_missing'),
            ])
            ->keyBy('state_id');

        $districts = SenatorialDistrict::query()->active()->whereIn('state_id', $stateIds)->toBase()
            ->groupBy('state_id')
            ->selectRaw('state_id, count(*) AS total')
            ->pluck('total', 'state_id');

        return collect($stateIds)->mapWithKeys(function (int $id) use ($lgas, $districts): array {
            $row = $lgas->get($id);

            return [$id => $row === null ? [...self::empty(), 'districts' => (int) ($districts[$id] ?? 0)] : [
                'area_km2' => $row->area === null ? null : round((float) $row->area, 2),
                'area_missing' => (int) $row->area_missing,
                'population' => $row->population === null ? null : (int) $row->population,
                'population_missing' => (int) $row->population_missing,
                'lgas' => (int) $row->lgas,
                'districts' => (int) ($districts[$id] ?? 0),
            ]];
        });
    }

    /**
     * @return array{area_km2: null, area_missing: int, population: null, population_missing: int, lgas: int, districts: int}
     */
    private static function empty(): array
    {
        return ['area_km2' => null, 'area_missing' => 0, 'population' => null, 'population_missing' => 0, 'lgas' => 0, 'districts' => 0];
    }
}
