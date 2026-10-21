<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\CasualtyOutcome;
use App\Enums\IncidentStatus;
use App\Enums\PersonInvolvement;
use App\Models\Incident;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Incidents: the Phase 7D incident list export, now in the registry
 * (Phase 11E). Any IncidentFilters selection through the requester's
 * scopes; drafts and invalid reports are never exported (unlike the page,
 * which shows the user's own drafts).
 *
 * No person names (persons are counted by involvement only), no evidence
 * and no free-text descriptions, locations or notes: free text can name
 * persons, so the bulk file leaves it out.
 */
final class IncidentsExporter extends ListExporter
{
    /** @var array<int, array<string, int>> */
    private array $casualties = [];

    /** @var array<int, array<string, int>> */
    private array $persons = [];

    /** @var array<int, string> */
    private array $agencies = [];

    /** @var Collection<int, string> */
    private Collection $officers;

    public function __construct()
    {
        $this->officers = collect();
    }

    public function key(): string
    {
        return 'incidents';
    }

    public function label(): string
    {
        return 'Incident list';
    }

    public function page(): string
    {
        return 'incidents.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Incident::class);
    }

    /** IncidentFilters validates and normalises its own values. */
    public function rules(User $user): array
    {
        return [];
    }

    public function filters(array $input, User $user): array
    {
        return array_filter(IncidentFilters::from($input)->values, fn (mixed $v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Incident>
     */
    public function query(User $user, array $filters): Builder
    {
        return IncidentFilters::from($filters)->apply(Incident::query(), $user)
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->select('incidents.*');
    }

    public function classificationColumn(): string
    {
        return 'incidents.classification';
    }

    public function with(): array
    {
        return ['type', 'state', 'lga', 'community'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $ids = $rows->pluck('id')->all();

        $this->casualties = DB::table('incident_casualties')->whereIn('incident_id', $ids)->whereNull('deleted_at')
            ->selectRaw('incident_id, outcome, sum(adult_male + adult_female + child_male + child_female + "unknown") AS people')
            ->groupBy('incident_id', 'outcome')->get()
            ->groupBy('incident_id')->map(fn (Collection $g) => $g->pluck('people', 'outcome')->map(fn (mixed $n) => (int) $n)->all())->all();
        $this->persons = DB::table('incident_persons')->whereIn('incident_id', $ids)->whereNull('deleted_at')
            ->selectRaw('incident_id, involvement, count(*) AS people')
            ->groupBy('incident_id', 'involvement')->get()
            ->groupBy('incident_id')->map(fn (Collection $g) => $g->pluck('people', 'involvement')->map(fn (mixed $n) => (int) $n)->all())->all();
        $this->agencies = DB::table('incident_agencies')->join('security_agencies', 'security_agencies.id', '=', 'incident_agencies.security_agency_id')
            ->whereIn('incident_agencies.incident_id', $ids)->whereNull('incident_agencies.deleted_at')
            ->orderBy('security_agencies.name')->get(['incident_agencies.incident_id', 'security_agencies.short_name', 'security_agencies.name'])
            ->groupBy('incident_id')
            ->map(fn (Collection $g) => $g->map(fn (\stdClass $a): string => (string) ($a->short_name ?: $a->name))->unique()->implode(', '))->all();
        $this->officers = OfficerNames::for($rows->pluck('reporting_officer_id'));
    }

    public function columns(): array
    {
        return [
            ListColumn::make('reference', 'Reference', fn (Incident $i) => $i->reference_no),
            ListColumn::make('type', 'Type', fn (Incident $i) => $i->type->name),
            ListColumn::make('category', 'Category', fn (Incident $i) => $i->type->category->label()),
            ListColumn::make('severity', 'Severity', fn (Incident $i) => $i->severity->label()),
            ListColumn::make('status', 'Status', fn (Incident $i) => $i->status->label()),
            ListColumn::make('occurred_at', 'Occurred at', fn (Incident $i) => self::at($i->occurred_at)),
            ListColumn::make('precision', 'Time precision', fn (Incident $i) => $i->occurred_at_precision->label()),
            ListColumn::make('state', 'State', fn (Incident $i) => $i->state->name),
            ListColumn::make('lga', 'LGA', fn (Incident $i) => $i->lga->name),
            ListColumn::make('community', 'Community', fn (Incident $i) => $i->community?->name),
            ListColumn::make('title', 'Title', fn (Incident $i) => $i->title),
            ...array_map(fn (CasualtyOutcome $o) => ListColumn::make('casualties_'.$o->value, 'Casualties: '.$o->label(), fn (Incident $i) => $this->casualties[$i->id][$o->value] ?? 0), CasualtyOutcome::cases()),
            ...array_map(fn (PersonInvolvement $p) => ListColumn::make('persons_'.$p->value, 'Persons: '.$p->label(), fn (Incident $i) => $this->persons[$i->id][$p->value] ?? 0), PersonInvolvement::cases()),
            ListColumn::make('agencies', 'Agencies involved', fn (Incident $i) => $this->agencies[$i->id] ?? ''),
            ListColumn::make('escalation', 'Escalation', fn (Incident $i) => $i->escalated_at === null ? 'No' : ($i->escalation_acknowledged_at === null ? 'Escalated' : 'Escalated, acknowledged')),
            ListColumn::make('reporting_officer', 'Reporting officer', fn (Incident $i) => $this->officers->get($i->reporting_officer_id)),
            ListColumn::make('submitted_at', 'Submitted', fn (Incident $i) => self::at($i->submitted_at)),
            ListColumn::make('returned_at', 'Returned', fn (Incident $i) => self::at($i->returned_at)),
            ListColumn::make('verified_at', 'Verified', fn (Incident $i) => self::at($i->verified_at)),
            ListColumn::make('investigation_started_at', 'Investigation started', fn (Incident $i) => self::at($i->investigation_started_at)),
            ListColumn::make('resolved_at', 'Resolved', fn (Incident $i) => self::at($i->resolved_at)),
            ListColumn::make('closed_at', 'Closed', fn (Incident $i) => self::at($i->closed_at)),
            ListColumn::make('escalated_at', 'Escalated', fn (Incident $i) => self::at($i->escalated_at)),
            ListColumn::make('escalation_acknowledged_at', 'Escalation acknowledged', fn (Incident $i) => self::at($i->escalation_acknowledged_at)),
            ListColumn::make('classification', 'Classification', fn (Incident $i) => $i->classificationLevel()->label()),
        ];
    }

    /** Every column of the 7D export (the page shows a subset; the export kept them all). */
    public function defaultColumns(): array
    {
        return array_values(array_filter(array_map(fn (ListColumn $c) => $c->key, $this->columns()), fn (string $k) => $k !== 'classification'));
    }
}
