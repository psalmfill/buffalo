<?php

namespace App\Services\Analytics;

use App\Enums\ConcernLevel;
use App\Enums\Permission;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\BlackspotSuggestion;
use App\Models\Flashpoint;
use App\Models\RecordDissemination;
use App\Models\ThreatReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Threats and flashpoints (Phase 7C):
 * - threat reports created in the period, by type, urgency and credibility,
 *   and their status outcomes;
 * - the materialisation rate by credibility: of the reports that reached a
 *   final status (materialised, mitigated, expired, withdrawn), the share
 *   that materialised;
 * - flashpoints by their assessed concern level at the end of each period
 *   bucket; counted incidents inside a flashpoint area or linked to one,
 *   versus elsewhere;
 * - blackspot suggestions generated, accepted and dismissed;
 * - disseminations of the viewer's records: sent, acknowledged, time to
 *   acknowledge.
 *
 * Threat reports and flashpoints are the viewer's OWN (ownJurisdiction):
 * records disseminated to them belong to another area's figures.
 */
final class ThreatAnalytics implements AnalyticsArea
{
    /** Final statuses for the materialisation rate. */
    private const FINAL = ['materialised', 'mitigated', 'expired', 'withdrawn'];

    public function key(): string
    {
        return 'threats';
    }

    public function label(): string
    {
        return 'Threats & flashpoints';
    }

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo(Permission::ThreatsView->value) || $user->checkPermissionTo(Permission::FlashpointsView->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        $charts = [];

        if ($user->checkPermissionTo(Permission::ThreatsView->value)) {
            $charts[] = $this->threatsBy($user, $filters, 'threat_type', 'Threat reports by type');
            $charts[] = $this->threatsBy($user, $filters, 'urgency', 'Threat reports by urgency');
            $charts[] = $this->threatsBy($user, $filters, 'credibility', 'Threat reports by credibility');
            $charts[] = $this->outcomes($user, $filters);
            $charts[] = $this->materialisation($user, $filters);
        }

        if ($user->checkPermissionTo(Permission::FlashpointsView->value)) {
            $charts[] = $this->concernOverTime($user, $filters);
            if ($user->checkPermissionTo(Permission::IncidentsView->value)) {
                $charts[] = $this->nearFlashpoints($user, $filters);
            }
        }

        if ($user->checkPermissionTo(Permission::BlackspotsSuggestions->value)) {
            $charts[] = $this->suggestions($user, $filters);
        }

        if ($user->checkPermissionTo(Permission::DisseminationsView->value)) {
            $charts[] = $this->disseminations($user, $filters);
        }

        return $charts;
    }

    /**
     * @return Builder<ThreatReport>
     */
    public function threats(User $user, AnalyticsFilters $filters): Builder
    {
        return $filters->narrow(ThreatReport::query()->ownJurisdiction($user)
            ->where('threat_reports.created_at', '>=', $filters->start)
            ->where('threat_reports.created_at', '<', $filters->end), 'threat_reports');
    }

    /**
     * @param  'threat_type'|'urgency'|'credibility'  $by
     * @return array<string, mixed>
     */
    public function threatsBy(User $user, AnalyticsFilters $filters, string $by, string $title): array
    {
        $query = $this->threats($user, $filters)->toBase();

        if ($by === 'threat_type') {
            $counts = $query->join('threat_types', 'threat_types.id', '=', 'threat_reports.threat_type_id')
                ->selectRaw('threat_types.name AS label, count(*) AS n')->groupBy('threat_types.name')->orderByDesc('n')->pluck('n', 'label');
            $rows = $counts->map(fn ($n, $label) => ['label' => (string) $label, 'count' => (int) $n])->values()->all();
        } else {
            $counts = $query->selectRaw("threat_reports.{$by} AS value, count(*) AS n")->groupBy('value')->pluck('n', 'value');
            $cases = $by === 'urgency' ? ThreatUrgency::cases() : ThreatCredibility::cases();
            $rows = array_map(fn ($case) => ['label' => $case->label(), 'count' => (int) ($counts[$case->value] ?? 0)], $cases);
        }

        return ChartData::make("threats_by_{$by}", $title, 'bar', array_column($rows, 'label'),
            [['label' => 'Threat reports', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => ucfirst(str_replace('_', ' ', $by))], ['key' => 'count', 'label' => 'Threat reports', 'numeric' => true]], array_values($rows));
    }

