<?php

namespace App\Http\Controllers\Security;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Security\SaveAgencyFormation;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\AgencyFormationPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Security\AgencyFormationRequest;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\Community;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\User;
use App\Support\Arrests\ArrestLinks;
use App\Support\Dashboard\MapView;
use App\Support\Exports\Lists\Exporters\FormationsExporter;
use App\Support\Incidents\IncidentLinks;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Agency formations (Phase 4A). {formation} is bound through the
 * jurisdiction and classification scopes. Every view is access-logged.
 */
class AgencyFormationController extends Controller
{
    /** Map markers shown on the list page (the filtered set, with a location). */
    private const MAP_LIMIT = 1000;

    public function index(Request $request, JurisdictionOptions $options, FormationsExporter $exporter): Response
    {
        $this->authorize('viewAny', AgencyFormation::class);
        $user = $this->actor($request);
        $states = $options->states($user);

        $filters = $exporter->filters($request->query(), $user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);

        // The same filtered query as the export (Phase 11E).
        $query = fn (): Builder => $exporter->filtered($user, $filters);

        $formations = $query()
            ->withCoordinates()
            ->with(['agency', 'lga', 'state'])
            ->when(! isset($filters['q']), fn (Builder $q) => $q->orderBy('agency_formations.name'))
            ->paginate(25)
            ->withQueryString();

        $markers = $query()
            ->withCoordinates()
            ->with(['agency', 'lga', 'state'])
            ->whereNotNull('agency_formations.location')
            ->limit(self::MAP_LIMIT)
            ->get();

        return Inertia::render('formations/index', [
            'formations' => $formations->through(fn (AgencyFormation $f) => AgencyFormationPresenter::row($f)),
            'markers' => $markers->map(fn (AgencyFormation $f) => AgencyFormationPresenter::row($f))->values(),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'agency' => $filters['agency'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'state' => $filters['state'] ?? '',
                'zone' => $filters['zone'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'temporary' => $filters['temporary'] ?? '',
            ],
            'options' => AgencyFormationPresenter::options(),
            'agencies' => AgencyFormationPresenter::agencyOptions(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'zones' => PlaceFilter::options($user),
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'can' => ['create' => $user->can('create', AgencyFormation::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', AgencyFormation::class);

        return $this->form($this->actor($request), $options, null, $request->query('lga'));
    }

    public function store(AgencyFormationRequest $request, SaveAgencyFormation $save): RedirectResponse
    {
        $this->authorize('create', AgencyFormation::class);

        /** @var SecurityAgency $agency */
        $agency = $request->agency();
        /** @var Lga $lga */
        $lga = $request->lga();
        $formation = $save->create($agency, $lga, $request->values(), $this->actor($request));

        return redirect()->route('formations.show', $formation)
            ->with('toast', ['type' => 'success', 'message' => "Formation \"{$formation->name}\" added."]);
    }

    public function show(Request $request, AgencyFormation $formation): Response
    {
        $this->authorize('view', $formation);
        $user = $this->actor($request);
        // Incidents linked to it (Phase 5D): the tab is hidden when there are none.
        $hasIncidents = $user->can('viewAny', Incident::class) && IncidentLinks::formation($formation)->exists();
        // Arrests by the formation (Phase 11C), likewise.
        $hasArrests = $user->can('viewAny', Arrest::class) && ArrestLinks::formation($formation)->exists();
        $tab = match ($request->query('tab')) {
            'history' => 'history',
            'incidents' => $hasIncidents ? 'incidents' : 'overview',
            'arrests' => $hasArrests ? 'arrests' : 'overview',
            default => 'overview',
        };

        $formation->recordAccess(context: "tab:{$tab}");
        Inertia::encryptHistory();

        $row = AgencyFormation::query()->withCoordinates()->whereKey($formation->id)->firstOrFail();
        $row->load(['agency', 'lga', 'state', 'community']);
        // Parent and children only when the viewer can see them.
        $parent = $formation->parent_formation_id === null ? null : AgencyFormation::query()->find($formation->parent_formation_id);
        $children = AgencyFormation::query()->withCoordinates()->with(['agency', 'lga', 'state'])
            ->where('parent_formation_id', $formation->id)
            ->orderBy('name')->get();

        return Inertia::render('formations/show', [
            'formation' => AgencyFormationPresenter::detail($row, $parent),
            'children' => $children->map(fn (AgencyFormation $f) => AgencyFormationPresenter::row($f))->values(),
            'tab' => $tab,
            'hasIncidents' => $hasIncidents,
            'incidents' => $tab === 'incidents' ? IncidentLinks::rows(IncidentLinks::formation($formation)) : null,
            'hasArrests' => $hasArrests,
            'arrests' => $tab === 'arrests' ? ArrestLinks::rows(ArrestLinks::formation($formation)) : null,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $formation->getMorphClass())->where('subject_id', $formation->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'can' => [
                'update' => $user->can('update', $formation),
                'delete' => $user->can('delete', $formation),
                'create' => $user->can('create', AgencyFormation::class),
            ],
        ]);
    }

    public function edit(Request $request, AgencyFormation $formation, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $formation);

        return $this->form($this->actor($request), $options, $formation, null);
    }

    public function update(AgencyFormationRequest $request, AgencyFormation $formation, SaveAgencyFormation $save): RedirectResponse
    {
        $this->authorize('update', $formation);

        $save->update($formation, $request->values(), $this->actor($request));

        return redirect()->route('formations.show', $formation)->with('toast', ['type' => 'success', 'message' => 'Formation saved.']);
    }

    public function destroy(DeleteRecordRequest $request, AgencyFormation $formation, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $formation);

        $name = $formation->name;
        $delete($formation, 'agency_formation', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('formations.index')->with('toast', ['type' => 'success', 'message' => "Formation \"{$name}\" deleted."]);
    }

    private function form(User $user, JurisdictionOptions $options, ?AgencyFormation $formation, mixed $lgaUlid): Response
    {
        $row = $formation === null ? null : AgencyFormation::query()->withCoordinates()->whereKey($formation->id)->firstOrFail();
        $row?->load(['agency', 'lga', 'state', 'community']);
        $parent = $formation?->parent_formation_id === null ? null : AgencyFormation::query()->find($formation->parent_formation_id);
        $lgas = $formation === null ? $options->lgas($user)->load('state:id,ulid,name') : collect();
        $lga = $row !== null ? $row->lga : (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : ($lgas->count() === 1 ? $lgas->first() : null));

        return Inertia::render('formations/form', [
            'formation' => $row === null ? null : AgencyFormationPresenter::detail($row, $parent),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})", 'state' => $l->state->ulid])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name]),
            'agencies' => AgencyFormationPresenter::agencyOptions($row?->agency),
            'options' => AgencyFormationPresenter::options(),
            'map' => MapView::for($lga),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
