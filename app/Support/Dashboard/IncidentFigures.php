<?php

namespace App\Support\Dashboard;

use App\Enums\CasualtyGroup;
use App\Enums\CasualtyOutcome;
use App\Enums\IncidentCategory;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Http\Presenters\IncidentRows;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Incidents\IncidentFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Incident figures for the dashboards (Phase 5D). Every query starts from
 * the scoped incident query (the viewer's jurisdiction and clearance) and,
 * per the statistics rule, leaves out drafts and invalid reports, except
 * the LGA chief's own drafts, which are a list of their work.
 */
final class IncidentFigures
{
    /** Open: not yet resolved, closed or invalid. */
    private const OPEN = ['submitted', 'returned', 'verified', 'under_investigation'];

    /** @return array<string, mixed> */
    public function forLga(User $user, Lga $lga): array
    {
        $mine = fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('incidents.reporting_officer_id', $user->id)->orWhere('incidents.created_by', $user->id));

        return [
            'byType30' => $this->countBy($this->since(30)->where('incidents.lga_id', $lga->id), 'type'),
            'myOpenByStatus' => $this->statusCounts($mine($this->counted())->whereIn('incidents.status', self::OPEN)),
            'returned' => IncidentRows::present($this->counted()->tap($mine)->where('incidents.status', IncidentStatus::Returned->value)
                ->with(['type', 'lga', 'state'])->latest('returned_at')->limit(10)->get()),
            'drafts' => [
                'count' => Incident::query()->where('incidents.status', IncidentStatus::Draft->value)->where('incidents.created_by', $user->id)->count(),
                'latest' => Incident::query()->where('incidents.status', IncidentStatus::Draft->value)->where('incidents.created_by', $user->id)
                    ->latest('updated_at')->limit(5)->get(['ulid', 'title', 'updated_at'])
                    ->map(fn (Incident $i) => ['ulid' => $i->ulid, 'title' => $i->title, 'updated_at' => $i->updated_at?->toIso8601String()])->values(),
            ],
            'latest' => IncidentRows::present($this->counted()->where('incidents.lga_id', $lga->id)->with(['type', 'lga', 'state'])
                ->orderByDesc('incidents.occurred_at')->limit(5)->get()),
            'channel' => 'states.'.$lga->state->ulid.'.incidents',
        ];
    }

    /** @return array<string, mixed> */
    public function forState(User $user, State $state): array
    {
        $inState = fn () => $this->counted()->where('incidents.state_id', $state->id);
        $awaiting = $inState()->where('incidents.status', IncidentStatus::Submitted->value);
        $overdue = (clone $awaiting);
        IncidentFilters::overdue($overdue);
        $overdueIds = $overdue->pluck('incidents.id')->all();

        return [
            'awaiting' => [
                'count' => (clone $awaiting)->count(),
                'overdue' => count($overdueIds),
                'rows' => array_map(function (array $row) use ($overdueIds): array {
                    $row['overdue'] = in_array($row['id'], $overdueIds, true);
                    unset($row['id']);

                    return $row;
                }, $this->withIds((clone $awaiting)->with(['type', 'lga', 'state'])->orderBy('incidents.submitted_at')->limit(15)->get())),
            ],
            'criticalHigh' => [
                'count' => $inState()->whereIn('incidents.severity', ['critical', 'high'])->whereIn('incidents.status', self::OPEN)->count(),
                'rows' => IncidentRows::present($inState()->whereIn('incidents.severity', ['critical', 'high'])->whereIn('incidents.status', self::OPEN)
                    ->with(['type', 'lga', 'state'])->orderByRaw("CASE incidents.severity WHEN 'critical' THEN 0 ELSE 1 END")->orderByDesc('incidents.occurred_at')->limit(10)->get()),
            ],
            'byLga30' => $this->countBy($this->since(30)->where('incidents.state_id', $state->id), 'lga'),
            'byType30' => $this->countBy($this->since(30)->where('incidents.state_id', $state->id), 'type'),
            'casualtiesByOutcome30' => $this->casualtiesBy($this->since(30)->where('incidents.state_id', $state->id), 'outcome'),
            'escalated' => IncidentRows::present($inState()->whereNotNull('incidents.escalated_at')->whereIn('incidents.status', self::OPEN)
                ->with(['type', 'lga', 'state'])->orderByDesc('incidents.escalated_at')->limit(10)->get()),
            'map7' => $this->markers($this->since(7)->where('incidents.state_id', $state->id)),
            'channel' => 'states.'.$state->ulid.'.incidents',
        ];
    }

    /** @return array<string, mixed> */
    public function national(User $user): array
    {
        $overdue = $this->counted();
        IncidentFilters::overdue($overdue);
        $unacknowledged = fn () => $this->counted()->whereNotNull('incidents.escalated_at')->whereNull('incidents.escalation_acknowledged_at')->whereIn('incidents.status', self::OPEN);
        $critical7 = fn () => $this->since(7)->where('incidents.severity', Severity::Critical->value);

        return [
            'escalatedUnacknowledged' => [
                'count' => $unacknowledged()->count(),
                'rows' => IncidentRows::present($unacknowledged()->with(['type', 'lga', 'state'])->orderBy('incidents.escalated_at')->limit(20)->get()),
            ],
            'critical7' => [
                'count' => $critical7()->count(),
                'rows' => IncidentRows::present($critical7()->with(['type', 'lga', 'state'])->orderByDesc('incidents.occurred_at')->limit(15)->get()),
            ],
            'byState30' => $this->countBy($this->since(30), 'state'),
            'byCategory30' => $this->countBy($this->since(30), 'category'),
            'casualtiesByOutcome30' => $this->casualtiesBy($this->since(30), 'outcome'),
            'casualtiesByGroup30' => $this->casualtiesBy($this->since(30), 'group'),
            'securityCasualtiesByAgency30' => $this->securityByAgency($this->since(30)),
            'statesOverdue' => $overdue->join('states', 'states.id', '=', 'incidents.state_id')
                ->groupBy('states.name')->orderByRaw('COUNT(*) DESC')
                ->get([DB::raw('states.name AS label'), DB::raw('COUNT(*) AS count')])
                ->map(fn (Incident $r) => ['label' => (string) $r->getAttribute('label'), 'count' => (int) $r->getAttribute('count')])->values(),
            'map7' => $this->markers($this->since(7)->whereIn('incidents.severity', ['critical', 'high'])),
            'channel' => 'national.incidents',
        ];
    }

    /**
     * Counted incidents: no drafts, no invalid reports (statistics rule).
     *
     * @return Builder<Incident>
     */
    private function counted(): Builder
    {
        return Incident::query()->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value]);
    }

    /** @return Builder<Incident> */
    private function since(int $days): Builder
    {
        return $this->counted()->where('incidents.occurred_at', '>=', CarbonImmutable::now()->subDays($days));
    }

    /**
     * @param  Builder<Incident>  $query
     * @return list<array{label: string, count: int}>
     */
    private function countBy(Builder $query, string $by): array
    {
        [$join, $column] = match ($by) {
            'type' => [['incident_types', 'incident_types.id', 'incidents.incident_type_id'], 'incident_types.name'],
            'category' => [['incident_types', 'incident_types.id', 'incidents.incident_type_id'], 'incident_types.category'],
            'lga' => [['lgas', 'lgas.id', 'incidents.lga_id'], 'lgas.name'],
            default => [['states', 'states.id', 'incidents.state_id'], 'states.name'],
        };

        $rows = $query->join($join[0], $join[1], '=', $join[2])
            ->groupBy($column)->orderByRaw('COUNT(*) DESC')
            ->get([DB::raw("{$column} AS label"), DB::raw('COUNT(*) AS count')]);

        return array_values($rows->map(fn (Incident $r) => [
            'label' => $by === 'category' ? (IncidentCategory::tryFrom((string) $r->getAttribute('label'))?->label() ?? (string) $r->getAttribute('label')) : (string) $r->getAttribute('label'),
            'count' => (int) $r->getAttribute('count'),
        ])->all());
    }

    /**
     * @param  Builder<Incident>  $query
     * @return list<array{status: array{value: string, label: string}, count: int}>
     */
    private function statusCounts(Builder $query): array
    {
        $counts = $query->groupBy('incidents.status')->get([DB::raw('incidents.status AS s'), DB::raw('COUNT(*) AS count')])
            ->mapWithKeys(fn (Incident $r) => [(string) $r->getAttribute('s') => (int) $r->getAttribute('count')]);

        return array_map(fn (string $s) => [
            'status' => ['value' => $s, 'label' => IncidentStatus::from($s)->label()],
            'count' => (int) ($counts[$s] ?? 0),
        ], self::OPEN);
    }

    /**
     * People affected, by casualty outcome or group.
     *
     * @param  Builder<Incident>  $incidents
     * @return list<array{label: string, count: int}>
     */
    private function casualtiesBy(Builder $incidents, string $by): array
    {
        $cases = $by === 'outcome'
            ? [CasualtyOutcome::Killed, CasualtyOutcome::Injured, CasualtyOutcome::Abducted, CasualtyOutcome::Rescued]
            : CasualtyGroup::cases();
        $column = $by === 'outcome' ? 'outcome' : 'group';

        $sums = DB::table('incident_casualties')
            ->whereIn('incident_id', $incidents->select('incidents.id'))
            ->whereNull('deleted_at')
            ->groupBy($column)
            ->selectRaw("\"{$column}\" AS k, SUM(adult_male + adult_female + child_male + child_female + \"unknown\") AS total")
            ->pluck('total', 'k');

        return array_map(fn (CasualtyOutcome|CasualtyGroup $c) => ['label' => $c->label(), 'count' => (int) ($sums[$c->value] ?? 0)], $cases);
    }

    /**
     * Security personnel casualties by agency.
     *
     * @param  Builder<Incident>  $incidents
     * @return list<array{label: string, count: int}>
     */
    private function securityByAgency(Builder $incidents): array
    {
        return array_values(DB::table('incident_casualties')
            ->join('security_agencies', 'security_agencies.id', '=', 'incident_casualties.security_agency_id')
            ->whereIn('incident_casualties.incident_id', $incidents->select('incidents.id'))
            ->whereNull('incident_casualties.deleted_at')
            ->where('incident_casualties.group', CasualtyGroup::SecurityPersonnel->value)
            ->groupBy('security_agencies.short_name')
            ->orderByRaw('SUM(adult_male + adult_female + child_male + child_female + "unknown") DESC')
            ->get([DB::raw('security_agencies.short_name AS label'), DB::raw('SUM(adult_male + adult_female + child_male + child_female + "unknown") AS count')])
            ->map(fn (object $r) => ['label' => (string) $r->label, 'count' => (int) $r->count])
            ->all());
    }

    /**
     * Map points: popup fields only, newest first, at most 500.
     *
     * @param  Builder<Incident>  $query
     * @return list<array<string, mixed>>
     */
    private function markers(Builder $query): array
    {
        return array_values($query->whereNotNull('incidents.location')
            ->join('incident_types', 'incident_types.id', '=', 'incidents.incident_type_id')
            ->orderByDesc('incidents.occurred_at')->limit(500)
            ->get([
                'incidents.ulid', 'incidents.reference_no', 'incidents.severity', 'incidents.status', 'incidents.occurred_at',
                'incident_types.name as type_name', 'incident_types.category as category',
                DB::raw('ST_Y(incidents.location::geometry) AS lat'), DB::raw('ST_X(incidents.location::geometry) AS lng'),
            ])
            ->map(fn (Incident $i) => [
                'ulid' => $i->ulid,
                'reference_no' => $i->reference_no,
                'type' => (string) $i->getAttribute('type_name'),
                'category' => (string) $i->getAttribute('category'),
                'severity' => $i->severity->value,
                'status' => $i->status->label(),
                'occurred_at' => $i->occurred_at->toIso8601String(),
                'lat' => round((float) $i->getAttribute('lat'), 5),
                'lng' => round((float) $i->getAttribute('lng'), 5),
            ])->all());
    }

    /**
     * List rows keeping the internal id for in-PHP matching (removed before
     * the rows leave the server).
     *
     * @param  iterable<Incident>  $incidents
     * @return list<array<string, mixed>>
     */
    private function withIds(iterable $incidents): array
    {
        $incidents = collect($incidents);
        $rows = IncidentRows::present($incidents);

        return array_map(fn (array $row, Incident $i) => [...$row, 'id' => $i->id, 'submitted_at' => $i->submitted_at?->toIso8601String()], $rows, $incidents->all());
    }
}
