<?php

namespace App\Http\Controllers\LocalGovernment;

use App\Actions\LocalGovernment\MarkStateProfileReviewed;
use App\Actions\LocalGovernment\UpdateStateProfile;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SecurityRelevance;
use App\Enums\ThreatUrgency;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\AgencyFormationPresenter;
use App\Http\Presenters\FlashpointPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Presenters\OrganizationPresenter;
use App\Http\Presenters\StatePresenter;
use App\Http\Presenters\ThreatReportPresenter;
use App\Http\Requests\LocalGovernment\StateProfileRequest;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\GeopoliticalZone;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\OrganizationSite;
use App\Models\PoliticalOffice;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\StateProfile;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Analytics\AnalyticsFilters;
use App\Support\Dashboard\MapView;
use App\Support\Exports\Lists\Exporters\StatesExporter;
use App\Support\Incidents\IncidentLinks;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\ProfileCompleteness;
use App\Support\LocalGovernment\StateFacts;
use App\Support\LocalGovernment\StateListFigures;
use App\Support\LocalGovernment\StateProfileCompleteness;
use App\Support\Political\Leadership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * State pages (Phase 11F): the list of states within reach and each
 * state's reference profile, mirroring the LGA pages. The state dashboard
 * (Phase 11A) stays the operational view; each links to the other.
 *
 * States are reference data (not scoped): StatePolicy::view keeps a page to
 * the states within reach (any other is 404). An LGA chief opens their own
 * state with the reference tabs only (overview, profile read-only, their
 * own LGA, districts, public office holders). Every tab's content comes
 * through the viewer's own scopes and clearance; tabs a viewer cannot use
 * are left out, not shown empty.
 */
class StateController extends Controller
{
    /** Tabs of a state page, in order. */
    public const TABS = ['overview', 'profile', 'lgas', 'districts', 'leadership', 'security_presence', 'organizations', 'incidents', 'history'];

    /** The tabs an LGA chief may open (the reference profile). */
    private const REFERENCE_TABS = ['overview', 'profile', 'lgas', 'districts', 'leadership'];

    /** Formations of these types lead each agency's group ("commands first"). */
    private const COMMAND_ORDER = [
        FormationType::Headquarters->value => 0,
        FormationType::Command->value => 1,
        FormationType::AreaCommand->value => 2,
        FormationType::Division->value => 3,
    ];

    public function index(Request $request, StatesExporter $exporter, StateListFigures $listFigures, JurisdictionOptions $options): Response|RedirectResponse
    {
        $this->authorize('viewAny', State::class);
        $user = $this->actor($request);

        // State-level users and LGA chiefs have one state: go straight to it.
        if ($user->jurisdiction_level !== JurisdictionLevel::National) {
            $state = $options->states($user)->first();

            if ($state !== null) {
                return redirect()->route('states.show', $state);
            }
        }

        $filters = $exporter->filters($request->query(), $user);

        // The same query as the export (Phase 11E pattern).
        $states = $exporter->query($user, $filters)->with('geopoliticalZone')->get();
        $figures = $listFigures->for($user, $states);
        $facts = StateFacts::forStates(array_values(array_map('intval', $states->modelKeys())));
        $reach = $options->states($user);

        return Inertia::render('states/index', [
            'states' => $states->map(fn (State $s) => StatePresenter::row($s, $figures[$s->id], $facts->get($s->id) ?? ['lgas' => 0]))->values(),
            'filters' => [
                'zone' => $filters['zone'] ?? '',
                'state' => $filters['state'] ?? '',
                'search' => $filters['search'] ?? '',
            ],
            'zones' => GeopoliticalZone::query()->whereIn('id', $reach->pluck('geopolitical_zone_id')->unique()->all())->orderBy('name')->get(['ulid', 'name'])
                ->map(fn (GeopoliticalZone $z) => ['ulid' => $z->ulid, 'name' => $z->name]),
            'stateOptions' => $reach->map(fn (State $s) => ['ulid' => $s->ulid, 'name' => $s->name])->values(),
            'can' => [
                'viewIncidents' => $user->checkPermissionTo(Permission::IncidentsView->value),
                'viewThreats' => $user->checkPermissionTo(Permission::ThreatsView->value),
            ],
        ]);
    }

