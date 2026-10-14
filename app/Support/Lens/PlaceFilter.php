<?php

namespace App\Support\Lens;

use App\Models\GeopoliticalZone;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Validation\Rule;

/**
 * The geopolitical zone filter shared by every list (Phase 11A.2), next to
 * each list's own state filter: a zone narrows to its states. It only
 * narrows; the jurisdiction scope still decides what is visible. Zones on
 * offer are those within the user's reach (national users only).
 */
final class PlaceFilter
{
    /**
     * @return list<mixed>
     */
    public static function zoneRule(User $user): array
    {
        return ['nullable', 'string', Rule::in(app(StateLens::class)->zones($user)->pluck('ulid')->all())];
    }

    public static function zone(mixed $ulid): ?GeopoliticalZone
    {
        return is_string($ulid) && $ulid !== '' ? GeopoliticalZone::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * Narrows a query on its state column to the zone's states.
     *
     * @template TQuery of Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function apply(Builder|QueryBuilder $query, string $stateColumn, ?GeopoliticalZone $zone): Builder|QueryBuilder
    {
        if ($zone !== null) {
            $query->whereIn($stateColumn, State::query()->where('geopolitical_zone_id', $zone->id)->select('id'));
        }

        return $query;
    }

    /**
     * Zone options for a list's filter bar (empty for users without a lens).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(User $user): array
    {
        return array_values(app(StateLens::class)->zones($user)->map(fn (GeopoliticalZone $z) => ['value' => $z->ulid, 'label' => $z->name])->all());
    }
}
