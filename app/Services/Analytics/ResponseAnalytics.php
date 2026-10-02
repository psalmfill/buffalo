<?php

namespace App\Services\Analytics;

use App\Enums\OccurrencePrecision;
use App\Enums\Permission;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;

/**
 * Response (Phase 7C), for the viewer's counted incidents that occurred in
 * the period:
 * - reporting delay (submitted − occurred; date-only incidents excluded);
 * - verification time (verified − submitted) by severity, against the
 *   configured deadlines (buffalo.incidents.verify_within_minutes);
 * - time to resolve and to close (from submission);
 * - by state or LGA, one level below the selection;
 * - escalation: escalated, acknowledged, time to national acknowledgement.
 *
 * Medians and 90th percentiles use percentile_cont over seconds; shown in
 * hours.
 */
final class ResponseAnalytics implements AnalyticsArea
{
    public function __construct(private readonly JurisdictionOptions $options) {}

    public function key(): string
    {
        return 'response';
    }

    public function label(): string
    {
        return 'Response';
    }

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo(Permission::IncidentsView->value);
    }

    public function charts(User $user, AnalyticsFilters $filters): array
    {
        return [
            $this->summary($user, $filters),
            $this->verificationBySeverity($user, $filters),
            $this->byPlace($user, $filters),
            $this->escalation($user, $filters),
        ];
    }

    /**
     * Reporting delay, verification, resolution and closure: count, median
     * and 90th percentile.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, AnalyticsFilters $filters): array
    {
        $rows = [
            ['label' => 'Reporting delay (occurred → submitted)', ...$this->stats($filters->incidents($user)
                ->whereNotNull('incidents.submitted_at')
                ->where('incidents.occurred_at_precision', '!=', OccurrencePrecision::DateOnly->value), 'incidents.submitted_at', 'incidents.occurred_at')],
            ['label' => 'Verification (submitted → verified)', ...$this->stats($filters->incidents($user)->whereNotNull('incidents.verified_at'), 'incidents.verified_at', 'incidents.submitted_at')],
            ['label' => 'Resolution (submitted → resolved)', ...$this->stats($filters->incidents($user)->whereNotNull('incidents.resolved_at'), 'incidents.resolved_at', 'incidents.submitted_at')],
            ['label' => 'Closure (submitted → closed)', ...$this->stats($filters->incidents($user)->whereNotNull('incidents.closed_at'), 'incidents.closed_at', 'incidents.submitted_at')],
        ];
        $excluded = $filters->incidents($user)->where('incidents.occurred_at_precision', OccurrencePrecision::DateOnly->value)->count();

        return ChartData::make('times', 'Response times (hours)', 'bar', array_column($rows, 'label'), [
            ['label' => 'Median', 'data' => array_column($rows, 'median')],
            ['label' => '90th percentile', 'data' => array_column($rows, 'p90')],
        ], [
            ['key' => 'label', 'label' => 'Measure'],
            ['key' => 'count', 'label' => 'Incidents', 'numeric' => true],
            ['key' => 'median', 'label' => 'Median (h)', 'numeric' => true],
            ['key' => 'p90', 'label' => '90th percentile (h)', 'numeric' => true],
        ], $rows, "Reporting delay excludes {$excluded} incidents recorded with a date only.");
    }

    /**
     * @return array<string, mixed>
     */
    public function verificationBySeverity(User $user, AnalyticsFilters $filters): array
    {
        $rows = [];

        foreach (array_reverse(Severity::cases()) as $severity) {
            $query = $filters->incidents($user)->whereNotNull('incidents.verified_at')->where('incidents.severity', $severity->value);
            $minutes = $this->deadline($severity);
            $within = (clone $query)->whereRaw("incidents.verified_at - incidents.submitted_at <= ? * interval '1 minute'", [$minutes])->count();
            $stats = $this->stats($query, 'incidents.verified_at', 'incidents.submitted_at');
            $rows[] = [
                'label' => $severity->label(),
                'deadline' => $this->deadlineLabel($minutes),
                ...$stats,
                'within' => $within,
                'within_pct' => $stats['count'] > 0 ? round($within / $stats['count'] * 100, 1) : null,
            ];
        }

        return ChartData::make('verification', 'Verification against deadlines', 'bar', array_column($rows, 'label'), [
            ['label' => 'Within deadline (%)', 'data' => array_column($rows, 'within_pct')],
        ], [
            ['key' => 'label', 'label' => 'Severity'],
            ['key' => 'deadline', 'label' => 'Deadline'],
            ['key' => 'count', 'label' => 'Verified', 'numeric' => true],
            ['key' => 'median', 'label' => 'Median (h)', 'numeric' => true],
            ['key' => 'p90', 'label' => '90th percentile (h)', 'numeric' => true],
            ['key' => 'within', 'label' => 'Within deadline', 'numeric' => true],
            ['key' => 'within_pct', 'label' => 'Within deadline %', 'numeric' => true],
        ], $rows);
    }

    /**
     * One level below the selection: verification within deadline,
     * medians, reporting delay.
     *
     * @return array<string, mixed>
     */
    public function byPlace(User $user, AnalyticsFilters $filters): array
    {
        $level = $filters->breakdown($user);
        $column = $level === 'state' ? 'incidents.state_id' : 'incidents.lga_id';
        [$critical, $high, $medium, $low] = array_map(fn (Severity $s) => $this->deadline($s), [Severity::Critical, Severity::High, Severity::Medium, Severity::Low]);

        $verification = $filters->incidents($user)->whereNotNull('incidents.verified_at')->toBase()
            ->select($column.' AS place')
            ->selectRaw('count(*) AS verified')
            ->selectRaw("count(*) FILTER (WHERE incidents.verified_at - incidents.submitted_at <= (CASE incidents.severity WHEN 'critical' THEN ?::int WHEN 'high' THEN ?::int WHEN 'medium' THEN ?::int ELSE ?::int END) * interval '1 minute') AS within", [$critical, $high, $medium, $low])
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM incidents.verified_at - incidents.submitted_at)) AS median')
            ->groupBy('place')->get()->keyBy('place');
        $delay = $filters->incidents($user)->whereNotNull('incidents.submitted_at')
            ->where('incidents.occurred_at_precision', '!=', OccurrencePrecision::DateOnly->value)->toBase()
            ->select($column.' AS place')
            ->selectRaw('percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM incidents.submitted_at - incidents.occurred_at)) AS median')
            ->groupBy('place')->pluck('median', 'place');
        $total = $filters->incidents($user)->toBase()->select($column.' AS place')->selectRaw('count(*) AS n')->groupBy('place')->pluck('n', 'place');

        $places = $level === 'state'
            ? $this->options->states($user)->when($filters->zone !== null, fn ($c) => $c->where('geopolitical_zone_id', $filters->zone?->id))->sortBy('name')->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values()
            : $this->options->lgaQuery($user)->active()
                ->when($filters->lga !== null, fn (Builder $q) => $q->whereKey($filters->lga?->id))
                ->when($filters->lga === null && $filters->state !== null, fn (Builder $q) => $q->where('lgas.state_id', $filters->state?->id))
                ->orderBy('lgas.name')->get(['lgas.id', 'lgas.name'])->map(fn ($l) => ['id' => $l->id, 'name' => $l->name])->values();
        $rows = [];

        foreach ($places as $place) {
            $v = $verification->get($place['id']);
            $verified = $v === null ? 0 : (int) $v->verified;
            $rows[] = [
                'label' => $place['name'],
                'incidents' => (int) ($total->get($place['id']) ?? 0),
                'verified' => $verified,
                'within_pct' => $verified > 0 ? round((int) $v->within / $verified * 100, 1) : null,
                'median_verification' => $v === null || $v->median === null ? null : round((float) $v->median / 3600, 1),
                'median_delay' => $delay->get($place['id']) === null ? null : round((float) $delay->get($place['id']) / 3600, 1),
            ];
        }

        return ChartData::make('by_place', $level === 'state' ? 'Performance by state' : 'Performance by LGA', 'table', array_column($rows, 'label'),
            [['label' => 'Within deadline (%)', 'data' => array_column($rows, 'within_pct')]], [
                ['key' => 'label', 'label' => $level === 'state' ? 'State' : 'LGA'],
                ['key' => 'incidents', 'label' => 'Incidents', 'numeric' => true],
                ['key' => 'verified', 'label' => 'Verified', 'numeric' => true],
                ['key' => 'within_pct', 'label' => 'Verified within deadline %', 'numeric' => true],
                ['key' => 'median_verification', 'label' => 'Median verification (h)', 'numeric' => true],
                ['key' => 'median_delay', 'label' => 'Median reporting delay (h)', 'numeric' => true],
            ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function escalation(User $user, AnalyticsFilters $filters): array
    {
        $escalated = $filters->incidents($user)->whereNotNull('incidents.escalated_at');
        $acknowledged = (clone $escalated)->whereNotNull('incidents.escalation_acknowledged_at');
        $stats = $this->stats($acknowledged, 'incidents.escalation_acknowledged_at', 'incidents.escalated_at');
        $count = $escalated->count();
        $rows = [
            ['label' => 'Escalated to national', 'value' => $count],
            ['label' => 'Acknowledged', 'value' => $stats['count']],
            ['label' => 'Not yet acknowledged', 'value' => $count - $stats['count']],
            ['label' => 'Median time to acknowledgement (h)', 'value' => $stats['median']],
            ['label' => '90th percentile time to acknowledgement (h)', 'value' => $stats['p90']],
        ];

        return ChartData::make('escalation', 'Escalation', 'stats', array_column($rows, 'label'),
            [['label' => 'Value', 'data' => array_column($rows, 'value')]],
            [['key' => 'label', 'label' => 'Measure'], ['key' => 'value', 'label' => 'Value', 'numeric' => true]], $rows);
    }

    /**
     * Count, median and 90th percentile (hours) of `$to − $from`.
     *
     * @param  Builder<Incident>  $query
     * @param  literal-string  $to
     * @param  literal-string  $from
     * @return array{count: int, median: float|null, p90: float|null}
     */
    public function stats(Builder $query, string $to, string $from): array
    {
        // Column names come from code only.
        $row = $query->toBase()
            ->selectRaw('count(*) AS n')
            ->selectRaw("percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM {$to} - {$from})) AS median")
            ->selectRaw("percentile_cont(0.9) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM {$to} - {$from})) AS p90")
            ->first();

        return [
            'count' => (int) ($row->n ?? 0),
            ...ChartData::hours($row?->median === null ? null : (float) $row->median, $row?->p90 === null ? null : (float) $row->p90),
        ];
    }

    private function deadline(Severity $severity): int
    {
        return (int) (config('buffalo.incidents.verify_within_minutes')[$severity->value] ?? 1440);
    }

    private function deadlineLabel(int $minutes): string
    {
        return $minutes % 60 === 0 ? ($minutes / 60).' h' : $minutes.' min';
    }
}