    /**
     * @return array<string, mixed>
     */
    public function outcomes(User $user, AnalyticsFilters $filters): array
    {
        $counts = $this->threats($user, $filters)->toBase()->selectRaw('threat_reports.status AS value, count(*) AS n')->groupBy('value')->pluck('n', 'value');
        $rows = array_map(fn (ThreatStatus $s) => ['label' => $s->label(), 'count' => (int) ($counts[$s->value] ?? 0)], ThreatStatus::cases());

        return ChartData::make('threat_outcomes', 'Threat report outcomes', 'bar', array_column($rows, 'label'),
            [['label' => 'Threat reports', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Status'], ['key' => 'count', 'label' => 'Threat reports', 'numeric' => true]], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function materialisation(User $user, AnalyticsFilters $filters): array
    {
        $counts = $this->threats($user, $filters)->toBase()
            ->selectRaw('threat_reports.credibility AS credibility, threat_reports.status AS status, count(*) AS n')
            ->groupBy('credibility', 'status')->get();
        $rows = [];

        foreach (ThreatCredibility::cases() as $credibility) {
            $mine = $counts->where('credibility', $credibility->value);
            $final = (int) $mine->whereIn('status', self::FINAL)->sum('n');
            $materialised = (int) $mine->where('status', ThreatStatus::Materialised->value)->sum('n');
            $rows[] = [
                'label' => $credibility->label(),
                'final' => $final,
                'materialised' => $materialised,
                'rate' => self::rate($materialised, $final),
                'open' => (int) $mine->where('status', ThreatStatus::Active->value)->sum('n'),
            ];
        }

        return ChartData::make('materialisation', 'Materialisation rate by credibility', 'bar', array_column($rows, 'label'),
            [['label' => 'Materialised (%)', 'data' => array_column($rows, 'rate')]], [
                ['key' => 'label', 'label' => 'Credibility'],
                ['key' => 'final', 'label' => 'Reached a final status', 'numeric' => true],
                ['key' => 'materialised', 'label' => 'Materialised', 'numeric' => true],
                ['key' => 'rate', 'label' => 'Materialised %', 'numeric' => true],
                ['key' => 'open', 'label' => 'Still active (not counted)', 'numeric' => true],
            ], $rows, 'Of the threat reports created in the period that reached a final status (materialised, mitigated, expired or withdrawn), the share that materialised.');
    }

    /** Share in percent, one decimal; null when nothing to divide by. */
    public static function rate(int $part, int $whole): ?float
    {
        return $whole === 0 ? null : round($part / $whole * 100, 1);
    }

    /**
     * Flashpoints by the concern level of their latest assessment at the
     * end of each bucket.
     *
     * @return array<string, mixed>
     */
    public function concernOverTime(User $user, AnalyticsFilters $filters): array
    {
        $buckets = $filters->buckets();
        $ids = $filters->narrow(Flashpoint::query()->ownJurisdiction($user), 'flashpoints')->pluck('flashpoints.id')->all();
        $ends = array_map(fn (array $b, int $i) => [$b['key'], $buckets[$i + 1]['key'] ?? $filters->end->toDateString()], $buckets, array_keys($buckets));
        $rows = collect();

        if ($ids !== [] && $ends !== []) {
            $values = implode(', ', array_fill(0, count($ends), '(?::date, ?::date)'));
            $rows = collect(DB::select(<<<SQL
                SELECT b.bucket::text AS bucket, latest.concern_level, count(*) AS n
                FROM (VALUES {$values}) AS b(bucket, ends)
                CROSS JOIN LATERAL (
                    SELECT DISTINCT ON (a.flashpoint_id) a.flashpoint_id, a.concern_level
                    FROM flashpoint_assessments a
                    WHERE a.flashpoint_id = ANY (?::bigint[]) AND a.assessed_at < b.ends
                    ORDER BY a.flashpoint_id, a.assessed_at DESC, a.id DESC
                ) latest
                GROUP BY b.bucket, latest.concern_level
            SQL, [...array_merge(...$ends), '{'.implode(',', array_map('intval', $ids)).'}']));
        }

        $series = [];
        $table = array_map(fn (array $b) => ['bucket' => $b['label']], $buckets);
        $columns = [['key' => 'bucket', 'label' => 'Period']];

        foreach (array_reverse(ConcernLevel::cases()) as $level) {
            $data = ChartData::fill($buckets, $rows->where('concern_level', $level->value)->mapWithKeys(fn ($r) => [(string) $r->bucket => (int) $r->n])->all());
            $series[] = ['label' => $level->label(), 'data' => $data];
            $columns[] = ['key' => $level->value, 'label' => $level->label(), 'numeric' => true];
            foreach ($data as $i => $n) {
                $table[$i][$level->value] = $n;
            }
        }

        return ChartData::make('concern_over_time', 'Flashpoints by concern level', 'stacked', array_column($buckets, 'label'), $series, $columns, $table,
            'Each flashpoint counted at the concern level of its latest assessment by the end of the period shown.');
    }

    /**
     * @return array<string, mixed>
     */
    public function nearFlashpoints(User $user, AnalyticsFilters $filters): array
    {
        $flashpoints = array_map('intval', Flashpoint::query()->ownJurisdiction($user)->pluck('flashpoints.id')->all());
        $total = $filters->incidents($user)->count();
        $near = 0;

        if ($flashpoints !== []) {
            $ids = '{'.implode(',', $flashpoints).'}';
            // From each (few) flashpoint outwards on the incident location
            // GIST index, plus linked incidents; then only the viewer's
            // counted incidents in the period.
            $areaIds = DB::table('flashpoints AS f')
                ->join('incidents AS i', fn ($j) => $j->whereRaw(Flashpoint::containsSql('f', 'i.location')))
                ->whereRaw('f.id = ANY (?::bigint[])', [$ids])
                ->whereNull('f.deleted_at')
                ->select('i.id');
            $linkedIds = DB::table('flashpoint_incidents AS l')
                ->whereRaw('l.flashpoint_id = ANY (?::bigint[])', [$ids])
                ->whereNull('l.deleted_at')
                ->select('l.incident_id');
            $near = $filters->incidents($user)->whereIn('incidents.id', $areaIds->union($linkedIds))->count();
        }

        $rows = [
            ['label' => 'Inside or linked to a flashpoint', 'count' => $near],
            ['label' => 'Elsewhere', 'count' => $total - $near],
        ];

        return ChartData::make('near_flashpoints', 'Incidents at flashpoints versus elsewhere', 'bar', array_column($rows, 'label'),
            [['label' => 'Incidents', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Where'], ['key' => 'count', 'label' => 'Incidents', 'numeric' => true]], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function suggestions(User $user, AnalyticsFilters $filters): array
    {
        $base = fn () => $filters->narrow(BlackspotSuggestion::query(), 'blackspot_suggestions');
        $between = fn (Builder $q, string $column) => $q->where($column, '>=', $filters->start)->where($column, '<', $filters->end);
        $rows = [
            ['label' => 'Generated', 'count' => $between($base(), 'blackspot_suggestions.generated_at')->count()],
            ['label' => 'Accepted', 'count' => $between($base(), 'blackspot_suggestions.decided_at')->where('blackspot_suggestions.status', 'accepted')->count()],
            ['label' => 'Dismissed', 'count' => $between($base(), 'blackspot_suggestions.decided_at')->where('blackspot_suggestions.status', 'dismissed')->count()],
        ];

        return ChartData::make('suggestions', 'Blackspot suggestions', 'bar', array_column($rows, 'label'),
            [['label' => 'Suggestions', 'data' => array_column($rows, 'count')]],
            [['key' => 'label', 'label' => 'Outcome'], ['key' => 'count', 'label' => 'Suggestions', 'numeric' => true]], $rows,
            'Generated suggestions still open are replaced on each nightly run, so earlier ones are not kept.');
    }

    /**
     * Disseminations of the viewer's own threat reports and flashpoints.
     *
     * @return array<string, mixed>
     */
    public function disseminations(User $user, AnalyticsFilters $filters): array
    {
        $threats = $filters->narrow(ThreatReport::query()->ownJurisdiction($user), 'threat_reports')->select('threat_reports.id');
        $flashpoints = $filters->narrow(Flashpoint::query()->ownJurisdiction($user), 'flashpoints')->select('flashpoints.id');
        $query = fn () => RecordDissemination::query()
            ->where('disseminated_at', '>=', $filters->start)->where('disseminated_at', '<', $filters->end)
            ->where(fn (Builder $q) => $q->where(fn (Builder $t) => $t->where('subject_type', 'threat_report')->whereIn('subject_id', $threats))
                ->orWhere(fn (Builder $f) => $f->where('subject_type', 'flashpoint')->whereIn('subject_id', $flashpoints)));
        $sent = $query()->count();
        $acknowledged = $query()->whereNotNull('acknowledged_at');
        $times = $acknowledged->toBase()
            ->selectRaw('count(*) AS n')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM acknowledged_at - disseminated_at)) AS median')
            ->selectRaw('percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM acknowledged_at - disseminated_at)) AS p90')
            ->first();
        $hours = ChartData::hours($times?->median === null ? null : (float) $times->median, $times?->p90 === null ? null : (float) $times->p90);
        $rows = [
            ['label' => 'Sent', 'value' => $sent],
            ['label' => 'Acknowledged', 'value' => (int) ($times->n ?? 0)],
            ['label' => 'Revoked', 'value' => $query()->whereNotNull('revoked_at')->count()],
            ['label' => 'Median time to acknowledge (h)', 'value' => $hours['median']],
            ['label' => '90th percentile time to acknowledge (h)', 'value' => $hours['p90']],
        ];

        return ChartData::make('disseminations', 'Disseminations sent', 'stats', array_column($rows, 'label'),
            [['label' => 'Value', 'data' => array_column($rows, 'value')]],
            [['key' => 'label', 'label' => 'Measure'], ['key' => 'value', 'label' => 'Value', 'numeric' => true]], $rows);
    }
}
