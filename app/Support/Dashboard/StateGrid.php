<?php

namespace App\Support\Dashboard;

use App\Enums\CasualtyOutcome;
use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Incident;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ChartData;
use App\Support\Incidents\IncidentFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The national dashboard's state grid (Phase 11A.2): one card per state
 * within the viewer's reach, with the figures that call for attention.
 *
 * Every figure is the same query a state's own dashboard runs (the KPI
 * tiles' AnalyticsFilters, the incident figures' counted and overdue
 * rules), grouped by state, so a card always equals what opening that
 * state shows. All through the viewer's scopes; the statistics rule
 * applies (no drafts, no invalid incidents).
 */
final class StateGrid
{
    private const OPEN = ['submitted', 'returned', 'verified', 'under_investigation'];

    public function __construct(private readonly CoverageFigures $coverage) {}

    /**
     * @param  Collection<int, State>  $states  the states within reach
     * @return list<array<string, mixed>> most urgent first
     */
    public function for(User $user, Collection $states): array
    {
        $states->loadMissing('geopoliticalZone');
        $ids = array_values(array_map('intval', $states->modelKeys()));
        $incidents = $user->checkPermissionTo(Permission::IncidentsView->value);

        $count = fn (Builder $query): array => $query->whereIn('incidents.state_id', $ids)->toBase()
            ->selectRaw('incidents.state_id AS state, count(*) AS total')->groupBy('incidents.state_id')
            ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();
        $period = fn (int $days, bool $previous) => $count(AnalyticsFilters::from(['period' => "{$days}d", 'compare' => 'previous'], $user)->incidents($user, comparison: $previous));
        $counted = fn () => Incident::query()->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value]);

        $figures = $incidents ? [
            'incidents7' => $period(7, false),
            'incidents7Previous' => $period(7, true),
            'incidents30' => $period(30, false),
            'incidents30Previous' => $period(30, true),
            'criticalOpen' => $count($counted()->where('incidents.severity', 'critical')->whereIn('incidents.status', self::OPEN)),
            'escalationsUnacknowledged' => $count($counted()->whereNotNull('incidents.escalated_at')->whereNull('incidents.escalation_acknowledged_at')->whereIn('incidents.status', self::OPEN)),
            'overdueVerifications' => $count(tap($counted(), fn (Builder $q) => IncidentFilters::overdue($q))),
            'killed30' => $this->people($user, CasualtyOutcome::Killed, $ids),
            'abducted30' => $this->people($user, CasualtyOutcome::Abducted, $ids),
        ] : [];

        $threats = $user->checkPermissionTo(Permission::ThreatsView->value)
            ? ThreatReport::query()->ownJurisdiction($user)
                ->where('threat_reports.status', ThreatStatus::Active->value)
                ->where('threat_reports.urgency', ThreatUrgency::Immediate->value)
                ->whereIn('threat_reports.state_id', $ids)
                ->toBase()->selectRaw('threat_reports.state_id AS state, count(*) AS total')->groupBy('threat_reports.state_id')
                ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all()
            : null;

        /** @var list<array{state: array{ulid: string}, percent: int}> $rows */
        $rows = $this->coverage->national($user, $states)['compliance'] ?? [];
        $compliance = collect($rows)->keyBy('state.ulid');
        $weights = (array) config('buffalo.dashboard.attention_weights');

        $cards = $states->map(function (State $state) use ($figures, $threats, $compliance, $weights, $incidents): array {
            $get = fn (string $key): int => (int) ($figures[$key][$state->id] ?? 0);
            $values = [
                'incidents7' => $incidents ? $get('incidents7') : null,
                'incidents7Change' => $incidents ? ChartData::percent($get('incidents7'), $get('incidents7Previous')) : null,
                'incidents30' => $incidents ? $get('incidents30') : null,
                'incidents30Change' => $incidents ? ChartData::percent($get('incidents30'), $get('incidents30Previous')) : null,
                'criticalOpen' => $incidents ? $get('criticalOpen') : null,
                'escalationsUnacknowledged' => $incidents ? $get('escalationsUnacknowledged') : null,
                'immediateThreats' => $threats === null ? null : (int) ($threats[$state->id] ?? 0),
                'overdueVerifications' => $incidents ? $get('overdueVerifications') : null,
                'killed30' => $incidents ? $get('killed30') : null,
                'abducted30' => $incidents ? $get('abducted30') : null,
                'coverageCompliance' => $compliance->get($state->ulid)['percent'] ?? null,
            ];
            $score = self::score($values, $weights);

            return [
                'state' => ['ulid' => $state->ulid, 'name' => $state->name, 'code' => $state->code],
                'zone' => $state->geopoliticalZone === null ? null : ['ulid' => $state->geopoliticalZone->ulid, 'name' => $state->geopoliticalZone->name],
                ...$values,
                'attention' => ['score' => $score, 'level' => self::level($score)],
            ];
        });

        return array_values($cards->sortBy([['attention.score', 'desc'], ['state.name', 'asc']])->all());
    }

    /**
     * The attention score: a weighted sum (weights in
     * buffalo.dashboard.attention_weights).
     *
     * @param  array<string, int|float|null>  $values
     * @param  array<string, int|float>  $weights
     */
    public static function score(array $values, array $weights): float
    {
        $score = 0.0;

        foreach ($weights as $key => $weight) {
            $score += (float) ($values[$key] ?? 0) * (float) $weight;
        }

        return round($score, 1);
    }

    /** none, elevated, high or critical (thresholds in buffalo.dashboard.attention_levels). */
    public static function level(float $score): string
    {
        foreach ((array) config('buffalo.dashboard.attention_levels') as $level => $threshold) {
            if ($score >= (float) $threshold) {
                return (string) $level;
            }
        }

        return 'none';
    }

    /**
     * People killed or abducted in the last 30 days (the KPI tiles' rule),
     * per state.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function people(User $user, CasualtyOutcome $outcome, array $ids): array
    {
        $incidents = AnalyticsFilters::from(['period' => '30d', 'compare' => 'previous'], $user)->incidents($user)
            ->whereIn('incidents.state_id', $ids)->select(['incidents.id', 'incidents.state_id']);

        return DB::table('incident_casualties AS c')
            ->joinSub($incidents->toBase(), 'i', 'i.id', '=', 'c.incident_id')
            ->whereNull('c.deleted_at')
            ->where('c.outcome', $outcome->value)
            ->groupBy('i.state_id')
            ->selectRaw('i.state_id AS state, sum(c.adult_male + c.adult_female + c.child_male + c.child_female + c.unknown) AS total')
            ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();
    }
}
