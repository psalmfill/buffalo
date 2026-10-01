<?php

namespace App\Http\Controllers\Threats;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Threats\CreateThreatReport;
use App\Actions\Threats\UpdateThreatReport;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\DisseminationPresenter;
use App\Http\Presenters\ThreatReportPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Threats\ThreatReportRequest;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Jurisdiction\DisseminationResolver;
use App\Support\Jurisdiction\JurisdictionGate;
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
 * Threat reports (Phase 6B). {threat} is bound through the jurisdiction
 * and classification scopes. Views above `restricted` are access-logged.
 */
class ThreatReportController extends Controller
{
    private const TABS = ['overview', 'links', 'timeline', 'history'];

    public function index(Request $request, JurisdictionOptions $options, DisseminationResolver $disseminations): Response
    {
        $this->authorize('viewAny', ThreatReport::class);
        $user = $this->actor($request);
        $states = $options->states($user);

        $filters = $request->validate([
            // "any" shows every status; the default is active only.
            'status' => ['nullable', Rule::in([...array_map(fn (ThreatStatus $s) => $s->value, ThreatStatus::cases()), 'any'])],
            'threat_type' => ['nullable', 'string', 'size:26'],
            'credibility' => ['nullable', Rule::enum(ThreatCredibility::class)],
            'urgency' => ['nullable', Rule::enum(ThreatUrgency::class)],
            'state' => ['nullable', 'string', Rule::in($states->pluck('ulid'))],
            'lga' => ['nullable', 'string', 'size:26'],
            'expiring' => ['nullable', Rule::in(['1'])],
            // Records shared with the user by another jurisdiction (Phase 7A).
            'shared' => ['nullable', Rule::in(['1'])],
        ]);
        $status = $filters['status'] ?? ThreatStatus::Active->value;
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $lgas = $options->lgas($user, $state);
        $lga = isset($filters['lga']) ? $lgas->firstWhere('ulid', $filters['lga']) : null;
        $threat = isset($filters['threat_type']) ? ThreatType::query()->where('ulid', $filters['threat_type'])->first() : null;

        $query = fn (): Builder => ThreatReport::query()
            ->when($status !== 'any', fn (Builder $q) => $q->where('threat_reports.status', $status))
            ->when($threat, fn (Builder $q) => $q->where('threat_reports.threat_type_id', $threat?->id))
            ->when($filters['credibility'] ?? null, fn (Builder $q, string $v) => $q->where('threat_reports.credibility', $v))
            ->when($filters['urgency'] ?? null, fn (Builder $q, string $v) => $q->where('threat_reports.urgency', $v))
            ->when($state, fn (Builder $q) => $q->where('threat_reports.state_id', $state?->id))
            ->when($lga, fn (Builder $q) => $q->where('threat_reports.lga_id', $lga?->id))
            ->when(isset($filters['expiring']), fn (Builder $q) => $q->active()
                ->where('threat_reports.expires_at', '<=', CarbonImmutable::now()->addHours((int) config('buffalo.threats.expiring_soon_hours'))))
            ->when(isset($filters['shared']), fn (Builder $q) => $disseminations->sharedOnly($q, $user));

        $threats = $query()->withCoordinates()->with(['threatType', 'lga', 'state'])->byUrgency()->paginate(25)->withQueryString();
        $points = $query()->withCoordinates()->with(['threatType', 'lga', 'state'])->whereNotNull('threat_reports.location')
            ->byUrgency()->limit((int) config('buffalo.threats.map_cap'))->get();

        $shared = $disseminations->sharedMarkers([...$threats->items(), ...$points->all()], $user);
        $row = fn (ThreatReport $t) => [...ThreatReportPresenter::row($t), 'shared' => isset($shared[$t->id])];

        return Inertia::render('threats/index', [
            'threats' => $threats->through($row),
            'points' => $points->map($row)->values(),
            'filters' => [
                'status' => $status,
                'threat_type' => $filters['threat_type'] ?? '',
                'credibility' => $filters['credibility'] ?? '',
                'urgency' => $filters['urgency'] ?? '',
                'state' => $filters['state'] ?? '',
                'lga' => $filters['lga'] ?? '',
                'expiring' => $filters['expiring'] ?? '',
                'shared' => $filters['shared'] ?? '',
            ],
            'options' => ThreatReportPresenter::options(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'lgas' => $lgas->count() > 1 ? $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values() : [],
            'map' => MapView::for($lga ?? $state ?? $user->lga ?? $user->state),
            'can' => ['create' => $user->can('create', ThreatReport::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', ThreatReport::class);

        return $this->form($this->actor($request), $options, null, $request->query('lga'));
    }

    public function store(ThreatReportRequest $request, CreateThreatReport $create): RedirectResponse
    {
        $this->authorize('create', ThreatReport::class);
        $user = $this->actor($request);

        foreach ($request->flashpoints() as $flashpoint) {
            $this->authorize('link', $flashpoint);
        }
        foreach ($request->groups() as $row) {
            $this->authorize('view', $row['group']);
        }

        /** @var Lga $lga */
        $lga = $request->lga();
        $threat = $create($lga, $request->values(), $request->flashpoints(), $request->groups(), $user);

        return redirect()->route('threats.show', $threat)
            ->with('toast', ['type' => 'success', 'message' => "Threat report {$threat->reference_no} recorded."])
            ->with('dissemination_prompt', true);
    }

    public function show(Request $request, ThreatReport $threat, JurisdictionGate $gate, DisseminationPresenter $disseminations): Response
    {
        $this->authorize('view', $threat);
        $user = $this->actor($request);
        // Seen only through a dissemination (Phase 7A): read-only, always logged.
        $shared = $gate->viaGrantOnly($user, $threat);
        $tabs = $shared ? array_values(array_diff(self::TABS, ['history'])) : self::TABS;
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : 'overview';

        if ($shared) {
            $threat->recordAccess(context: 'dissemination');
        } elseif ($threat->logsViews()) {
            $threat->recordAccess(context: "tab:{$tab}");
        }

        Inertia::encryptHistory();

        $row = ThreatReport::query()->withCoordinates()->whereKey($threat->id)->firstOrFail();
        $row->load(['threatType', 'lga', 'state', 'community']);

        return Inertia::render('threats/show', [
            'threat' => ThreatReportPresenter::detail($row),
            'tab' => $tab,
            'map' => MapView::for($row->lga),
            'links' => $tab === 'links' || $tab === 'overview' ? [
                'flashpoints' => $user->can('viewAny', Flashpoint::class) ? ThreatLinks::flashpoints($threat) : [],
                'groups' => $user->can('viewAny', CriminalGroup::class) ? ThreatLinks::groups($threat) : [],
                'incidents' => $user->can('viewAny', Incident::class) ? ThreatLinks::incidents($threat) : [],
            ] : null,
            'timeline' => $tab === 'timeline' ? ThreatReportPresenter::timeline($threat) : null,
            'history' => $tab === 'history' && ! $shared
                ? Activity::query()->with(['causer', 'subject'])
                    ->where('subject_type', $threat->getMorphClass())->where('subject_id', $threat->id)
                    ->latest('id')
                    ->paginate(25, pageName: 'history_page')
                    ->withQueryString()
                    ->through(fn (Activity $a) => ActivityPresenter::present($a))
                : null,
            'options' => ThreatReportPresenter::options(),
            // Recipients of a dissemination cannot join the owning state's
            // channel: they listen on their own (for the end of sharing).
            'channel' => $shared ? 'users.'.$user->ulid : 'states.'.$row->state->ulid.'.threats',
            ...$disseminations->forPage($threat, $user, (bool) $request->session()->get('dissemination_prompt')),
            'can' => [
                'update' => $user->can('update', $threat),
                'reactivate' => $user->can('reactivate', $threat),
                'delete' => $user->can('delete', $threat),
                'linkFlashpoints' => $user->can('viewAny', Flashpoint::class),
                'linkGroups' => $user->can('viewAny', CriminalGroup::class),
                'linkIncidents' => $user->can('viewAny', Incident::class),
            ],
        ]);
    }

    public function edit(Request $request, ThreatReport $threat, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $threat);

        return $this->form($this->actor($request), $options, $threat, $request->query('lga'));
    }

    public function update(ThreatReportRequest $request, ThreatReport $threat, UpdateThreatReport $update): RedirectResponse
    {
        $this->authorize('update', $threat);

        $update($threat, $request->lga(), $request->values(), $request->comment(), $this->actor($request));

        return redirect()->route('threats.show', $threat)->with('toast', ['type' => 'success', 'message' => 'Threat report saved.']);
    }

    public function destroy(DeleteRecordRequest $request, ThreatReport $threat, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $threat);

        $reference = $threat->reference_no;
        $delete($threat, 'threat_report', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('threats.index')->with('toast', ['type' => 'success', 'message' => "{$reference} deleted."]);
    }

    private function form(User $user, JurisdictionOptions $options, ?ThreatReport $threat, mixed $lgaUlid): Response
    {
        $row = $threat === null ? null : ThreatReport::query()->withCoordinates()->whereKey($threat->id)->firstOrFail();
        $row?->load(['threatType', 'lga', 'state', 'community']);
        $lgas = $options->lgas($user)->load('state:id,ulid,name');
        $lga = (is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : null)
            ?? ($row !== null ? $row->lga : null)
            ?? ($lgas->count() === 1 ? $lgas->first() : null);

        return Inertia::render('threats/form', [
            'threat' => $row === null ? null : ThreatReportPresenter::detail($row),
            'lga' => $lga === null ? null : ['ulid' => $lga->ulid, 'name' => $lga->name],
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => "{$l->name} ({$l->state->name})"])->values(),
            'communities' => $lga === null ? [] : Community::query()->where('lga_id', $lga->id)->orderBy('name')->get(['ulid', 'name'])->map(fn (Community $c) => ['value' => $c->ulid, 'label' => $c->name]),
            'options' => ThreatReportPresenter::options($row?->threatType),
            'map' => MapView::for($lga),
            'can' => [
                'linkFlashpoints' => $user->can('viewAny', Flashpoint::class),
                'linkGroups' => $user->can('viewAny', CriminalGroup::class),
            ],
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
