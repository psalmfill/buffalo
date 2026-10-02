<?php

namespace App\Services\Analytics;

use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Enums\Permission;
use App\Models\CoverageReport;
use App\Models\Lga;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Security\CoverageSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Coverage (Phase 7C), for the expected report period type
 * (buffalo.coverage.expected_period) and periods starting in the analytics
 * period:
 * - submission compliance per period (LGAs that submitted ÷ active LGAs in
 *   reach) and by state or LGA;
 * - rating distribution per period;
 * - LGAs rated inadequate or critical in 3 or more consecutive reports;
 * - personnel, vehicles and patrol frequency from deployments.
 *
 * Reports are read through their scopes (jurisdiction and classification).
 */
final class CoverageAnalytics implements AnalyticsArea
{
    private const STREAK = 3;

    public function __construct(
        private readonly JurisdictionOptions $options,
        private readonly CoverageSchedule $schedule,
    ) {}

    public function key(): string
    {
        return 'coverage';
    }

    public function label(): string
    {
        return 'Coverage';
    }

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo(Permission::CoverageView->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        return [
            $this->compliance($user, $filters),
            $this->complianceByPlace($user, $filters),
            $this->ratings($user, $filters),
            $this->persistent($user, $filters),
            $this->deployments($user, $filters),
        ];
    }

    /**
     * Periods of the expected type starting within the analytics period.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public function periods(AnalyticsFilters $filters): array
    {
        $type = $this->schedule->periodType();
        [$start] = $type->periodContaining($filters->start);
        $periods = [];

        while ($start->lessThan($filters->end) && count($periods) < 400) {
            [$from, $to] = $type->periodContaining($start);
            if ($from->greaterThanOrEqualTo($filters->start)) {
                $periods[] = ['start' => $from, 'end' => $to, 'label' => $type->value === 'weekly' ? 'Wk of '.$from->format('j M') : ($type->value === 'quarterly' ? 'Q'.$from->quarter.' '.$from->year : $from->format('M Y'))];
            }
            $start = $to->addDay();
        }

        return $periods;
    }

    /**
     * @return Builder<CoverageReport>
     */
    public function reports(AnalyticsFilters $filters): Builder
    {
        return $filters->narrow(CoverageReport::query()
            ->where('coverage_reports.period_type', $this->schedule->periodType()->value)
            ->where('coverage_reports.period_start', '>=', $filters->start->toDateString())
            ->where('coverage_reports.period_start', '<', $filters->end->toDateString()), 'coverage_reports');
    }

    /**
     * @return Builder<Lga>
     */
    private function lgas(User $user, AnalyticsFilters $filters): Builder
    {
        return $this->options->lgaQuery($user)->active()
            ->when($filters->lga !== null, fn (Builder $q) => $q->whereKey($filters->lga?->id))
            ->when($filters->lga === null && $filters->state !== null, fn (Builder $q) => $q->where('lgas.state_id', $filters->state?->id))
            ->when($filters->lga === null && $filters->state === null && $filters->zone !== null, fn (Builder $q) => $q->whereIn('lgas.state_id', DB::table('states')->where('geopolitical_zone_id', $filters->zone?->id)->select('id')));
    }

    /** @return list<string> */
    private static function submitted(): array
    {
        return array_values(array_map(fn (CoverageReportStatus $s) => $s->value, array_filter(CoverageReportStatus::cases(), fn (CoverageReportStatus $s) => $s->countsAsSubmitted())));
    }

    /**
     * @return array<string, mixed>
     */
    public function compliance(User $user, AnalyticsFilters $filters): array
    {
        $expected = $this->lgas($user, $filters)->count();
        $submitted = $this->reports($filters)->whereIn('coverage_reports.status', self::submitted())->toBase()
            ->selectRaw('coverage_reports.period_start::text AS period, count(DISTINCT coverage_reports.lga_id) AS n')
            ->groupBy('period')->pluck('n', 'period');
        $rows = array_map(fn (array $p) => [
            'label' => $p['label'],
            'submitted' => (int) ($submitted[$p['start']->toDateString()] ?? 0),
            'expected' => $expected,
            'rate' => ThreatAnalytics::rate((int) ($submitted[$p['start']->toDateString()] ?? 0), $expected),
        ], $this->periods($filters));

        return ChartData::make('compliance', 'Report submission compliance', 'line', array_column($rows, 'label'),
            [['label' => 'Submitted (%)', 'data' => array_column($rows, 'rate')]], [
                ['key' => 'label', 'label' => 'Period'],
                ['key' => 'submitted', 'label' => 'LGAs submitted', 'numeric' => true],
                ['key' => 'expected', 'label' => 'LGAs expected', 'numeric' => true],
                ['key' => 'rate', 'label' => 'Compliance %', 'numeric' => true],
            ], $rows, 'Expected: every active LGA in reach, one '.$this->schedule->periodType()->value.' report per period.');
    }

