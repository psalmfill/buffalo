<?php

namespace App\Support\Jurisdiction;

use App\Enums\DisseminationTargetLevel;
use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Places a threat report's or flashpoint's area reaches outside its own
 * jurisdiction (Phase 7A), as suggested dissemination targets:
 *
 * - LGAs other than the record's own whose boundary intersects the area
 *   (point + radius); LGAs without a boundary when their centroid lies
 *   within the radius plus `centroid_margin_meters`;
 * - states other than the record's own whose boundary intersects the area,
 *   or that contain one of those LGAs.
 *
 * Reads only ids and the record's location, by id, outside any scope
 * (system context): the caller has already authorised the record. A
 * record without a location or radius has no suggestions.
 */
final class DisseminationTargets
{
    /**
     * @return list<array{level: DisseminationTargetLevel, state_id: int, lga_id: int|null}>
     */
    public function suggest(Model&Disseminable $subject): array
    {
        $table = $subject->getTable();
        $margin = (int) config('buffalo.disseminations.centroid_margin_meters');

        /** @var list<object{id: int, state_id: int}> $lgas */
        $lgas = DB::select(<<<SQL
            SELECT l.id, l.state_id
            FROM lgas l, (SELECT location, radius_meters, lga_id FROM {$table} WHERE id = ? AND location IS NOT NULL AND radius_meters IS NOT NULL) s
            WHERE l.id <> s.lga_id
              AND (
                (l.boundary IS NOT NULL AND ST_DWithin(l.boundary, s.location, s.radius_meters))
                OR (l.boundary IS NULL AND l.centroid IS NOT NULL AND ST_DWithin(l.centroid, s.location, s.radius_meters + ?))
              )
            ORDER BY l.state_id, l.name
        SQL, [$subject->getKey(), $margin]);

        $boundaryStates = DB::select(<<<SQL
            SELECT st.id
            FROM states st, (SELECT location, radius_meters FROM {$table} WHERE id = ? AND location IS NOT NULL AND radius_meters IS NOT NULL) s
            WHERE st.boundary IS NOT NULL AND ST_DWithin(st.boundary, s.location, s.radius_meters)
        SQL, [$subject->getKey()]);

        $ownState = (int) $subject->getAttribute('state_id');
        $stateIds = collect($boundaryStates)->pluck('id')
            ->merge(collect($lgas)->pluck('state_id'))
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => $id === $ownState)
            ->unique()->sort()->values();

        return [
            ...$stateIds->map(fn (int $id) => ['level' => DisseminationTargetLevel::State, 'state_id' => $id, 'lga_id' => null])->all(),
            ...array_map(fn (object $row) => ['level' => DisseminationTargetLevel::Lga, 'state_id' => (int) $row->state_id, 'lga_id' => (int) $row->id], $lgas),
        ];
    }

    /**
     * Suggested places not yet reached by an active dissemination. An LGA
     * is reached by a dissemination to it, or (in another state) to its
     * whole state; a state by any dissemination to it.
     *
     * @return list<array{level: DisseminationTargetLevel, state_id: int, lga_id: int|null}>
     */
    public function uncovered(Model&Disseminable $subject): array
    {
        $active = RecordDissemination::query()->about($subject)->active()->get(['target_level', 'target_state_id', 'target_lga_id']);
        $ownState = (int) $subject->getAttribute('state_id');

        return array_values(array_filter($this->suggest($subject), function (array $target) use ($active, $ownState): bool {
            if ($target['level'] === DisseminationTargetLevel::State) {
                return ! $active->contains('target_state_id', $target['state_id']);
            }

            return ! $active->contains(fn (RecordDissemination $d) => $d->target_lga_id === $target['lga_id']
                || ($target['state_id'] !== $ownState && $d->target_level === DisseminationTargetLevel::State && $d->target_state_id === $target['state_id']));
        }));
    }

    /**
     * Names and ULIDs for display.
     *
     * @param  list<array{level: DisseminationTargetLevel, state_id: int, lga_id: int|null}>  $targets
     * @return list<array{level: string, ulid: string, name: string, state: string}>
     */
    public function present(array $targets): array
    {
        $states = State::query()->whereKey(array_column($targets, 'state_id'))->get(['id', 'ulid', 'name'])->keyBy('id');
        $lgas = Lga::query()->whereKey(array_filter(array_column($targets, 'lga_id')))->get(['id', 'ulid', 'name'])->keyBy('id');

        return array_values(array_filter(array_map(function (array $t) use ($states, $lgas): ?array {
            $state = $states->get($t['state_id']);
            $lga = $t['lga_id'] === null ? null : $lgas->get($t['lga_id']);

            if ($state === null || ($t['lga_id'] !== null && $lga === null)) {
                return null;
            }

            return [
                'level' => $t['level']->value,
                'ulid' => $lga !== null ? $lga->ulid : $state->ulid,
                'name' => $lga !== null ? "{$lga->name} LGA, {$state->name}" : "{$state->name} State",
                'state' => $state->name,
            ];
        }, $targets)));
    }
}
