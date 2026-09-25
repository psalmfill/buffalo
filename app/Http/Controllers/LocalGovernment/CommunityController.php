<?php

namespace App\Http\Controllers\LocalGovernment;

use App\Actions\LocalGovernment\DeleteCommunity;
use App\Actions\LocalGovernment\SaveCommunity;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\CommunityPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Requests\LocalGovernment\CommunityRequest;
use App\Http\Requests\LocalGovernment\DeleteCommunityRequest;
use App\Models\Community;
use App\Models\Lga;
use App\Models\User;
use App\Models\Ward;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Community::class);
        $user = $this->actor($request);

        $states = $options->states($user);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'lga' => ['nullable', 'string', 'size:26'],
            'type' => ['nullable', 'string', Rule::in(array_column(CommunityPresenter::typeOptions(), 'value'))],
        ]);

        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);

        $results = Community::query()
            ->with(['lga', 'state', 'ward'])
            ->withCoordinates()
            ->when($state, fn (Builder $query) => $query->where('communities.state_id', $state?->id))
            ->when($filters['lga'] ?? null, fn (Builder $query, string $lga) => $query->whereHas('lga', fn (Builder $q) => $q->where('ulid', $lga)))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('communities.type', $type))
            ->when($filters['q'] ?? null, fn (Builder $query, string $q) => $query->search($q), fn (Builder $query) => $query->orderBy('communities.name'))
            ->paginate(25)
            ->withQueryString();

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

    public function show(Request $request, Community $community): Response
    {
        $this->authorize('view', $community);
        $user = $this->actor($request);
        $tab = $request->query('tab') === 'history' ? 'history' : 'details';

        $record = $this->withCoordinates($community);

        return Inertia::render('communities/show', [
            'community' => CommunityPresenter::detail($record),
            'marker' => CommunityPresenter::marker($record),
            'map' => MapView::for($record->lga),
            'tab' => $tab,
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

    public function destroy(DeleteCommunityRequest $request, Community $community, DeleteCommunity $delete): RedirectResponse
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