    /**
     * @return array<string, mixed>
     */
    public function complianceByPlace(User $user, AnalyticsFilters $filters): array
    {
        $level = $filters->breakdown($user);
        $periods = count($this->periods($filters));
        $lgas = $this->lgas($user, $filters)->with('state')->get(['lgas.id', 'lgas.name', 'lgas.state_id']);
        $submitted = $this->reports($filters)->whereIn('coverage_reports.status', self::submitted())->toBase()
            ->selectRaw('coverage_reports.lga_id AS lga, count(DISTINCT coverage_reports.period_start) AS n')
            ->groupBy('lga')->pluck('n', 'lga');
        $groups = $level === 'state' ? $lgas->groupBy(fn (Lga $l) => $l->state->name) : $lgas->groupBy(fn (Lga $l) => $l->name);
        $rows = [];

        foreach ($groups->sortKeys() as $name => $members) {
            $expected = $periods * $members->count();
            $done = (int) $members->sum(fn (Lga $l) => (int) ($submitted[$l->id] ?? 0));
            $rows[] = ['label' => (string) $name, 'submitted' => $done, 'expected' => $expected, 'rate' => ThreatAnalytics::rate($done, $expected)];
        }

        return ChartData::make('compliance_by_place', $level === 'state' ? 'Compliance by state' : 'Compliance by LGA', 'table', array_column($rows, 'label'),
            [['label' => 'Compliance (%)', 'data' => array_column($rows, 'rate')]], [
                ['key' => 'label', 'label' => $level === 'state' ? 'State' : 'LGA'],
                ['key' => 'submitted', 'label' => 'Reports submitted', 'numeric' => true],
                ['key' => 'expected', 'label' => 'Reports expected', 'numeric' => true],
                ['key' => 'rate', 'label' => 'Compliance %', 'numeric' => true],
            ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function ratings(User $user, AnalyticsFilters $filters): array
    {
        $counts = $this->reports($filters)->whereIn('coverage_reports.status', self::submitted())->toBase()
            ->selectRaw('coverage_reports.period_start::text AS period, coverage_reports.overall_rating AS rating, count(*) AS n')
            ->groupBy('period', 'rating')->get();
        $periods = $this->periods($filters);
        $series = [];
        $table = array_map(fn (array $p) => ['label' => $p['label']], $periods);
        $columns = [['key' => 'label', 'label' => 'Period']];

        foreach (CoverageRating::cases() as $rating) {
            $data = array_map(fn (array $p) => (int) $counts->where('period', $p['start']->toDateString())->where('rating', $rating->value)->sum('n'), $periods);
            $series[] = ['label' => $rating->label(), 'data' => $data];
            $columns[] = ['key' => $rating->value, 'label' => $rating->label(), 'numeric' => true];
            foreach ($data as $i => $n) {
                $table[$i][$rating->value] = $n;
            }
        }

        return ChartData::make('ratings', 'Coverage ratings', 'stacked', array_column($periods, 'label'), $series, $columns, $table);
    }

    /**
     * LGAs whose latest reports (up to the end of the period) are rated
     * inadequate or critical 3 or more times in a row.
     *
     * @return array<string, mixed>
     */
    public function persistent(User $user, AnalyticsFilters $filters): array
    {
        /** @var Collection<int, CoverageReport> $reports */
        $reports = $filters->narrow(CoverageReport::query(), 'coverage_reports')
            ->where('coverage_reports.period_type', $this->schedule->periodType()->value)
            ->whereIn('coverage_reports.status', self::submitted())
            ->where('coverage_reports.period_start', '<', $filters->end->toDateString())
            ->whereIn('coverage_reports.lga_id', $this->lgas($user, $filters)->select('lgas.id'))
            ->with('lga.state')
            ->orderBy('coverage_reports.lga_id')->orderByDesc('coverage_reports.period_start')
            ->get(['coverage_reports.id', 'coverage_reports.lga_id', 'coverage_reports.state_id', 'coverage_reports.period_start', 'coverage_reports.overall_rating']);
        $rows = [];

        foreach ($reports->groupBy('lga_id') as $lgaReports) {
            $streak = 0;
            foreach ($lgaReports as $report) {
                if (! in_array($report->overall_rating, [CoverageRating::Inadequate, CoverageRating::Critical], true)) {
                    break;
                }
                $streak++;
            }

            if ($streak >= self::STREAK) {
                /** @var CoverageReport $latest */
                $latest = $lgaReports->first();
                $since = $lgaReports->slice($streak - 1, 1)->first();
                $rows[] = [
                    'label' => $latest->lga->name,
                    'state' => $latest->lga->state->name,
                    'streak' => $streak,
                    'latest' => $latest->overall_rating->label(),
                    'since' => $since?->period_start->toDateString(),
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$b['streak'], $a['label']] <=> [$a['streak'], $b['label']]);

        return ChartData::make('persistent', 'LGAs persistently inadequate or critical', 'table', array_column($rows, 'label'),
            [['label' => 'Consecutive reports', 'data' => array_column($rows, 'streak')]], [
                ['key' => 'label', 'label' => 'LGA'],
                ['key' => 'state', 'label' => 'State'],
                ['key' => 'streak', 'label' => 'Consecutive reports', 'numeric' => true],
                ['key' => 'latest', 'label' => 'Latest rating'],
                ['key' => 'since', 'label' => 'Since period'],
            ], $rows, 'Three or more consecutive submitted reports rated inadequate or critical, up to the end of the period.');
    }

    /**
     * @return array<string, mixed>
     */
    public function deployments(User $user, AnalyticsFilters $filters): array
    {
        $totals = DB::table('coverage_report_deployments AS d')
            ->join('coverage_reports AS r', 'r.id', '=', 'd.coverage_report_id')
            ->whereNull('d.deleted_at')
            ->whereIn('d.coverage_report_id', $this->reports($filters)->whereIn('coverage_reports.status', self::submitted())->select('coverage_reports.id'))
            ->selectRaw('r.period_start::text AS period, sum(d.personnel_count) AS personnel, sum(d.vehicles_count) AS vehicles')
            ->selectRaw("count(*) FILTER (WHERE d.patrol_frequency IN ('continuous', 'daily')) AS daily_patrols")
            ->selectRaw('count(*) AS deployments')
            ->groupBy('period')->get()->keyBy('period');
        $periods = $this->periods($filters);
        $rows = array_map(function (array $p) use ($totals): array {
            $t = $totals->get($p['start']->toDateString());

            return [
                'label' => $p['label'],
                'personnel' => (int) ($t->personnel ?? 0),
                'vehicles' => (int) ($t->vehicles ?? 0),
                'deployments' => (int) ($t->deployments ?? 0),
                'daily_patrols' => (int) ($t->daily_patrols ?? 0),
            ];
        }, $periods);

        return ChartData::make('deployments', 'Personnel and patrols reported', 'line', array_column($rows, 'label'), [
            ['label' => 'Personnel', 'data' => array_column($rows, 'personnel')],
            ['label' => 'Vehicles', 'data' => array_column($rows, 'vehicles')],
            ['label' => 'Deployments with daily or continuous patrols', 'data' => array_column($rows, 'daily_patrols')],
        ], [
            ['key' => 'label', 'label' => 'Period'],
            ['key' => 'personnel', 'label' => 'Personnel', 'numeric' => true],
            ['key' => 'vehicles', 'label' => 'Vehicles', 'numeric' => true],
            ['key' => 'deployments', 'label' => 'Deployments', 'numeric' => true],
            ['key' => 'daily_patrols', 'label' => 'Daily or continuous patrols', 'numeric' => true],
        ], $rows, 'From the deployments in submitted coverage reports.');
    }
}
