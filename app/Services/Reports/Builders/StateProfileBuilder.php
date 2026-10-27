<?php

namespace App\Services\Reports\Builders;

use App\Enums\FlashpointStatus;
use App\Enums\FormationStatus;
use App\Http\Presenters\StatePresenter;
use App\Models\AgencyFormation;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\PoliticalOffice;
use App\Models\ReportRun;
use App\Models\State;
use App\Models\StateProfile;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Incidents\IncidentLinks;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\ProfileCompleteness;
use App\Support\LocalGovernment\StateFacts;
use App\Support\Political\Leadership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A state profile as a PDF (Phase 11F), mirroring the LGA profile (7D):
 * the profile and computed facts, an LGA summary, the senatorial
 * districts, current public office holders (no party officials), a
 * security presence summary (formation counts by agency, never names or
 * commanders), active flashpoints by concern level and a 90-day incident
 * summary. No persons of interest, criminal group members or other
 * persons: the only person names are current public office holders
 * (CLAUDE.md). Each section is read through its own scopes and appears only
 * for modules the requester may view; the operational sections need
 * whole-state jurisdiction, as on the page (never for an LGA chief).
 */
final class StateProfileBuilder implements ReportBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly Leadership $leadership,
        private readonly JurisdictionOptions $options,
    ) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $state = State::query()->where('ulid', (string) $run->parameters['state'])->first();

        if ($state === null || ! $user->can('view', $state)) {
            throw new AuthorizationException('The state is no longer within the requester\'s reach.');
        }

        $state->loadMissing('geopoliticalZone');
        $operational = $user->can('viewOperational', $state);
        $levels = new LevelTracker;
        $facts = StateFacts::for($state);

        $lgas = $this->options->lgaQuery($user)->active()->where('lgas.state_id', $state->id)->with('senatorialDistrict')->orderBy('lgas.name')->get();
        $completeness = ProfileCompleteness::forLgas($lgas);

        $data = [
            'facts' => [...StatePresenter::facts($state), ...($operational ? $facts : ['lgas' => $facts['lgas'], 'districts' => $facts['districts']])],
            'operational' => $operational,
            'profile' => StatePresenter::profile(StateProfile::query()->with('reviewer')->firstWhere('state_id', $state->id)),
            'lgas' => $lgas->map(fn ($l) => [
                'name' => $l->name,
                'district' => $l->senatorialDistrict->name ?? null,
                'headquarters' => $l->headquarters,
                'completeness' => $completeness->get($l->id)['completeness'] ?? 0,
            ])->all(),
            'districts' => $this->leadership->stateDistricts($state, $user->can('viewAny', PoliticalOffice::class)),
        ];

        if ($user->can('viewAny', PoliticalOffice::class)) {
            $data['offices'] = collect($this->leadership->forState($state, publicOnly: true)['groups'])
                ->flatMap(fn (array $group) => array_map(fn (array $o) => [
                    'office' => $o['title'],
                    'place' => $o['senatorial_district']['name'] ?? $o['constituency_name'] ?? null,
                    'name' => $o['person']['name'] ?? 'Not recorded',
                    'party' => $o['party'],
                    'since' => CarbonImmutable::parse($o['started_on'])->format('j M Y'),
                ], $group['holders']))
                ->values()->all();
        }

        if ($operational && $user->can('viewAny', AgencyFormation::class)) {
            $formations = AgencyFormation::query()->with('agency')->where('agency_formations.state_id', $state->id)
                ->where('agency_formations.status', '<>', FormationStatus::Closed->value)->get();
            $levels->seeAll($formations->map->classificationLevel());
            $data['formations'] = $formations->groupBy('agency_id')
                ->map(fn ($group) => ['agency' => $group->first()->agency->short_name ?: $group->first()->agency->name, 'count' => $group->count()])
                ->sortBy('agency')->values()->all();
        }

        if ($operational && $user->can('viewAny', Flashpoint::class)) {
            $flashpoints = Flashpoint::query()->with('lga')->where('flashpoints.state_id', $state->id)
                ->where('flashpoints.status', '<>', FlashpointStatus::Resolved->value)->byConcern()->get();
            $levels->seeAll($flashpoints->map->classificationLevel());
            $data['flashpoints'] = $flashpoints->map(fn (Flashpoint $f) => [
                'name' => $f->name, 'lga' => $f->lga->name, 'kind' => $f->kind->label(), 'concern' => $f->concern_level->label(), 'status' => $f->status->label(),
            ])->all();
        }

        if ($operational && $user->can('viewAny', Incident::class)) {
            $levels->seeAll(IncidentLinks::state($state)->where('incidents.occurred_at', '>=', now()->subDays(90))->toBase()->distinct()->pluck('incidents.classification'));
            $data['incidents'] = IncidentLinks::stateSummary($state);
        }

        return $this->pdf->render('reports.state-profile', $data, $state->name.' State profile', $run, $user, $levels);
    }
}
