<?php

namespace App\Services\Analytics;

use App\Enums\IncidentStatus;
use App\Enums\JurisdictionLevel;
use App\Models\GeopoliticalZone;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Map\MapFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Analytics filters (Phase 7C): a period (preset or custom), a comparison
 * period, a granularity (automatic from the period length unless chosen),
 * a geography within the viewer's reach, and incident types, category and
 * severities (validated by IncidentFilters).
 *
 * Periods are whole days in the application time zone (Africa/Lagos):
 * `start` inclusive, `end` exclusive. Incident queries always go through
 * the viewer's scopes and the statistics rule (MapFilters::COUNTED: no
 * drafts, no invalid reports).
 */
final class AnalyticsFilters
{
    public const PRESETS = ['7d', '30d', '90d', 'this_month', 'last_month', 'this_quarter', 'last_quarter', 'this_year', 'custom'];

    public const COMPARISONS = ['none', 'previous', 'last_year'];

    public const GRANULARITIES = ['day', 'week', 'month', 'quarter'];

    /** Longest custom period. */
    private const MAX_DAYS = 3 * 366;

    /** Finer granularities are coarsened beyond this many buckets. */
    private const MAX_BUCKETS = 400;

    /**
     * @param  list<string>  $types  incident type ULIDs
     * @param  list<string>  $severities
     */
    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $compare,
        public readonly string $granularity,
        public readonly bool $granularityChosen,
        public readonly ?GeopoliticalZone $zone,
        public readonly ?State $state,
        public readonly ?Lga $lga,
        public readonly array $types,
        public readonly ?string $category,
        public readonly array $severities,
    ) {}

    /**
     * Unknown or invalid values fall back to the defaults (last 30 days, no
     * comparison, automatic granularity, the viewer's whole reach), so an
     * old shared URL still opens. Places outside the viewer's reach are
     * ignored.
     *
     * @param  array<string, mixed>  $input
     */
    public static function from(array $input, User $user, ?CarbonImmutable $now = null): self
    {
        $tz = (string) config('app.timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($tz);
        $preset = in_array($input['period'] ?? null, self::PRESETS, true) ? (string) $input['period'] : '30d';
        [$start, $end] = self::range($preset, $input, $now);

        if ($start === null || $end === null) {
            $preset = '30d';
            [$start, $end] = self::range('30d', [], $now);
        }

        /** @var CarbonImmutable $start */
        /** @var CarbonImmutable $end */
        $compare = in_array($input['compare'] ?? null, self::COMPARISONS, true) ? (string) $input['compare'] : 'none';
        $chosen = in_array($input['granularity'] ?? null, self::GRANULARITIES, true);
        $granularity = self::fit($chosen ? (string) $input['granularity'] : self::automatic($start, $end), $start, $end);

        $incident = IncidentFilters::from([
            'types' => $input['types'] ?? null,
            'category' => $input['category'] ?? null,
            'severities' => $input['severities'] ?? null,
        ])->values;
        [$zone, $state, $lga] = self::places($input, $user);

        /** @var list<string> $types */
        $types = $incident['types'] ?? [];
        /** @var list<string> $severities */
        $severities = $incident['severities'] ?? [];

        return new self($preset, $start, $end, $compare, $granularity, $chosen, $zone, $state, $lga,
            $types, isset($incident['category']) ? (string) $incident['category'] : null, $severities);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function range(string $preset, array $input, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $tomorrow = $today->addDay();

        return match ($preset) {
            '7d' => [$today->subDays(6), $tomorrow],
            '30d' => [$today->subDays(29), $tomorrow],
            '90d' => [$today->subDays(89), $tomorrow],
            'this_month' => [$today->startOfMonth(), $tomorrow],
            'last_month' => [$today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()],
            'this_quarter' => [$today->firstOfQuarter(), $tomorrow],
            'last_quarter' => [$today->firstOfQuarter()->subMonthsNoOverflow(3), $today->firstOfQuarter()],
            'this_year' => [$today->startOfYear(), $tomorrow],
            'custom' => self::custom($input, $now),
            default => [null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private static function custom(array $input, CarbonImmutable $now): array
    {
        $tz = (string) config('app.timezone');
        $parse = fn (mixed $value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? (CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz) ?: null)
            : null;
        $from = $parse($input['from'] ?? null);
        $to = $parse($input['to'] ?? null);

        if ($from === null || $to === null || $to->lessThan($from) || $from->diffInDays($to) > self::MAX_DAYS) {
            return [null, null];
        }

        return [$from, $to->addDay()];
    }

    private static function automatic(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $days = (int) round($start->diffInDays($end));

        return match (true) {
            $days <= 31 => 'day',
            $days <= 183 => 'week',
            $days <= 731 => 'month',
            default => 'quarter',
        };
    }

    /** A chosen granularity is coarsened if it would give too many buckets. */
    private static function fit(string $granularity, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $days = $start->diffInDays($end);
        $order = self::GRANULARITIES;
        $index = (int) array_search($granularity, $order, true);
        $per = ['day' => 1, 'week' => 7, 'month' => 30, 'quarter' => 91];

        while ($index < count($order) - 1 && $days / $per[$order[$index]] > self::MAX_BUCKETS) {
            $index++;
        }

        return $order[$index];
    }

    /**
     * The zone, state or LGA chosen, if within the viewer's reach.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: GeopoliticalZone|null, 1: State|null, 2: Lga|null}
     */
    private static function places(array $input, User $user): array
    {
        $options = app(JurisdictionOptions::class);
        $states = $options->states($user);
        $lga = is_string($input['lga'] ?? null) ? $options->lgaQuery($user)->where('ulid', $input['lga'])->first() : null;

        if ($lga !== null) {
            return [null, $lga->state, $lga];
        }

        $state = is_string($input['state'] ?? null) ? $states->firstWhere('ulid', $input['state']) : null;

        if ($state !== null) {
            return [null, $state, null];
        }

        $zone = is_string($input['zone'] ?? null) && $user->jurisdiction_level === JurisdictionLevel::National
            ? GeopoliticalZone::query()->where('ulid', $input['zone'])->first()
            : null;

        return [$zone !== null && $states->contains('geopolitical_zone_id', $zone->id) ? $zone : null, null, null];
    }

    public function hasComparison(): bool
    {
        return $this->compare !== 'none';
    }

    /**
     * The comparison period: the previous calendar unit for calendar
     * presets, the same number of days just before for rolling and custom
     * periods, or the same dates one year earlier.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function comparison(): ?array
    {
        if ($this->compare === 'last_year') {
            return [$this->start->subYearNoOverflow(), $this->end->subYearNoOverflow()];
        }

        if ($this->compare !== 'previous') {
            return null;
        }

        $months = match ($this->preset) {
            'this_month', 'last_month' => 1,
            'this_quarter', 'last_quarter' => 3,
            'this_year' => 12,
            default => null,
        };

        if ($months === null) {
            $days = (int) round($this->start->diffInDays($this->end));

            return [$this->start->subDays($days), $this->start];
        }

        $start = $this->start->subMonthsNoOverflow($months);
        $whole = $this->start->addMonthsNoOverflow($months)->equalTo($this->end);

        return [$start, $whole ? $this->start : $start->addDays((int) round($this->start->diffInDays($this->end)))->min($this->start)];
    }

    /**
     * The viewer's counted incidents (statistics rule) in the period (or the
     * comparison period), filtered by geography, types and severities.
     *
     * @return Builder<Incident>
     */
    public function incidents(User $user, bool $comparison = false): Builder
    {
        [$start, $end] = $comparison ? ($this->comparison() ?? [$this->start, $this->end]) : [$this->start, $this->end];

        return $this->incidentsBetween($user, $start, $end);
    }

    /**
     * @return Builder<Incident>
     */
    public function incidentsBetween(User $user, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        $query = IncidentFilters::from(array_filter([
            'statuses' => MapFilters::COUNTED,
            'types' => $this->types ?: null,
            'category' => $this->category,
            'severities' => $this->severities ?: null,
        ]))->apply(Incident::query(), $user, sort: false)
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->where('incidents.occurred_at', '>=', $start)
            ->where('incidents.occurred_at', '<', $end);

        return $this->narrow($query, 'incidents');
    }

    /**
     * Narrows any query with state_id / lga_id columns to the chosen place.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function narrow(Builder $query, string $table): Builder
    {
        return match (true) {
            $this->lga !== null => $query->where("{$table}.lga_id", $this->lga->id),
            $this->state !== null => $query->where("{$table}.state_id", $this->state->id),
            $this->zone !== null => $query->whereIn("{$table}.state_id", State::query()->where('geopolitical_zone_id', $this->zone->id)->select('id')),
            default => $query,
        };
    }

    /**
     * One level below the selection: states (national or a zone) or LGAs
     * (a state, a state-level viewer, an LGA).
     */
    public function breakdown(User $user): string
    {
        return match (true) {
            $this->lga !== null, $this->state !== null => 'lga',
            $this->zone !== null => 'state',
            $user->jurisdiction_level === JurisdictionLevel::National => 'state',
            default => 'lga',
        };
    }

    /**
     * SQL for the bucket a timestamp column falls in (the session time zone
     * is Africa/Lagos, CLAUDE.md section 5). Granularity is whitelisted.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    public function bucketSql(string $column): string
    {
        $unit = match ($this->granularity) {
            'week' => 'week',
            'month' => 'month',
            'quarter' => 'quarter',
            default => 'day',
        };

        return "date_trunc('{$unit}', {$column})::date";
    }

    /**
     * Bucket start dates (Y-m-d) and labels for the period (or comparison).
     *
     * @return list<array{key: string, label: string}>
     */
    public function buckets(bool $comparison = false): array
    {
        [$start, $end] = $comparison ? ($this->comparison() ?? [$this->start, $this->end]) : [$this->start, $this->end];
        $cursor = match ($this->granularity) {
            'week' => $start->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $start->startOfMonth(),
            'quarter' => $start->firstOfQuarter(),
            default => $start,
        };
        $buckets = [];

        while ($cursor->lessThan($end)) {
            $buckets[] = ['key' => $cursor->toDateString(), 'label' => $this->label($cursor)];
            $cursor = match ($this->granularity) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow(),
                'quarter' => $cursor->addMonthsNoOverflow(3),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    private function label(CarbonImmutable $date): string
    {
        return match ($this->granularity) {
            'week' => 'Wk of '.$date->format('j M'),
            'month' => $date->format('M Y'),
            'quarter' => 'Q'.$date->quarter.' '.$date->year,
            default => $date->format('j M'),
        };
    }

    public function periodLabel(bool $comparison = false): string
    {
        [$start, $end] = $comparison ? ($this->comparison() ?? [$this->start, $this->end]) : [$this->start, $this->end];

        return $start->format('j M Y').' – '.$end->subDay()->format('j M Y');
    }

    /**
     * Canonical values: the URL query (shareable) and cache keys. The
     * resolved dates are included so a rolling period's cache follows the
     * calendar.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return array_filter([
            'period' => $this->preset,
            'from' => $this->preset === 'custom' ? $this->start->toDateString() : null,
            'to' => $this->preset === 'custom' ? $this->end->subDay()->toDateString() : null,
            'compare' => $this->compare === 'none' ? null : $this->compare,
            'granularity' => $this->granularityChosen ? $this->granularity : null,
            'zone' => $this->zone?->ulid,
            'state' => $this->lga === null ? $this->state?->ulid : null,
            'lga' => $this->lga?->ulid,
            'types' => $this->types ?: null,
            'category' => $this->category,
            'severities' => $this->severities ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function cacheKey(): array
    {
        return [...$this->values(), '_range' => [$this->start->toDateString(), $this->end->toDateString(), $this->granularity]];
    }

    /**
     * Incident list / map filters for the same period and filters.
     *
     * @return array<string, mixed>
     */
    public function incidentListQuery(): array
    {
        return array_filter([
            'from' => $this->start->toDateString(),
            'to' => $this->end->subDay()->toDateString(),
            'types' => $this->types ? implode(',', $this->types) : null,
            'category' => $this->category,
            'severities' => $this->severities ? implode(',', $this->severities) : null,
            'state' => $this->state?->ulid,
            'lga' => $this->lga?->ulid,
            'statuses' => implode(',', MapFilters::COUNTED),
        ], fn ($v) => $v !== null);
    }

    /**
     * For the page: the resolved period, comparison and granularity.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $comparison = $this->comparison();

        return [
            'values' => $this->values(),
            'start' => $this->start->toDateString(),
            'end' => $this->end->subDay()->toDateString(),
            'label' => $this->periodLabel(),
            'comparison' => $comparison === null ? null : ['start' => $comparison[0]->toDateString(), 'end' => $comparison[1]->subDay()->toDateString(), 'label' => $this->periodLabel(true)],
            'granularity' => $this->granularity,
            'zone' => $this->zone === null ? null : ['ulid' => $this->zone->ulid, 'name' => $this->zone->name],
            'state' => $this->state === null ? null : ['ulid' => $this->state->ulid, 'name' => $this->state->name],
            'lga' => $this->lga === null ? null : ['ulid' => $this->lga->ulid, 'name' => $this->lga->name],
        ];
    }
}