    public function show(Request $request, State $state, Leadership $leadership): Response
    {
        $this->authorize('view', $state);
        $user = $this->actor($request);
        $state->loadMissing('geopoliticalZone');

        $operational = $user->can('viewOperational', $state);
        $tabs = $this->tabs($user, $operational);
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : 'overview';

        $profile = StateProfile::query()->with('reviewer')->firstWhere('state_id', $state->id);

        return Inertia::render('states/show', [
            'state' => StatePresenter::facts($state),
            'tab' => $tab,
            'tabs' => $tabs,
            'completeness' => StateProfileCompleteness::summary($profile, StateProfileCompleteness::reviewedLgaProfiles($state)),
            'lastReviewed' => [
                'at' => $profile?->last_reviewed_at?->toIso8601String(),
                'by' => $profile?->reviewer?->name,
            ],
            'can' => [
                'updateProfile' => $user->can('updateProfile', $state),
                'operational' => $operational,
            ],
            'overview' => $tab === 'overview' ? $this->overview($user, $state, $operational, $leadership) : null,
            'profile' => $tab === 'profile' ? [
                ...StatePresenter::profile($profile),
                'terrainOptions' => LgaPresenter::terrainOptions(),
            ] : null,
            'lgas' => $tab === 'lgas' ? $this->lgas($user, $state) : null,
            'districts' => $tab === 'districts' ? $leadership->stateDistricts($state, $user->can('viewAny', PoliticalOffice::class)) : null,
            // LGA chiefs: public office holders only (no party officials).
            'leadership' => $tab === 'leadership' ? $leadership->forState($state, publicOnly: ! $operational) : null,
            'securityPresence' => $tab === 'security_presence' ? $this->securityPresence($user, $state) : null,
            'organizations' => $tab === 'organizations' ? $this->organizations($state) : null,
            'incidents' => $tab === 'incidents' ? IncidentLinks::stateSummary($state) : null,
            'history' => $tab === 'history' ? $this->history($state, $profile) : null,
        ]);
    }

