<?php

namespace App\Services\Analytics;

use App\Enums\CasualtyGroup;
use App\Enums\CasualtyOutcome;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Casualties (Phase 7C): people affected (adults, children and unknown
 * summed) by outcome over time, by group, security personnel by agency,
 * abducted versus rescued for kidnapping-type incidents, and the incident
 * types with most people killed and abducted.
 *
 * Casualty rows are counted only for incidents the viewer can see and
 * that the statistics rule counts (the scoped incident query, as a
 * subquery).
 */
final class CasualtyAnalytics implements AnalyticsArea
{
    private const PEOPLE = '(c.adult_male + c.adult_female + c.child_male + c.child_female + c.unknown)';

    public function key(): string
    {
        return 'casualties';
    }

    public function label(): string
    {
        return 'Casualties';
    }

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        return [
            $this->byOutcome($user, $filters),
            $this->byGroup($user, $filters),
            $this->securityByAgency($user, $filters),
            $this->abductedRescued($user, $filters),
            $this->topTypes($user, $filters, CasualtyOutcome::Killed),
            $this->topTypes($user, $filters, CasualtyOutcome::Abducted),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function byOutcome(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $rows = $this->casualties($user, $filters)
            ->selectRaw($filters->bucketSql('i.occurred_at').' AS bucket, c.outcome AS outcome, sum('.self::PEOPLE.') AS people')
            ->groupBy('bucket', 'outcome')->get();

        return $this->stackedBy($buckets, $rows, 'outcome', array_map(fn (CasualtyOutcome $o) => [$o->value, $o->label()], CasualtyOutcome::cases()),
            'by_outcome', 'People affected by outcome over time', 'line');
    }

    /**
     * @return array<string, mixed>
     */
    public function byGroup(User $user, AnalyticsFilters $filters): array
    {
        $totals = $this->casualties($user, $filters)
            ->selectRaw('c."group" AS grouping, c.outcome AS outcome, sum('.self::PEOPLE.') AS people')
            ->groupBy('grouping', 'outcome')->get();
        $rows = [];

        foreach (CasualtyGroup::cases() as $group) {
            $row = ['label' => $group->label()];
            foreach (CasualtyOutcome::cases() as $outcome) {
                $row[$outcome->value] = (int) $totals->where('grouping', $group->value)->where('outcome', $outcome->value)->sum('people');
            }
            $row['total'] = array_sum(array_filter($row, 'is_int'));
            $rows[] = $row;
        }

        return ChartData::make('by_group', 'By group', 'stacked', array_column($rows, 'label'),
            array_map(fn (CasualtyOutcome $o) => ['label' => $o->label(), 'data' => array_column($rows, $o->value)], CasualtyOutcome::cases()),
            $this->outcomeColumns('Group'), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function securityByAgency(User $user, AnalyticsFilters $filters): array
    {
        $totals = $this->casualties($user, $filters)
            ->where('c.group', CasualtyGroup::SecurityPersonnel->value)
            ->leftJoin('security_agencies AS a', 'a.id', '=', 'c.security_agency_id')
            ->selectRaw("COALESCE(a.name, 'Agency not recorded') AS agency, c.outcome AS outcome, sum(".self::PEOPLE.') AS people')
            ->groupBy('agency', 'outcome')->get();
        $rows = [];

        foreach ($totals->pluck('agency')->unique()->sort()->values() as $agency) {
            $row = ['label' => (string) $agency];
            foreach (CasualtyOutcome::cases() as $outcome) {
                $row[$outcome->value] = (int) $totals->where('agency', $agency)->where('outcome', $outcome->value)->sum('people');
            }
            $row['total'] = array_sum(array_filter($row, 'is_int'));
            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        return ChartData::make('security_by_agency', 'Security personnel by agency', 'stacked', array_column($rows, 'label'),
            array_map(fn (CasualtyOutcome $o) => ['label' => $o->label(), 'data' => array_column($rows, $o->value)], CasualtyOutcome::cases()),
            $this->outcomeColumns('Agency'), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function abductedRescued(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $codes = (array) config('buffalo.analytics.kidnapping_type_codes');
        $rows = $this->casualties($user, $filters)
            ->join('incident_types AS t', 't.id', '=', 'i.incident_type_id')
            ->whereIn('t.code', $codes)
            ->whereIn('c.outcome', [CasualtyOutcome::Abducted->value, CasualtyOutcome::Rescued->value])
            ->selectRaw($filters->bucketSql('i.occurred_at').' AS bucket, c.outcome AS outcome, sum('.self::PEOPLE.') AS people')
            ->groupBy('bucket', 'outcome')->get();

        return $this->stackedBy($buckets, $rows, 'outcome', [[CasualtyOutcome::Abducted->value, 'Abducted'], [CasualtyOutcome::Rescued->value, 'Rescued']],
            'abducted_rescued', 'Abducted and rescued (kidnapping incidents)', 'line');
    }

    /**
     * @return array<string, mixed>
     */
    public function topTypes(User $user, AnalyticsFilters $filters, CasualtyOutcome $outcome): array
    {
        $rows = $this->casualties($user, $filters)
            ->join('incident_types AS t', 't.id', '=', 'i.incident_type_id')
            ->where('c.outcome', $outcome->value)
            ->selectRaw('t.name AS label, sum('.self::PEOPLE.') AS people, count(DISTINCT i.id) AS incidents')
            ->groupBy('t.name')
            ->orderByDesc('people')->orderBy('t.name')
            ->limit(10)->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'people' => (int) $r->people, 'incidents' => (int) $r->incidents])
            ->values()->all();
        $verb = $outcome === CasualtyOutcome::Killed ? 'killed' : 'abducted';

        return ChartData::make("top_types_{$verb}", "Incident types by people {$verb}", 'bar', array_column($rows, 'label'),
            [['label' => 'People '.$verb, 'data' => array_column($rows, 'people')]],
            [['key' => 'label', 'label' => 'Type'], ['key' => 'people', 'label' => 'People '.$verb, 'numeric' => true], ['key' => 'incidents', 'label' => 'Incidents', 'numeric' => true]],
            $rows);
    }

    /**
     * Casualty rows (alias c) joined to their incident (alias i), for the
     * viewer's counted incidents in the period only.
     */
    private function casualties(User $user, AnalyticsFilters $filters): QueryBuilder
    {
        return DB::table('incident_casualties AS c')
            ->join('incidents AS i', 'i.id', '=', 'c.incident_id')
            ->whereNull('c.deleted_at')
            ->whereIn('c.incident_id', $filters->incidents($user)->select('incidents.id'));
    }

    /**
     * @param  list<array{key: string, label: string}>  $buckets
     * @param  Collection<int, \stdClass>  $rows
     * @param  list<array{0: string, 1: string}>  $values
     * @return array<string, mixed>
     */
    private function stackedBy(array $buckets, $rows, string $field, array $values, string $key, string $title, string $kind): array
    {
        $series = [];
        $table = array_map(fn (array $b) => ['bucket' => $b['label']], $buckets);
        $columns = [['key' => 'bucket', 'label' => 'Period']];

        foreach ($values as [$value, $label]) {
            $byKey = $rows->where($field, $value)->mapWithKeys(fn ($r) => [(string) $r->bucket => (int) $r->people])->all();
            $data = ChartData::fill($buckets, $byKey);
            $series[] = ['label' => $label, 'data' => $data];
            $columns[] = ['key' => $value, 'label' => $label, 'numeric' => true];
            foreach ($data as $i => $n) {
                $table[$i][$value] = $n;
            }
        }

        return ChartData::make($key, $title, $kind, array_column($buckets, 'label'), $series, $columns, $table, 'People affected, from casualty records of counted incidents.');
    }

    /**
     * @return list<array{key: string, label: string, numeric?: bool}>
     */
    private function outcomeColumns(string $first): array
    {
        return [
            ['key' => 'label', 'label' => $first],
            ...array_map(fn (CasualtyOutcome $o) => ['key' => $o->value, 'label' => $o->label(), 'numeric' => true], CasualtyOutcome::cases()),
            ['key' => 'total', 'label' => 'Total', 'numeric' => true],
        ];
    }
}
