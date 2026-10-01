<?php

namespace App\Http\Controllers\Flashpoints;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Flashpoints\SaveFlashpoint;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\FlashpointPresenter;
use App\Http\Requests\Flashpoints\FlashpointRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Flashpoints\FlashpointLinks;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Threats\ThreatLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Flashpoints and blackspots (Phase 6A). {flashpoint} is bound through the
 * jurisdiction and classification scopes. Views above `restricted` are
 * access-logged.
 */
class FlashpointController extends Controller
{
    private const TABS = ['overview', 'assessments', 'incidents', 'security_notes', 'threats', 'history'];

    /** Suggestion windows offered on the Incidents tab (the default is configured). */
    private const SUGGESTION_MONTHS = [3, 6, 12, 24];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Flashpoint::class);
        $user = $this->actor($request);
        $states = $options->states($user);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(FlashpointKind::class)],
            'concern' => ['nullable', Rule::enum(ConcernLevel::class)],
            'status' => ['nullable', Rule::enum(FlashpointStatus::class)],
            'threat_type' => ['nullable', 'string', 'size:26'],
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'lga' => ['nullable', 'string', 'size:26'],
            'overdue' => ['nullable', Rule::in(['1'])],
        ]);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);
        $lga = isset($filters['lga']) ? $lgas->firstWhere('ulid', $filters['lga']) : null;
        $threat = isset($filters['threat_type']) ? ThreatType::query()->where('ulid', $filters['threat_type'])->first() : null;

        $query = fn (): Builder => Flashpoint::query()
            ->when($filters['kind'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.kind', $v))
            ->when($filters['concern'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.concern_level', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.status', $v))
            ->when($threat, fn (Builder $q) => $q->where('flashpoints.threat_type_id', $threat?->id))
            ->when($state, fn (Builder $q) => $q->where('flashpoints.state_id', $state?->id))
            ->when($lga, fn (Builder $q) => $q->where('flashpoints.lga_id', $lga?->id))
            ->when(isset($filters['overdue']), fn (Builder $q) => $q
                ->where('flashpoints.status', '!=', FlashpointStatus::Resolved->value)
                ->where('flashpoints.review_due_at', '<', CarbonImmutable::today()->toDateString()))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->search($term));

        $flashpoints = $query()
            ->withCoordinates()
            ->with(['threatType', 'lga', 'state'])
            ->when(! isset($filters['q']), fn (Builder $q) => $q->byConcern())
            ->paginate(25)
            ->withQueryString();

        $areas = $query()
            ->withCoordinates()
            ->with(['threatType', 'lga', 'state'])
            ->byConcern()
            ->limit((int) config('buffalo.flashpoints.map_cap'))
            ->get();

        return Inertia::render('flashpoints/index', [
            'flashpoints' => $flashpoints->through(fn (Flashpoint $f) => FlashpointPresenter::row($f)),
            'areas' => $areas->map(fn (Flashpoint $f) => FlashpointPresenter::row($f))->values(),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'kind' => $filters['kind'] ?? '',
                'concern' => $filters['concern'] ?? '',
                'status' => $filters['status'] ?? '',
                'threat_type' => $filters['threat_type'] ?? '',
                'state' => $filters['state'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'overdue' => $filters['overdue'] ?? '',
            ],
            'options' => FlashpointPresenter::options(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'map' => MapView::for($lga ?? $state ?? $user->lga ?? $user->state),
            'can' => ['create' => $user->can('create', Flashpoint::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', Flashpoint::class);

        return $this->form($this->actor($request), $options, null, $request->query('lga'));
    }

    public function store(FlashpointRequest $request, SaveFlashpoint $save): RedirectResponse
    {
        $this->authorize('create', Flashpoint::class);

        /** @var Lga $lga */
        $lga = $request->lga();
        $flashpoint = $save->create($lga, $request->values(), $request->firstAssessment(), $this->actor($request));

        return redirect()->route('flashpoints.show', $flashpoint)
            ->with('toast', ['type' => 'success', 'message' => "{$flashpoint->kind->label()} \"{$flashpoint->name}\" recorded."]);
    }

    public function show(Request $request, Flashpoint $flashpoint): Response
    {
        $this->authorize('view', $flashpoint);
        $user = $this->actor($request);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview';

        if ($flashpoint->logsViews()) {
            $flashpoint->recordAccess(context: "tab:{$tab}");
        }

        Inertia::encryptHistory();

        $row = Flashpoint::query()->withCoordinates()->whereKey($flashpoint->id)->firstOrFail();
        $row->load(['threatType', 'lga', 'state', 'community']);
        $canLink = $user->can('update', $flashpoint);
        $sameCategory = $request->boolean('same_category');
        $months = (int) $request->query('months', (string) config('buffalo.flashpoints.suggestion_months'));
        $months = in_array($months, self::SUGGESTION_MONTHS, true) ? $months : (int) config('buffalo.flashpoints.suggestion_months');

        return Inertia::render('flashpoints/show', [
            'flashpoint' => FlashpointPresenter::detail($row),
            'tab' => $tab,
            'map' => MapView::for($row->lga),
            // Linked incidents on the overview map (visible ones only).
            'incidentMarkers' => $tab === 'overview' && $user->can('viewAny', Incident::class) ? FlashpointLinks::incidentMarkers($flashpoint) : [],
            'latest' => FlashpointPresenter::assessments(FlashpointAssessment::query()
                ->where('flashpoint_id', $flashpoint->id)->latest('assessed_at')->latest('id')->limit(1)->get())[0] ?? null,
            'assessments' => $tab === 'assessments'
                ? FlashpointPresenter::assessments(FlashpointAssessment::query()->where('flashpoint_id', $flashpoint->id)->latest('assessed_at')->latest('id')->get())
                : null,
            'incidents' => $tab === 'incidents' && $user->can('viewAny', Incident::class) ? [
                'linked' => FlashpointLinks::incidents($flashpoint),
                'suggestions' => $canLink ? FlashpointLinks::incidentSuggestions($row, $sameCategory, $months) : [],
                'sameCategory' => $sameCategory,
                'months' => $months,
                'monthOptions' => self::SUGGESTION_MONTHS,
            ] : null,
            'securityNotes' => $tab === 'security_notes' && $user->can('viewAny', CommunitySecurityNote::class) ? [
                'linked' => FlashpointLinks::securityNotes($flashpoint),
                'suggestions' => $canLink ? FlashpointLinks::securityNoteSuggestions($row) : [],
            ] : null,
            // Threat reports linked to it (Phase 6B), through their scopes.
            'threatReports' => $tab === 'threats' && $user->can('viewAny', ThreatReport::class) ? ThreatLinks::forFlashpoint($flashpoint) : null,
            'history' => $tab === 'history'
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $flashpoint->getMorphClass())->where('subject_id', $flashpoint->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'options' => $tab === 'overview' || $tab === 'assessments' ? FlashpointPresenter::options() : null,
            'can' => [
                'update' => $canLink,
                'assess' => $user->can('assess', $flashpoint),
                'delete' => $user->can('delete', $flashpoint),
                'viewIncidents' => $user->can('viewAny', Incident::class),
                'viewSecurityNotes' => $user->can('viewAny', CommunitySecurityNote::class),
                'viewThreats' => $user->can('viewAny', ThreatReport::class),
            ],
        ]);
    }

    public function edit(Request $request, Flashpoint $flashpoint, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $flashpoint);

        return $this->form($this->actor($request), $options, $flashpoint, $request->query('lga'));
    }

    public function update(FlashpointRequest $request, Flashpoint $flashpoint, SaveFlashpoint $save): RedirectResponse
    {
        $this->authorize('update', $flashpoint);

        $save->update($flashpoint, $request->lga(), $request->values(), $this->actor($request));

        return redirect()->route('flashpoints.show', $flashpoint)->with('toast', ['type' => 'success', 'message' => 'Flashpoint saved.']);
    }

    public function destroy(DeleteRecordRequest $request, Flashpoint $flashpoint, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $flashpoint);

        $name = $flashpoint->name;
        $delete($flashpoint, 'flashpoint', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('flashpoints.index')->with('toast', ['type' => 'success', 'message' => "\"{$name}\" deleted."]);
    }

    private function form(User $user, JurisdictionOptions $options, ?Flashpoint $flashpoint, mixed $lgaUlid): Response
    {
        $row = $flashpoint === null ? null : Flashpoint::query()->withCoordinates()->whereKey($flashpoint->id)->firstOrFail();
        $row?->load(['threatType', 'lga', 'state', 'community']);
        $lgas = $options->lgas($user)->load('state:id,ulid,name');
        // A chosen LGA (?lga=, while filling the form), else the current one.
        $lga = (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : null)
            ?? ($row !== null ? $row->lga : null)
            ?? ($lgas->count() === 1 ? $lgas->first() : null);

        return Inertia::render('flashpoints/form', [
            'flashpoint' => $row === null ? null : FlashpointPresenter::detail($row),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name]),
            'options' => FlashpointPresenter::options($row?->threatType),
            'map' => MapView::for($lga),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