    public function updateProfile(StateProfileRequest $request, State $state, UpdateStateProfile $update): RedirectResponse
    {
        $this->authorize('updateProfile', $state);

        $update($state, $request->profile(), $request->facts(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Profile saved.']);
    }

    public function markReviewed(Request $request, State $state, MarkStateProfileReviewed $review): RedirectResponse
    {
        $this->authorize('updateProfile', $state);

        $review($state, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Profile marked as reviewed.']);
    }

    /**
     * The tabs this viewer may use: the reference tabs, the leadership tab
     * with political.view, and the operational tabs (whole-state
     * jurisdiction only) for modules they may view.
     *
     * @return list<string>
     */
    private function tabs(User $user, bool $operational): array
    {
        $usable = [
            'leadership' => $user->can('viewAny', PoliticalOffice::class),
            'security_presence' => $operational && ($user->can('viewAny', AgencyFormation::class) || $user->can('viewAny', Flashpoint::class)
                || $user->can('viewAny', ThreatReport::class) || $user->can('viewAny', RecordDissemination::class)),
            'organizations' => $operational && $user->can('viewAny', OrganizationSite::class),
            'incidents' => $operational && $user->can('viewAny', Incident::class),
            'history' => $operational,
        ];

        return array_values(array_filter(self::TABS, fn (string $tab) => $usable[$tab] ?? in_array($tab, self::REFERENCE_TABS, true)));
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(User $user, State $state, bool $operational, Leadership $leadership): array
    {
        $facts = StateFacts::for($state);
        $map = MapView::for($state);

        return [
            // State-wide figures (area, population, communities) and the
            // state officers are for whole-state viewers; an LGA chief sees
            // the state's structure only.
            'facts' => $operational
                ? [...$facts, 'communities' => Community::query()->where('communities.state_id', $state->id)->count()]
                : ['lgas' => $facts['lgas'], 'districts' => $facts['districts']],
            'officers' => $operational ? $leadership->stateOfficers($state) : null,
            'map' => $map,
            'lgaOutlines' => MapView::lgaOutlines($state),
            'mapUrl' => $user->checkPermissionTo(Permission::MapView->value)
                ? ($map['center'] === null ? '/map' : sprintf('/map?lat=%s&lng=%s&z=%d', $map['center'][0], $map['center'][1], $map['zoom']))
                : null,
            'dashboardUrl' => $this->dashboardUrl($user, $state, $operational),
        ];
    }

    /** The state's operational dashboard (Phase 11A), for users who may open it. */
    private function dashboardUrl(User $user, State $state, bool $operational): ?string
    {
        if ($user->jurisdiction_level === JurisdictionLevel::National) {
            return $user->can('viewDashboard', $state) ? route('dashboard.states.show', $state, false) : null;
        }

        // The state's own directors: their dashboard is the state dashboard.
        return $operational && $user->state_id === $state->id && $user->checkPermissionTo(Permission::IncidentsView->value)
            ? route('dashboard', [], false)
            : null;
    }

    /**
     * The state's LGAs within the viewer's reach (an LGA chief: their own).
     *
     * @return list<array<string, mixed>>
     */
    private function lgas(User $user, State $state): array
    {
        $lgas = app(JurisdictionOptions::class)->lgaQuery($user)->active()
            ->where('lgas.state_id', $state->id)
            ->with('senatorialDistrict')
            ->orderBy('lgas.name')
            ->get();
        $ids = array_values(array_map('intval', $lgas->modelKeys()));

        $stats = ProfileCompleteness::forLgas($lgas);
        $chiefs = OfficerPosting::query()->with('user')
            ->where('role', Role::LgaSecurityChief->value)
            ->whereIn('lga_id', $ids)
            ->whereNull('ended_at')
            ->orderBy('started_at')
            ->get()
            ->filter(fn (OfficerPosting $p) => $p->user !== null)
            ->keyBy('lga_id');
        $incidents = $user->checkPermissionTo(Permission::IncidentsView->value) && $ids !== []
            ? AnalyticsFilters::from(['period' => '30d'], $user)->incidents($user)
                ->whereIn('incidents.lga_id', $ids)->toBase()
                ->selectRaw('incidents.lga_id AS lga, count(*) AS total')->groupBy('incidents.lga_id')
                ->pluck('total', 'lga')->all()
            : null;

        return array_values($lgas->map(fn (Lga $lga) => [
            'ulid' => $lga->ulid,
            'name' => $lga->name,
            'district' => $lga->senatorialDistrict->name ?? null,
            'headquarters' => $lga->headquarters,
            'chief' => ($posting = $chiefs->get($lga->id)) === null ? null : ['ulid' => $posting->user->ulid, 'name' => $posting->user->name],
            'completeness' => $stats->get($lga->id)['completeness'] ?? 0,
            'incidents30' => $incidents === null ? null : (int) ($incidents[$lga->id] ?? 0),
        ])->all());
    }

    /**
     * Formations by agency (commands first), active flashpoints and
     * blackspots by concern level, active threat reports by urgency and
     * disseminations the state received. Each through its own scopes, and
     * only for modules the viewer may view.
     *
     * @return array<string, mixed>
     */
    private function securityPresence(User $user, State $state): array
    {
        return [
            'formations' => $user->can('viewAny', AgencyFormation::class) ? $this->formations($state) : null,
            'flashpoints' => $user->can('viewAny', Flashpoint::class)
                ? self::grouped(
                    Flashpoint::query()->withCoordinates()->with(['threatType', 'lga', 'state'])
                        ->where('flashpoints.state_id', $state->id)
                        ->where('flashpoints.status', '<>', FlashpointStatus::Resolved->value)
                        ->byConcern()
                        ->get(),
                    fn (Flashpoint $f) => $f->concern_level->value,
                    array_map(fn (ConcernLevel $c) => [$c->value, $c->label()], array_reverse(ConcernLevel::cases())),
                    fn (Flashpoint $f) => FlashpointPresenter::row($f),
                )
                : null,
            'threats' => $user->can('viewAny', ThreatReport::class)
                ? self::grouped(
                    ThreatReport::query()->withCoordinates()->with(['threatType', 'lga', 'state'])
                        ->active()
                        ->where('threat_reports.state_id', $state->id)
                        ->byUrgency()
                        ->get(),
                    fn (ThreatReport $t) => $t->urgency->value,
                    array_map(fn (ThreatUrgency $u) => [$u->value, $u->label()], array_reverse(ThreatUrgency::cases())),
                    fn (ThreatReport $t) => ThreatReportPresenter::row($t),
                )
                : null,
            'disseminations' => $user->can('viewAny', RecordDissemination::class) ? $this->disseminations($state) : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function formations(State $state): array
    {
        $formations = AgencyFormation::query()->withCoordinates()->with(['agency', 'lga', 'state'])
            ->where('agency_formations.state_id', $state->id)
            ->where('agency_formations.status', '<>', FormationStatus::Closed->value)
            ->get();

        return array_values($formations->groupBy('agency_id')
            ->map(fn (Collection $group) => [
                'agency' => AgencyFormationPresenter::agency($group->first()->agency),
                'formations' => array_values($group
                    ->sortBy([
                        fn (AgencyFormation $a, AgencyFormation $b) => (self::COMMAND_ORDER[$a->formation_type->value] ?? 9) <=> (self::COMMAND_ORDER[$b->formation_type->value] ?? 9),
                        fn (AgencyFormation $a, AgencyFormation $b) => strcasecmp($a->name, $b->name),
                    ])
                    ->map(fn (AgencyFormation $f) => AgencyFormationPresenter::row($f))
                    ->all()),
            ])
            ->sortBy(fn (array $group) => mb_strtolower($group['agency']['name']))
            ->all());
    }

    /**
     * Disseminations the state received from other states (Phase 7A), still
     * active, whose record the viewer can see through their own scopes.
     *
     * @return list<array<string, mixed>>
     */
    private function disseminations(State $state): array
    {
        $rows = RecordDissemination::query()->active()
            ->with(['sourceState', 'sourceLga', 'targetLga'])
            ->where('record_disseminations.target_state_id', $state->id)
            ->where('record_disseminations.source_state_id', '<>', $state->id)
            ->where(fn ($q) => $q
                ->where(fn ($t) => $t->where('subject_type', 'threat_report')->whereIn('subject_id', ThreatReport::query()->select('threat_reports.id')))
                ->orWhere(fn ($f) => $f->where('subject_type', 'flashpoint')->whereIn('subject_id', Flashpoint::query()->select('flashpoints.id'))))
            ->orderByDesc('disseminated_at')
            ->limit(100)
            ->get();

        $subjects = [
            'threat_report' => ThreatReport::query()->whereKey($rows->where('subject_type', 'threat_report')->pluck('subject_id')->all())->get()->keyBy('id'),
            'flashpoint' => Flashpoint::query()->whereKey($rows->where('subject_type', 'flashpoint')->pluck('subject_id')->all())->get()->keyBy('id'),
        ];

        return array_values($rows->map(function (RecordDissemination $d) use ($subjects): ?array {
            $subject = $subjects[$d->subject_type]->get($d->subject_id) ?? null;

            return $subject instanceof ThreatReport || $subject instanceof Flashpoint ? [
                'ulid' => $d->ulid,
                'kind' => $subject->disseminationKind(),
                'label' => $subject->disseminationLabel(),
                'href' => $subject->disseminationPath(),
                'from' => $d->sourceLga->name.' LGA, '.$d->sourceState->name,
                'to' => $d->targetLga === null ? 'The whole state' : $d->targetLga->name.' LGA',
                'disseminated_at' => $d->disseminated_at->toIso8601String(),
                'expires_at' => $d->expires_at->toIso8601String(),
                'acknowledged' => $d->acknowledged_at !== null,
            ] : null;
        })->filter()->all());
    }

    /**
     * High and critical-infrastructure organization sites, by LGA.
     *
     * @return list<array{lga: array{ulid: string, name: string}, sites: list<array<string, mixed>>}>
     */
    private function organizations(State $state): array
    {
        $sites = OrganizationSite::query()->withCoordinates()
            ->with(['organization.logo', 'lga', 'state', 'coverPhoto'])
            ->where('organization_sites.state_id', $state->id)
            ->whereIn('organization_sites.security_relevance', [SecurityRelevance::CriticalInfrastructure->value, SecurityRelevance::High->value])
            ->orderByRaw("CASE organization_sites.security_relevance WHEN 'critical_infrastructure' THEN 0 ELSE 1 END")
            ->orderBy('organization_sites.name')
            ->get();

        return array_values($sites->groupBy('lga_id')
            ->map(fn (Collection $group) => [
                'lga' => ['ulid' => $group->first()->lga->ulid, 'name' => $group->first()->lga->name],
                'sites' => array_values($group->map(fn (OrganizationSite $s) => OrganizationPresenter::site($s))->all()),
            ])
            ->sortBy(fn (array $group) => $group['lga']['name'])
            ->all());
    }

    /**
     * The activity log of the state profile, and of the state's own record
     * (its capital and reference changes).
     *
     * @return mixed paginated activity entries
     */
    private function history(State $state, ?StateProfile $profile): mixed
    {
        return Activity::query()
            ->with(['causer', 'subject'])
            ->where(fn ($q) => $q
                ->where(fn ($s) => $s->where('subject_type', $state->getMorphClass())->where('subject_id', $state->id))
                ->when($profile !== null, fn ($p) => $p->orWhere(fn ($s) => $s->where('subject_type', (new StateProfile)->getMorphClass())->where('subject_id', $profile?->id))))
            ->latest('id')
            ->paginate(25, pageName: 'history_page')
            ->withQueryString()
            ->through(fn (Activity $activity) => ActivityPresenter::present($activity));
    }

    /**
     * Rows grouped under fixed headings, in heading order (each row keeps
     * its order within its group); empty groups are left out.
     *
     * @template TModel
     *
     * @param  Collection<int, TModel>  $rows
     * @param  callable(TModel): string  $key  the heading value of a row
     * @param  list<array{0: string, 1: string}>  $headings  [value, label]
     * @param  callable(TModel): array<string, mixed>  $present
     * @return list<array{value: string, label: string, rows: list<array<string, mixed>>}>
     */
    private static function grouped(Collection $rows, callable $key, array $headings, callable $present): array
    {
        $out = [];
        foreach ($headings as [$value, $label]) {
            $group = $rows->filter(fn ($row) => $key($row) === $value);
            if ($group->isNotEmpty()) {
                $out[] = ['value' => $value, 'label' => $label, 'rows' => array_values($group->map($present)->all())];
            }
        }

        return $out;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
