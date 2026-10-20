<?php

namespace App\Services\Analytics;

use App\Enums\IncidentCategory;
use App\Enums\OccurrencePrecision;
use App\Enums\Permission;
use App\Enums\Severity;
use App\Models\Arrest;
use App\Models\ArrestPersonOffence;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Incidents (Phase 7C): trend with comparison, by category and type with
 * change, by geography one level below the selection (counts, change, rate
 * per 100,000), by severity over time, and day-of-week × hour patterns for
 * incidents with an exact time.
 */
final class IncidentAnalytics implements AnalyticsArea
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'incidents';
    }

    public function label(): string
    {
        return 'Incidents';
    }

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        return [
            $this->trend($user, $filters),
            $this->byCategory($user, $filters),
            $this->byType($user, $filters),
            $this->byGeography($user, $filters),
            $this->bySeverity($user, $filters),
            $this->timePatterns($user, $filters),
            // Arrests (Phase 11C): a small section of its own.
            ...($user->can('viewAny', Arrest::class) ? [
                $this->arrestsTrend($user, $filters),
                $this->arrestsByOffence($user, $filters),
                $this->arrestsByAgency($user, $filters),
                $this->arrestShareByType($user, $filters),
            ] : []),
        ];
    }

    /**
     * Arrests in the period, through the viewer's scopes and the chosen
     * place. Every arrest counts, also one linked to an incident later
     * marked invalid (the arrest happened).
     *
     * @return Builder<Arrest>
     */
    private function arrests(AnalyticsFilters $filters): Builder
    {
        return $filters->narrow(Arrest::query()
            ->where('arrests.arrested_at', '>=', $filters->start)
            ->where('arrests.arrested_at', '<', $filters->end), 'arrests');
    }

    /**
     * @return array<string, mixed>
     */
    public function arrestsTrend(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $counts = $this->arrests($filters)->toBase()
            ->selectRaw($filters->bucketSql('arrests.arrested_at').' AS bucket, count(*) AS n')
            ->groupBy('bucket')->pluck('n', 'bucket')->map(fn ($n) => (int) $n)->all();
        $data = ChartData::fill($buckets, $counts);
        $rows = array_map(fn (array $b, int $i) => ['bucket' => $b['label'], 'count' => $data[$i]], $buckets, array_keys($buckets));

        return ChartData::make('arrests_trend', 'Arrests over time', 'line', array_column($buckets, 'label'), [['label' => $filters->periodLabel(), 'data' => $data]],
            [['key' => 'bucket', 'label' => 'Period'], ['key' => 'count', 'label' => 'Arrests', 'numeric' => true]], $rows,
            'Every recorded arrest counts, including those linked to incidents later marked invalid.');
    }

    /**
     * Arrested persons per offence, with the offence's threat category.
     *
     * @return array<string, mixed>
     */
    public function arrestsByOffence(User $user, AnalyticsFilters $filters): array
    {
        $arrestIds = $this->arrests($filters)->select('arrests.id');
        $rows = ArrestPersonOffence::query()
            ->whereHas('arrestPerson', fn (Builder $q) => $q->whereIn('arrest_persons.arrest_id', $arrestIds))
            ->join('offence_types', 'offence_types.id', '=', 'arrest_person_offences.offence_type_id')
            ->leftJoin('threat_types', 'threat_types.id', '=', 'offence_types.threat_type_id')
            ->toBase()
            ->selectRaw("offence_types.name AS label, coalesce(threat_types.name, '—') AS threat, count(*) AS count")
            ->groupBy('offence_types.name', 'threat_types.name')
            ->orderByDesc('count')->orderBy('label')->get()
            ->map(fn (object $r) => ['label' => (string) $r->label, 'threat' => (string) $r->threat, 'count' => (int) $r->count])->all();

        return ChartData::make('arrests_by_offence', 'Arrests by offence', 'bar', array_column($rows, 'label'), [['label' => 'Arrested persons', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Offence'], ['key' => 'threat', 'label' => 'Threat category'], ['key' => 'count', 'label' => 'Arrested persons', 'numeric' => true]], $rows,
            'A person held for several offences counts once under each.');
    }

    /**
     * @return array<string, mixed>
     */
    public function arrestsByAgency(User $user, AnalyticsFilters $filters): array
    {
        $rows = $this->arrests($filters)
            ->join('security_agencies', 'security_agencies.id', '=', 'arrests.security_agency_id')
            ->toBase()->selectRaw('security_agencies.name AS label, count(*) AS count')
            ->groupBy('security_agencies.name')->orderByDesc('count')->orderBy('label')->get()
            ->map(fn (object $r) => ['label' => (string) $r->label, 'count' => (int) $r->count])->all();

        return ChartData::make('arrests_by_agency', 'Arrests by agency', 'bar', array_column($rows, 'label'), [['label' => 'Arrests', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Agency'], ['key' => 'count', 'label' => 'Arrests', 'numeric' => true]], $rows);
    }

    /**
     * Of the incidents of each type in the period (counted ones only), the
     * share with at least one linked arrest the viewer may see.
     *
     * @return array<string, mixed>
     */
    public function arrestShareByType(User $user, AnalyticsFilters $filters): array
    {
        // Incidents with an arrest the viewer may see (the arrests' scopes apply).
        $withArrest = '{'.implode(',', Arrest::query()->whereNotNull('arrests.incident_id')->distinct()->pluck('arrests.incident_id')->map(fn ($id) => (int) $id)->all()).'}';
        $rows = $filters->incidents($user)
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id')
            ->toBase()
            ->select('incident_types.name AS label')
            ->selectRaw('count(*) AS incidents')
            ->selectRaw('count(*) FILTER (WHERE incidents.id = ANY(?::bigint[])) AS with_arrest', [$withArrest])
            ->groupBy('incident_types.name')->orderBy('incident_types.name')->get()
            ->map(fn (object $r) => [
                'label' => (string) $r->label,
                'incidents' => (int) $r->incidents,
                'with_arrest' => (int) $r->with_arrest,
                'count' => (int) $r->incidents === 0 ? 0.0 : round((int) $r->with_arrest * 100 / (int) $r->incidents, 1),
            ])->all();

        return ChartData::make('arrest_share_by_type', 'Incidents with an arrest, by type', 'bar', array_column($rows, 'label'), [['label' => '% with an arrest', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Type'], ['key' => 'incidents', 'label' => 'Incidents', 'numeric' => true], ['key' => 'with_arrest', 'label' => 'With an arrest', 'numeric' => true], ['key' => 'count', 'label' => '% with an arrest', 'numeric' => true]], $rows,
            'Drafts and invalid reports are never counted as incidents.');
    }

    /**
     * @return array<string, mixed>
     */
    public function trend(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $current = ChartData::fill($buckets, $this->bucketCounts($filters->incidents($user), $filters));
        $series = [['label' => $filters->periodLabel(), 'data' => $current]];
        $previous = null;

        if ($filters->hasComparison()) {
            $comparisonBuckets = $filters->buckets(comparison: true);
            $previous = array_slice(array_pad(ChartData::fill($comparisonBuckets, $this->bucketCounts($filters->incidents($user, comparison: true), $filters)), count($buckets), 0), 0, count($buckets));
            $series[] = ['label' => $filters->periodLabel(comparison: true), 'data' => $previous, 'comparison' => true];
        }

        $rows = array_map(fn (array $b, int $i) => [
            'bucket' => $b['label'],
            'count' => $current[$i],
            'comparison' => $previous[$i] ?? null,
        ], $buckets, array_keys($buckets));

        return ChartData::make('trend', 'Incidents over time', 'line', array_column($buckets, 'label'), $series,
            $this->columns(['bucket' => 'Period', 'count' => 'Incidents'], $filters), $rows,
            'Drafts and invalid reports are never counted.');
    }

    /**
     * @return array<string, mixed>
     */
    public function byCategory(User $user, AnalyticsFilters $filters): array
    {
        $count = fn (bool $comparison) => $this->grouped($filters->incidents($user, $comparison)
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id'), 'incident_types.category');
        $current = $count(false);
        $previous = $filters->hasComparison() ? $count(true) : [];
        $rows = [];

        foreach (IncidentCategory::cases() as $category) {
            $rows[] = $this->changeRow($category->label(), (int) ($current[$category->value] ?? 0), $filters->hasComparison() ? (int) ($previous[$category->value] ?? 0) : null);
        }

        return $this->barWithChange('by_category', 'By category', $rows, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function byType(User $user, AnalyticsFilters $filters): array
    {
        $count = fn (bool $comparison) => $this->grouped($filters->incidents($user, $comparison)
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id'), 'incident_types.name');
        $current = $count(false);
        $previous = $filters->hasComparison() ? $count(true) : [];
        $names = array_unique([...array_keys($current), ...array_keys($previous)]);
        $rows = array_map(fn (int|string $name) => $this->changeRow((string) $name, (int) ($current[$name] ?? 0), $filters->hasComparison() ? (int) ($previous[$name] ?? 0) : null), $names);
        usort($rows, fn (array $a, array $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);

        return $this->barWithChange('by_type', 'By type', $rows, $filters);
    }

    /**
     * One level below the selection, every place in reach (zeros
     * included), with change and the rate per 100,000 where a population
     * estimate exists.
     *
     * @return array<string, mixed>
     */
    public function byGeography(User $user, AnalyticsFilters $filters): array
    {
        $level = $filters->breakdown($user);
        $column = $level === 'state' ? 'incidents.state_id' : 'incidents.lga_id';
        $current = $this->grouped($filters->incidents($user), $column);
        $previous = $filters->hasComparison() ? $this->grouped($filters->incidents($user, comparison: true), $column) : [];
        $years = max(1 / 365, $filters->start->diffInDays($filters->end) / 365.25);
        $rows = [];

        foreach ($this->places($user, $filters, $level) as $place) {
            $count = (int) ($current[$place['id']] ?? 0);
            $row = $this->changeRow($place['name'], $count, $filters->hasComparison() ? (int) ($previous[$place['id']] ?? 0) : null);
            $row['population'] = $place['population'];
            // Per 100,000 people per year, so periods of any length compare.
            $row['rate'] = $place['population'] ? round($count / $place['population'] * 100000 / $years, 2) : null;
            $row['ulid'] = $place['ulid'];
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]);
        $columns = $this->columns(['label' => $level === 'state' ? 'State' : 'LGA', 'count' => 'Incidents'], $filters);
        $columns[] = ['key' => 'population', 'label' => 'Population', 'numeric' => true];
        $columns[] = ['key' => 'rate', 'label' => 'Per 100,000 a year', 'numeric' => true];

        return ChartData::make('by_geography', $level === 'state' ? 'By state' : 'By LGA', 'table', array_column($rows, 'label'),
            [['label' => 'Incidents', 'data' => array_column($rows, 'count')]], $columns, $rows,
            'Rates use the LGA population estimates and are annualised.',
            ['path' => '/map', 'query' => [...array_intersect_key($filters->incidentListQuery(), array_flip(['from', 'to', 'types', 'category', 'severities'])),
                'layers' => $level === 'state' ? 'state_choropleth' : 'lga_choropleth', 'measure' => 'rate']]);
    }

    /**
     * @return array<string, mixed>
     */
    public function bySeverity(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $rows = $filters->incidents($user)->toBase()
            ->selectRaw($filters->bucketSql('incidents.occurred_at').' AS bucket, incidents.severity AS severity, count(*) AS n')
            ->groupBy('bucket', 'severity')
            ->get();
        $series = [];
        $table = array_map(fn (array $b) => ['bucket' => $b['label']], $buckets);

        foreach (array_reverse(Severity::cases()) as $severity) {
            $byKey = $rows->where('severity', $severity->value)->mapWithKeys(fn ($r) => [(string) $r->bucket => (int) $r->n])->all();
            $data = ChartData::fill($buckets, $byKey);
            $series[] = ['label' => $severity->label(), 'data' => $data];
            foreach ($data as $i => $n) {
                $table[$i][$severity->value] = $n;
            }
        }

        $columns = [['key' => 'bucket', 'label' => 'Period']];
        foreach (array_reverse(Severity::cases()) as $severity) {
            $columns[] = ['key' => $severity->value, 'label' => $severity->label(), 'numeric' => true];
        }

        return ChartData::make('by_severity', 'By severity over time', 'stacked', array_column($buckets, 'label'), $series, $columns, $table);
    }

    /**
     * Day of week × hour of day, exact times only.
     *
     * @return array<string, mixed>
     */
    public function timePatterns(User $user, AnalyticsFilters $filters): array
    {
        $exact = $filters->incidents($user)->where('incidents.occurred_at_precision', OccurrencePrecision::Exact->value);
        $cells = $exact->toBase()
            ->selectRaw('EXTRACT(ISODOW FROM incidents.occurred_at)::int AS dow, EXTRACT(HOUR FROM incidents.occurred_at)::int AS hour, count(*) AS n')
            ->groupBy('dow', 'hour')
            ->get();
        $excluded = $filters->incidents($user)->where('incidents.occurred_at_precision', '!=', OccurrencePrecision::Exact->value)->count();
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $series = [];
        $rows = [];

        foreach ($days as $index => $day) {
            $data = array_fill(0, 24, 0);
            foreach ($cells->where('dow', $index + 1) as $cell) {
                $data[(int) $cell->hour] = (int) $cell->n;
            }
            /** @var list<int> $data */
            $series[] = ['label' => $day, 'data' => $data];
            $rows[] = ['day' => $day, ...array_combine(array_map(fn (int $h) => sprintf('h%02d', $h), range(0, 23)), $data), 'total' => array_sum($data)];
        }

        $columns = [['key' => 'day', 'label' => 'Day']];
        foreach (range(0, 23) as $hour) {
            $columns[] = ['key' => sprintf('h%02d', $hour), 'label' => sprintf('%02d', $hour), 'numeric' => true];
        }
        $columns[] = ['key' => 'total', 'label' => 'Total', 'numeric' => true];

        return ChartData::make('time_patterns', 'Day and hour', 'heatmap', array_map(fn (int $h) => sprintf('%02d', $h), range(0, 23)), $series, $columns, $rows,
            "Incidents with an exact time only; {$excluded} with an approximate time or a date only are not shown.");
    }

    /**
     * @param  Builder<Incident>  $query
     * @return array<string, int>
     */
    private function bucketCounts(Builder $query, AnalyticsFilters $filters): array
    {
        return $query->toBase()
            ->selectRaw($filters->bucketSql('incidents.occurred_at').' AS bucket, count(*) AS n')
            ->groupBy('bucket')
            ->pluck('n', 'bucket')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @param  Builder<Incident>  $query
     * @return array<int|string, int>
     */
    private function grouped(Builder $query, string $column): array
    {
        return $query->toBase()
            ->select($column.' AS grouping')
            ->selectRaw('count(*) AS n')
            ->groupBy('grouping')
            ->pluck('n', 'grouping')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function changeRow(string $label, int $count, ?int $previous): array
    {
        return [
            'label' => $label,
            'count' => $count,
            'comparison' => $previous,
            'change' => $previous === null ? null : $count - $previous,
            'change_pct' => ChartData::percent($count, $previous),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function barWithChange(string $key, string $title, array $rows, AnalyticsFilters $filters): array
    {
        $series = [['label' => $filters->periodLabel(), 'data' => array_column($rows, 'count')]];

        if ($filters->hasComparison()) {
            $series[] = ['label' => $filters->periodLabel(comparison: true), 'data' => array_column($rows, 'comparison'), 'comparison' => true];
        }

        return ChartData::make($key, $title, 'bar', array_column($rows, 'label'), $series,
            $this->columns(['label' => $key === 'by_category' ? 'Category' : 'Type', 'count' => 'Incidents'], $filters), $rows);
    }

    /**
     * @param  array<string, string>  $base
     * @return list<array{key: string, label: string, numeric?: bool}>
     */
    private function columns(array $base, AnalyticsFilters $filters): array
    {
        $columns = [];
        foreach ($base as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, ...($key === 'count' ? ['numeric' => true] : [])];
        }

        if ($filters->hasComparison()) {
            $columns[] = ['key' => 'comparison', 'label' => 'Comparison', 'numeric' => true];
            if (! in_array('bucket', array_keys($base), true)) {
                $columns[] = ['key' => 'change', 'label' => 'Change', 'numeric' => true];
                $columns[] = ['key' => 'change_pct', 'label' => 'Change %', 'numeric' => true];
            }
        }

        return $columns;
    }

    /**
     * Places one level below the selection, within the viewer's reach.
     *
     * @return list<array{id: int, ulid: string, name: string, population: int|null}>
     */
    private function places(User $user, AnalyticsFilters $filters, string $level): array
    {
        $population = 'lgas.population_estimate';

        if ($level === 'state') {
            $states = $this->options->states($user)
                ->when($filters->zone !== null, fn ($c) => $c->where('geopolitical_zone_id', $filters->zone?->id));
            $totals = DB::table('lgas')->whereIn('state_id', $states->pluck('id'))
                // Literal SQL (no input).
                ->selectRaw("state_id, sum({$population}) AS population")
                ->groupBy('state_id')->pluck('population', 'state_id');

            return array_values($states->sortBy('name')->map(fn (State $s) => [
                'id' => $s->id, 'ulid' => $s->ulid, 'name' => $s->name,
                'population' => $totals->get($s->id) === null ? null : (int) $totals->get($s->id),
            ])->all());
        }

        return array_values($this->options->lgaQuery($user)->active()
            ->when($filters->lga !== null, fn (Builder $q) => $q->whereKey($filters->lga?->id))
            ->when($filters->lga === null && $filters->state !== null, fn (Builder $q) => $q->where('lgas.state_id', $filters->state?->id))
            ->select('lgas.id', 'lgas.ulid', 'lgas.name')
            ->selectRaw("{$population} AS population")
            ->orderBy('lgas.name')->get()
            ->map(fn (Lga $l) => [
                'id' => $l->id, 'ulid' => $l->ulid, 'name' => $l->name,
                'population' => $l->getAttribute('population') === null ? null : (int) $l->getAttribute('population'),
            ])->all());
    }
}
