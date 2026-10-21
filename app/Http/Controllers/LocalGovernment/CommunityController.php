<?php

namespace App\Http\Controllers\LocalGovernment;

use App\Actions\LocalGovernment\DeleteCommunity;
use App\Actions\LocalGovernment\SaveCommunity;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\CommunityPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Requests\LocalGovernment\CommunityRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Models\Ward;
use App\Support\CommunityInformation\TabData;
use App\Support\Dashboard\MapView;
use App\Support\Exports\Lists\Exporters\CommunitiesExporter;
use App\Support\Incidents\IncidentLinks;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Communities: cross-LGA search, detail, create (in an LGA), edit and
 * soft delete with a reason. {community} is bound through the jurisdiction
 * scope, so out-of-jurisdiction communities are 404.
 */
class CommunityController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options, CommunitiesExporter $exporter): Response
    {
        $this->authorize('viewAny', Community::class);
        $user = $this->actor($request);

        $states = $options->states($user);
        $filters = $exporter->filters($request->query(), $user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);

        // The same query as the export (Phase 11E).
        $results = $exporter->around(fn () => $exporter->query($user, $filters)
            ->with(['lga', 'state', 'ward'])
            ->paginate(25)
            ->withQueryString());

        return Inertia::render('communities/index', [
            'results' => $results->through(fn (Community $community) => CommunityPresenter::row($community)),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'state' => $filters['state'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'type' => $filters['type'] ?? '',
            ],
            'states' => $states->count() > 1 ? $states->map(fn ($s) => ['ulid' => $s->ulid, 'name' => $s->name])->values() : [],
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $lga) => ['ulid' => $lga->ulid, 'name' => $lga->name])->values() : [],
            'types' => CommunityPresenter::typeOptions(),
            'can' => ['import' => $user->can('importAnyCommunities', Lga::class)],
        ]);
    }

    public function create(Lga $lga): Response
    {
        $this->authorize('addCommunity', $lga);

        return $this->form($lga, null);
    }

    public function store(CommunityRequest $request, Lga $lga, SaveCommunity $save): RedirectResponse
    {
        $this->authorize('addCommunity', $lga);

        $community = $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('communities.show', $community)
            ->with('toast', ['type' => 'success', 'message' => "Community \"{$community->name}\" added."]);
    }

    /** Tabs of a community page. */
    public const TABS = ['details', 'religious_institutions', 'vulnerable_population', 'security_notes', 'incidents', 'history'];

    public function show(Request $request, Community $community, TabData $data): Response
    {
        $this->authorize('view', $community);
        $user = $this->actor($request);
        // Incidents in the community (Phase 5D), hidden when there are none.
        $hasIncidents = $user->can('viewAny', Incident::class) && IncidentLinks::community($community)->exists();
        $tabs = $hasIncidents ? self::TABS : array_values(array_diff(self::TABS, ['incidents']));
        $tab = in_array($request->query('tab'), $tabs, true) ? $request->query('tab') : 'details';
        $lga = $community->lga;

        $record = $this->withCoordinates($community);

        return Inertia::render('communities/show', [
            'community' => CommunityPresenter::detail($record),
            'marker' => CommunityPresenter::marker($record),
            'map' => MapView::for($record->lga),
            'tab' => $tab,
            'tabs' => $tabs,
            'incidents' => $tab === 'incidents' ? IncidentLinks::rows(IncidentLinks::community($community)) : null,
            'options' => CommunityInformationPresenter::options(),
            'religiousInstitutions' => $tab === 'religious_institutions' && $user->can('viewAny', ReligiousInstitution::class)
                ? [...$data->religiousInstitutions($lga, $community, $request), 'communities' => [], 'map' => MapView::for($lga)]
                : null,
            'vulnerablePopulation' => $tab === 'vulnerable_population' && $user->can('viewAny', VulnerablePopulationStat::class)
                ? $data->vulnerablePopulation($lga, $community, $request)
                : null,
            'securityNotes' => $tab === 'security_notes' && $user->can('viewAny', CommunitySecurityNote::class)
                ? $data->securityNotes($lga, $community, $request)
                : null,
            'history' => $tab === 'history'
                ? Activity::query()
                    ->with(['causer', 'subject'])
                    ->where('subject_type', $community->getMorphClass())
                    ->where('subject_id', $community->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $activity) => ActivityPresenter::present($activity))
                : null,
            'can' => [
                'update' => $user->can('update', $community),
                'delete' => $user->can('delete', $community),
                'addReligiousInstitution' => $user->can('addReligiousInstitution', $lga),
                'addVulnerableFigures' => $user->can('addVulnerableFigures', $lga),
                'addSecurityNote' => $user->can('addSecurityNote', $lga),
                'viewReligiousInstitutions' => $user->can('viewAny', ReligiousInstitution::class),
                'viewVulnerableFigures' => $user->can('viewAny', VulnerablePopulationStat::class),
                'viewSecurityNotes' => $user->can('viewAny', CommunitySecurityNote::class),
                // For showing row actions; each action is authorised on the record.
                'deleteReligiousInstitutions' => $user->checkPermissionTo(Permission::ReligiousInstitutionsDelete->value),
                'deleteVulnerableFigures' => $user->checkPermissionTo(Permission::VulnerableStatsDelete->value),
            ],
        ]);
    }

    public function edit(Community $community): Response
    {
        $this->authorize('update', $community);

        return $this->form($community->lga, $this->withCoordinates($community));
    }

    public function update(CommunityRequest $request, Community $community, SaveCommunity $save): RedirectResponse
    {
        $this->authorize('update', $community);

        $save->update($community, $request->values(), $this->actor($request));

        return redirect()->route('communities.show', $community)
            ->with('toast', ['type' => 'success', 'message' => 'Community saved.']);
    }

    public function destroy(DeleteRecordRequest $request, Community $community, DeleteCommunity $delete): RedirectResponse
    {
        $this->authorize('delete', $community);

        $delete($community, (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $community->lga, 'tab' => 'communities'])
            ->with('toast', ['type' => 'success', 'message' => "Community \"{$community->name}\" deleted."]);
    }

    private function form(Lga $lga, ?Community $community): Response
    {
        $lga->loadMissing(['state', 'senatorialDistrict']);

        return Inertia::render('communities/form', [
            'lga' => LgaPresenter::facts($lga),
            'community' => $community === null ? null : CommunityPresenter::detail($community),
            'types' => CommunityPresenter::typeOptions(),
            'wards' => Ward::query()->where('lga_id', $lga->id)
                ->where(fn (Builder $query) => $query->where('is_active', true)->when($community?->ward_id, fn (Builder $q, int $id) => $q->orWhere('id', $id)))
                ->orderBy('name')
                ->get(['ulid', 'name', 'code'])
                ->map(fn (Ward $ward) => ['ulid' => $ward->ulid, 'name' => "{$ward->name} ({$ward->code})"]),
            'map' => MapView::for($lga),
        ]);
    }

    private function withCoordinates(Community $community): Community
    {
        return Community::query()->withCoordinates()->with(['lga', 'state', 'ward'])->whereKey($community->id)->firstOrFail();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
