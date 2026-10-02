<?php

namespace App\Services\Reports\Builders;

use App\Enums\CasualtyOutcome;
use App\Enums\ConcernLevel;
use App\Enums\IncidentStatus;
use App\Enums\Permission;
use App\Enums\ThreatUrgency;
use App\Models\CoverageReport;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\ReportRun;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Incidents\IncidentFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Daily situation report (Phase 7D) for the requester's jurisdiction (or a
 * chosen state or LGA within it), covering the last 24 hours or, for a
 * scheduled run, everything since the previous run:
 * new incidents with casualty summaries and totals; escalations and
 * acknowledgements; new or changed immediate and priority threats
 * (including those disseminated to the viewer); flashpoints assessed as
 * high or severe; disseminations received; overdue verifications (now);
 * coverage reports awaiting review (now). Sections appear only for modules
 * the requester may view. Drafts and invalid reports are never counted.
 */
final class SitrepBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $tz = (string) config('app.timezone');
        $until = CarbonImmutable::now($tz);
        $since = isset($run->parameters['since']) ? CarbonImmutable::parse((string) $run->parameters['since'])->setTimezone($tz) : $until->subDay();
        $place = Places::from($run->parameters);
        $levels = new LevelTracker;
        $data = ['since' => $since, 'until' => $until, 'place' => $place->label($user)];

        if ($user->checkPermissionTo(Permission::IncidentsView->value)) {
            $counted = fn (): Builder => $place->narrow(Incident::query()->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value]), 'incidents');
            $new = $counted()->whereBetween('incidents.submitted_at', [$since, $until])->with(['type', 'lga'])->orderBy('incidents.submitted_at')->get();
            $levels->seeAll($new->map(fn (Incident $i) => $i->classificationLevel()));
            $casualties = $this->casualties(array_values(array_map('intval', $new->pluck('id')->all())));
            $data['incidents'] = $new->map(fn (Incident $i) => [
                'reference' => $i->reference_no,
                'type' => $i->type->name,
                'lga' => $i->lga->name,
                'time' => $i->occurred_at->setTimezone($tz)->format('j M H:i'),
                'severity' => $i->severity->label(),
                'status' => $i->status->label(),
                'casualties' => $casualties['by_incident'][$i->id] ?? '—',
            ])->all();
            $data['casualtyTotals'] = $casualties['totals'];
            $escalated = $counted()->whereBetween('incidents.escalated_at', [$since, $until])->get();
            $acknowledged = $counted()->whereBetween('incidents.escalation_acknowledged_at', [$since, $until])->get();
            $levels->seeAll([...$escalated->map->classificationLevel(), ...$acknowledged->map->classificationLevel()]);
            $data['escalations'] = ['escalated' => $escalated->pluck('reference_no')->filter()->values()->all(), 'acknowledged' => $acknowledged->pluck('reference_no')->filter()->values()->all()];
            $overdue = $counted();
            IncidentFilters::overdue($overdue);
            $overdueRows = $overdue->with('lga')->orderBy('incidents.submitted_at')->limit(50)->get();
            $levels->seeAll($overdueRows->map->classificationLevel());
            $data['overdue'] = ['count' => (clone $overdue)->count(), 'rows' => $overdueRows->map(fn (Incident $i) => ['reference' => $i->reference_no, 'lga' => $i->lga->name, 'severity' => $i->severity->label(), 'submitted' => $i->submitted_at?->setTimezone($tz)->format('j M H:i')])->all()];
        }

        if ($user->checkPermissionTo(Permission::ThreatsView->value)) {
            // Through the scopes: the requester's own threats and those disseminated to them.
            $threats = $place->narrowUnlessShared(ThreatReport::query(), 'threat_reports', $user)
                ->whereIn('threat_reports.urgency', [ThreatUrgency::Immediate->value, ThreatUrgency::Priority->value])
                ->where(fn (Builder $q) => $q->whereBetween('threat_reports.created_at', [$since, $until])->orWhereBetween('threat_reports.updated_at', [$since, $until])
                    ->orWhereIn('threat_reports.id', RecordDissemination::query()->receivedBy($user)->where('subject_type', 'threat_report')->whereBetween('disseminated_at', [$since, $until])->select('subject_id')))
                ->with(['threatType', 'lga', 'state'])->byUrgency()->get();
            $levels->seeAll($threats->map->classificationLevel());
            $data['threats'] = $threats->map(fn (ThreatReport $t) => [
                'reference' => $t->reference_no, 'title' => $t->title, 'type' => $t->threatType->name, 'urgency' => $t->urgency->label(),
                'status' => $t->status->label(), 'place' => $t->lga->name.' LGA, '.$t->state->name,
                'new' => $t->created_at->greaterThanOrEqualTo($since),
            ])->all();
        }

        if ($user->checkPermissionTo(Permission::FlashpointsView->value)) {
            $raised = Flashpoint::query()->tap(fn (Builder $q) => $place->narrowUnlessShared($q, 'flashpoints', $user))
                ->whereIn('flashpoints.id', DB::table('flashpoint_assessments')->whereBetween('assessed_at', [$since, $until])
                    ->whereIn('concern_level', [ConcernLevel::High->value, ConcernLevel::Severe->value])->select('flashpoint_id'))
                ->with('lga')->byConcern()->get();
            $levels->seeAll($raised->map->classificationLevel());
            $data['flashpoints'] = $raised->map(fn (Flashpoint $f) => ['name' => $f->name, 'kind' => $f->kind->label(), 'concern' => $f->concern_level->label(), 'lga' => $f->lga->name])->all();
        }

        if ($user->checkPermissionTo(Permission::DisseminationsView->value)) {
            $received = RecordDissemination::query()->receivedBy($user)->whereBetween('disseminated_at', [$since, $until])->with('sourceState')->get();
            $rows = [];
            foreach ($received as $d) {
                $subject = RecordDissemination::subjectClass($d->subject_type)::query()->whereKey($d->subject_id)->first();
                if ($subject !== null) {
                    $levels->see($subject->classificationLevel());
                    $rows[] = ['kind' => $subject->disseminationKind(), 'label' => $subject->disseminationLabel(), 'from' => $d->sourceState->name.' State', 'at' => $d->disseminated_at->setTimezone($tz)->format('j M H:i'), 'acknowledged' => $d->acknowledged_at !== null];
                }
            }
            $data['disseminations'] = $rows;
        }

        if ($user->checkPermissionTo(Permission::CoverageView->value)) {
            $awaiting = $place->narrow(CoverageReport::query(), 'coverage_reports')->where('coverage_reports.status', 'submitted')->with('lga')->orderBy('coverage_reports.submitted_at')->get();
            $levels->seeAll($awaiting->map->classificationLevel());
            $data['coverage'] = $awaiting->map(fn (CoverageReport $r) => ['lga' => $r->lga->name, 'period' => $r->period_start->format('j M Y').' – '.$r->period_end->format('j M Y'), 'rating' => $r->overall_rating?->label()])->all();
        }

        return $this->pdf->render('reports.sitrep', $data, 'Daily situation report', $run, $user, $levels);
    }

    /**
     * @param  list<int>  $ids
     * @return array{by_incident: array<int, string>, totals: array<string, int>}
     */
    private function casualties(array $ids): array
    {
        $rows = $ids === [] ? collect() : DB::table('incident_casualties')->whereIn('incident_id', $ids)->whereNull('deleted_at')
            ->selectRaw('incident_id, outcome, sum(adult_male + adult_female + child_male + child_female + "unknown") AS people')
            ->groupBy('incident_id', 'outcome')->get();
        $byIncident = [];
        foreach ($rows->groupBy('incident_id') as $incident => $outcomes) {
            $byIncident[(int) $incident] = $outcomes->map(fn ($o) => CasualtyOutcome::from($o->outcome)->label().' '.(int) $o->people)->implode(', ');
        }
        $totals = [];
        foreach (CasualtyOutcome::cases() as $outcome) {
            $totals[$outcome->label()] = (int) $rows->where('outcome', $outcome->value)->sum('people');
        }

        return ['by_incident' => $byIncident, 'totals' => $totals];
    }
}
