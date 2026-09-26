<?php

namespace App\Http\Controllers\CriminalGroups;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\CriminalGroups\SaveCriminalGroup;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\CriminalGroupPresenter;
use App\Http\Requests\CriminalGroups\CriminalGroupRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\CriminalGroupMember;
use App\Models\Lga;
use App\Models\Person;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Criminal groups (Phase 3B). {group} is bound through the jurisdiction
 * (home LGA) and classification scopes: areas of operation never make a
 * group visible. Every view of a group is access-logged.
 */
class CriminalGroupController extends Controller
{
    public const TABS = ['overview', 'areas', 'members', 'history'];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', CriminalGroup::class);
        $user = $this->actor($request);
        $lgas = $options->lgas($user);
        $groupOptions = CriminalGroupPresenter::options();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(array_column($groupOptions['types'], 'value'))],
            'status' => ['nullable', 'string', Rule::in(array_column($groupOptions['statuses'], 'value'))],
            'classification' => ['nullable', 'string', Rule::in(array_column($groupOptions['classifications'], 'value'))],
            'lga' => ['nullable', 'string', Rule::in($lgas->pluck('ulid'))],
        ]);
        $lga = isset($filters['lga']) ? $lgas->firstWhere('ulid', $filters['lga']) : null;

        $groups = CriminalGroup::query()
            ->with(['lga', 'state'])
            ->withCount(['members as visible_members_count' => fn (Builder $q) => $q->whereHas('person')])
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('criminal_groups.type', $type))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('criminal_groups.status', $status))
            ->when($filters['classification'] ?? null, fn (Builder $q, string $level) => $q->where('criminal_groups.classification', $level))
            ->when($lga, fn (Builder $q) => $q->where('criminal_groups.lga_id', $lga?->id))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->search($term), fn (Builder $q) => $q->orderBy('criminal_groups.name'))
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('criminal-groups/index', [
            'groups' => $groups->through(fn (CriminalGroup $group) => CriminalGroupPresenter::row($group)),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'type' => $filters['type'] ?? '',
                'status' => $filters['status'] ?? '',
                'classification' => $filters['classification'] ?? '',
                'lga' => $filters['lga'] ?? '',
            ],
            'options' => $groupOptions,
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'can' => ['create' => $user->can('create', CriminalGroup::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', CriminalGroup::class);

        return $this->form($this->actor($request), $options, null);
    }

    public function store(CriminalGroupRequest $request, SaveCriminalGroup $save): RedirectResponse
    {
        $this->authorize('create', CriminalGroup::class);

        /** @var Lga $lga */
        $lga = $request->lga();
        $group = $save->create($lga, $request->validated(), $this->actor($request));

        return redirect()->route('criminal-groups.show', $group)
            ->with('toast', ['type' => 'success', 'message' => "Group \"{$group->name}\" added."]);
    }

    public function show(Request $request, CriminalGroup $group): Response
    {
        $this->authorize('view', $group);
        $user = $this->actor($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';

        $group->recordAccess(context: "tab:{$tab}");
        Inertia::encryptHistory();

        $group->load(['lga', 'state'])->loadCount(['members as visible_members_count' => fn (Builder $q) => $q->whereHas('person')]);

        $areas = CriminalGroupArea::query()
            ->where('criminal_group_id', $group->id)
            ->with(['lga', 'state'])
            ->join('lgas', 'lgas.id', '=', 'criminal_group_areas.lga_id')
            ->select('criminal_group_areas.*')
            ->selectRaw('ST_Y(lgas.centroid::geometry) AS latitude, ST_X(lgas.centroid::geometry) AS longitude')
            ->orderBy('lgas.name')
            ->get();

        return Inertia::render('criminal-groups/show', [
            'group' => CriminalGroupPresenter::detail($group),
            'tab' => $tab,
            'tabs' => self::TABS,
            'areas' => $areas->map(fn (CriminalGroupArea $area) => CriminalGroupPresenter::area($area))->values(),
            'members' => $tab === 'members' || $tab === 'overview'
                ? CriminalGroupMember::query()
                    ->where('criminal_group_id', $group->id)
                    ->withVisiblePerson()
                    ->with(['person.lga', 'person.roles', 'person.personOfInterest', 'person.media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)])
                    ->orderByRaw("CASE membership_status WHEN 'confirmed' THEN 1 WHEN 'suspected' THEN 2 ELSE 3 END")
                    ->get()
                    ->map(fn (CriminalGroupMember $m) => CriminalGroupPresenter::member($m))
                    ->values()
                : null,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where(fn (Builder $q) => $q
                        ->where(fn (Builder $s) => $s->where('subject_type', $group->getMorphClass())->where('subject_id', $group->id)))
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'options' => CriminalGroupPresenter::options(),
            'states' => $tab === 'areas' ? State::query()->active()->orderBy('name')->get(['ulid', 'name'])->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name]) : [],
            'can' => [
                'update' => $user->can('update', $group),
                'delete' => $user->can('delete', $group),
                'designatePoi' => $user->checkPermissionTo(Permission::PoiManage->value),
            ],
        ]);
    }

    public function edit(Request $request, CriminalGroup $group, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $group);

        return $this->form($this->actor($request), $options, $group);
    }

    public function update(CriminalGroupRequest $request, CriminalGroup $group, SaveCriminalGroup $save): RedirectResponse
    {
        $this->authorize('update', $group);

        $save->update($group, $request->validated(), $this->actor($request));

        return redirect()->route('criminal-groups.show', $group)->with('toast', ['type' => 'success', 'message' => 'Group saved.']);
    }

    public function destroy(DeleteRecordRequest $request, CriminalGroup $group, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $group);

        $name = $group->name;
        $delete($group, 'criminal_group', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('criminal-groups.index')->with('toast', ['type' => 'success', 'message' => "Group \"{$name}\" deleted."]);
    }

    private function form(User $user, JurisdictionOptions $options, ?CriminalGroup $group): Response
    {
        return Inertia::render('criminal-groups/form', [
            'group' => $group === null ? null : CriminalGroupPresenter::detail($group->load(['lga', 'state'])),
            'lgas' => $group === null ? $options->lgas($user)->load('state:id,name')->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values() : [],
            'options' => CriminalGroupPresenter::options(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
