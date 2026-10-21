<?php

namespace App\Http\Controllers\AccessRequests;

use App\Actions\AccessRequests\CancelAccessRequest;
use App\Actions\AccessRequests\DecideAccessRequest;
use App\Actions\AccessRequests\ManageDelegations;
use App\Actions\AccessRequests\RequestAccess;
use App\Actions\AccessRequests\RevokeGrant;
use App\Enums\AccessRequestStatus;
use App\Enums\GrantScope;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AccessRequestPresenter;
use App\Http\Requests\AccessRequests\AccessRequestRequest;
use App\Http\Requests\AccessRequests\ApproveRequest;
use App\Http\Requests\AccessRequests\DelegationRequest;
use App\Http\Requests\AccessRequests\RejectRequest;
use App\Http\Requests\AccessRequests\RevokeGrantRequest;
use App\Models\ApprovalDelegation;
use App\Models\Person;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\State;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Exports\Lists\Exporters\AccessGrantsExporter;
use App\Support\Exports\Lists\Exporters\AccessRequestsExporter;
use App\Support\Jurisdiction\AccessRequestApprovers;
use App\Support\Jurisdiction\AccessRequestParties;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Jurisdiction\OversightReach;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-jurisdiction access requests, grants and delegations (Phase 3C).
 *
 * Tabs by permission: my requests (officers), awaiting my decision (State
 * Directors, delegated deputies), escalated and oversight (DG, DDG),
 * grants, delegations (State Directors) and activity on the state's
 * records (State Directors, delegated deputies). Records are named only
 * when the viewer can see the person through their own scopes.
 */
class AccessRequestController extends Controller
{
    /** Rows per page in each oversight list (Phase 4A.0). */
    private const OVERSIGHT_PAGE = 25;

    public function __construct(private readonly AccessRequestApprovers $approvers) {}

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', RecordAccessRequest::class);
        $user = $this->actor($request);
        $tabs = $this->tabs($user);
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : $tabs[0];

        return Inertia::render('access-requests/index', [
            'tab' => $tab,
            'tabs' => $tabs,
            'mine' => $tab === 'mine' ? $this->mine($user) : null,
            'decide' => $tab === 'decide' ? $this->awaiting($user) : null,
            'escalated' => $tab === 'escalated' ? $this->escalated($user) : null,
            'grants' => $tab === 'grants' ? $this->grants($user) : null,
            'delegations' => $tab === 'delegations' ? $this->delegations($user) : null,
            'activity' => $tab === 'activity' ? $this->activity($user) : null,
            'oversight' => $tab === 'oversight' ? $this->oversight($request, $user, $options) : null,
            'isDelegatedDeputy' => $user->getRole() === Role::DeputyStateDirector && $user->state_id !== null && $this->approvers->activeDelegation($user, $user->state_id) !== null,
        ]);
    }

    /** Exchange a hit token for a request (the only way to create one). */
    public function store(AccessRequestRequest $request, RequestAccess $requestAccess): RedirectResponse
    {
        $this->authorize('create', RecordAccessRequest::class);

        $created = $requestAccess($this->actor($request), (string) $request->validated('token'), $request->values());

        return redirect()->route('access-requests.show', $created)
            ->with('toast', ['type' => 'success', 'message' => 'Access requested. The owning state has been notified.']);
    }

    public function show(Request $request, RecordAccessRequest $accessRequest): Response
    {
        $this->authorize('view', $accessRequest);
        $user = $this->actor($request);
        $accessRequest->load(['requesterState', 'requesterLga', 'owningState', 'matchEvent', 'grant']);
        AccessRequestParties::attach($accessRequest);

        // Named only if the viewer can see it (owner, oversight, or an active grant).
        $record = Person::query()->find($accessRequest->subject_id);
        $canDecide = $user->can('decide', $accessRequest);
        $isOwner = $user->id !== $accessRequest->requester_id;

        return Inertia::render('access-requests/show', [
            'request' => [
                ...AccessRequestPresenter::row($accessRequest, $record),
                'reason' => $accessRequest->reason,
                'reference' => $accessRequest->reference,
                'decision_note' => $accessRequest->decision_note,
                'decided_by' => $accessRequest->decider?->name,
                'related_incident' => AccessRequestParties::incidentLine($accessRequest),
            ],
            'requester' => $isOwner ? AccessRequestPresenter::requester($accessRequest->requester) : null,
            'history' => $isOwner ? $this->history($accessRequest->requester) : null,
            'options' => AccessRequestPresenter::options(),
            'can' => [
                'decide' => $canDecide,
                'cancel' => $user->can('cancel', $accessRequest),
                'revoke' => $accessRequest->grant !== null && $user->can('revoke', $accessRequest->grant),
            ],
        ]);
    }

    public function approve(ApproveRequest $request, RecordAccessRequest $accessRequest, DecideAccessRequest $decide): RedirectResponse
    {
        $this->authorize('decide', $accessRequest);
        // Granting cross-jurisdiction access needs a recent TOTP code (Phase 8B.2).
        StepUp::ensure($request);

        $note = $request->validated('note');
        $decide->approve(
            $accessRequest,
            $this->actor($request),
            (int) $request->validated('granted_days'),
            GrantScope::from((string) $request->validated('granted_scope')),
            is_string($note) ? $note : null,
        );

        return redirect()->route('access-requests.show', $accessRequest)->with('toast', ['type' => 'success', 'message' => 'Request approved. Temporary read-only access granted.']);
    }

    public function reject(RejectRequest $request, RecordAccessRequest $accessRequest, DecideAccessRequest $decide): RedirectResponse
    {
        $this->authorize('decide', $accessRequest);

        $decide->reject($accessRequest, $this->actor($request), (string) $request->validated('note'));

        return redirect()->route('access-requests.show', $accessRequest)->with('toast', ['type' => 'success', 'message' => 'Request rejected.']);
    }

    public function cancel(Request $request, RecordAccessRequest $accessRequest, CancelAccessRequest $cancel): RedirectResponse
    {
        $this->authorize('cancel', $accessRequest);

        $cancel($accessRequest, $this->actor($request));

        return redirect()->route('access-requests.index', ['tab' => 'mine'])->with('toast', ['type' => 'success', 'message' => 'Request cancelled.']);
    }

    public function revokeGrant(RevokeGrantRequest $request, RecordAccessGrant $grant, RevokeGrant $revoke): RedirectResponse
    {
        $this->authorize('revoke', $grant);

        $revoke($grant, $this->actor($request), (string) $request->validated('reason'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Access revoked.']);
    }

    public function storeDelegation(DelegationRequest $request, ManageDelegations $delegations): RedirectResponse
    {
        $this->authorize('create', ApprovalDelegation::class);

        /** @var User $deputy */
        $deputy = $request->deputy();
        $reason = $request->validated('reason');
        $delegations->create($this->actor($request), $deputy, $request->startsAt(), $request->endsAt(), is_string($reason) ? $reason : null);

        return redirect()->route('access-requests.index', ['tab' => 'delegations'])->with('toast', ['type' => 'success', 'message' => "Approval delegated to {$deputy->name}."]);
    }

    public function revokeDelegation(Request $request, ApprovalDelegation $delegation, ManageDelegations $delegations): RedirectResponse
    {
        $this->authorize('revoke', $delegation);

        $delegations->revoke($delegation, $this->actor($request));

        return redirect()->route('access-requests.index', ['tab' => 'delegations'])->with('toast', ['type' => 'success', 'message' => 'Delegation revoked.']);
    }

    /**
     * @return list<string>
     */
    private function tabs(User $user): array
    {
        $tabs = [];

        if ($user->checkPermissionTo(Permission::AccessRequestsCreate->value)) {
            $tabs[] = 'mine';
        }

        if ($user->checkPermissionTo(Permission::AccessRequestsDecide->value)) {
            $tabs[] = 'decide';
        }

        if ($user->checkPermissionTo(Permission::AccessRequestsOversee->value)) {
            $tabs[] = 'escalated';
        }

        $tabs[] = 'grants';

        if ($user->checkPermissionTo(Permission::DelegationsManage->value)) {
            $tabs[] = 'delegations';
        }

        if ($user->checkPermissionTo(Permission::AccessRequestsDecide->value)) {
            $tabs[] = 'activity';
        }

        if ($user->checkPermissionTo(Permission::AccessRequestsOversee->value)) {
            $tabs[] = 'oversight';
        }

        return $tabs;
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function mine(User $user): LengthAwarePaginator
    {
        $page = RecordAccessRequest::query()
            ->where('requester_id', $user->id)
            ->with(['owningState', 'requesterState', 'requesterLga', 'matchEvent', 'grant'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();
        AccessRequestParties::attach($page->items());

        // The record is named only while a grant makes it visible.
        $records = $this->visibleRecords(collect($page->items()));

        return $page->through(fn (RecordAccessRequest $r) => AccessRequestPresenter::row($r, $records->get($r->subject_id)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function awaiting(User $user): array
    {
        if ($user->state_id === null || ! $this->approvers->isStateApprover($user, $user->state_id)) {
            return [];
        }

        return $this->presentRequests(
            RecordAccessRequest::query()
                ->where('owning_state_id', $user->state_id)
                ->whereIn('status', AccessRequestStatus::openValues())
                ->orderByRaw("CASE urgency WHEN 'urgent' THEN 0 ELSE 1 END")
                ->oldest('id')
                ->get()
                ->filter(fn (RecordAccessRequest $r) => $this->approvers->canDecide($user, $r)),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function escalated(User $user): array
    {
        return $this->presentRequests(
            $this->inReach($user, RecordAccessRequest::query())
                ->where('status', AccessRequestStatus::Escalated->value)
                ->orderByRaw("CASE urgency WHEN 'urgent' THEN 0 ELSE 1 END")
                ->oldest('escalated_at')
                ->get(),
        );
    }

    /**
     * Grants I hold; grants on my state's records (approvers); grants in
     * my reach (oversight).
     *
     * @return array{held: list<array<string, mixed>>, issued: list<array<string, mixed>>}
     */
    private function grants(User $user): array
    {
        $held = RecordAccessGrant::query()->active()->where('user_id', $user->id)
            ->with(['request.owningState', 'request.requesterState', 'request.requesterLga'])
            ->orderBy('expires_at')->get();
        AccessRequestParties::attach($held);

        $issuedQuery = RecordAccessGrant::query()->active()->with(['request.owningState', 'request.requesterState', 'request.requesterLga']);

        if ($user->checkPermissionTo(Permission::AccessRequestsOversee->value)) {
            $issuedQuery->whereHas('request', fn (Builder $q) => $this->inReach($user, $q));
        } elseif ($user->state_id !== null && ($this->approvers->isStateApprover($user, $user->state_id) || $user->getRole() === Role::StateDirector)) {
            $issuedQuery->whereHas('request', fn (Builder $q) => $q->where('owning_state_id', $user->state_id));
        } else {
            $issuedQuery->whereRaw('1 = 0');
        }

        $issued = $issuedQuery->orderBy('expires_at')->get();
        AccessRequestParties::attach($issued);
        $records = $this->visibleRecords($held->map(fn (RecordAccessGrant $g) => $g->request)->merge($issued->map(fn (RecordAccessGrant $g) => $g->request)));

        $present = fn (RecordAccessGrant $g) => [
            ...AccessRequestPresenter::grant($g),
            'request' => AccessRequestPresenter::row($g->request, $records->get($g->subject_id)),
            'granted_by' => $g->granter->name,
            'can_revoke' => $user->can('revoke', $g),
        ];

        return [
            'held' => array_values($held->map($present)->all()),
            'issued' => array_values($issued->map($present)->all()),
        ];
    }

    /**
     * @return array{current: list<array<string, mixed>>, past: list<array<string, mixed>>, deputies: list<array{value: string, label: string}>}
     */
    private function delegations(User $user): array
    {
        $all = ApprovalDelegation::query()->where('state_id', $user->state_id)->with(['delegator', 'delegate'])->latest('id')->get();
        $present = fn (ApprovalDelegation $d) => [
            'ulid' => $d->ulid,
            'delegator' => $d->delegator->name,
            'delegate' => $d->delegate->name,
            'starts_at' => $d->starts_at->toIso8601String(),
            'ends_at' => $d->ends_at?->toIso8601String(),
            'reason' => $d->reason,
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'end_reason' => $d->end_reason,
            'active' => $d->isActive(),
            'can_revoke' => $user->can('revoke', $d),
        ];
        $open = fn (ApprovalDelegation $d) => $d->revoked_at === null && ($d->ends_at === null || $d->ends_at->isFuture());

        return [
            'current' => array_values($all->filter($open)->map($present)->all()),
            'past' => array_values($all->reject($open)->map($present)->all()),
            'deputies' => array_values(User::query()->role(Role::DeputyStateDirector->value)->where('state_id', $user->state_id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (User $u) => ['value' => $u->ulid, 'label' => $u->name])->all()),
        ];
    }

    /**
     * Hits on the state's records in the last 7 days (owners open the
     * records: they are theirs).
     *
     * @return list<array<string, mixed>>
     */
    private function activity(User $user): array
    {
        if ($user->state_id === null || ! $this->approvers->isStateApprover($user, $user->state_id)) {
            return [];
        }

        $events = RecordMatchEvent::query()->with(['owningState'])
            ->where('owning_state_id', $user->state_id)
            ->where('suppressed', false)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(7))
            ->latest('id')->limit(200)->get();
        AccessRequestParties::attach($events);
        $records = Person::query()->whereIn('id', $events->pluck('subject_id'))->get()->keyBy('id');

        return array_values($events->map(fn (RecordMatchEvent $e) => AccessRequestPresenter::event($e, $records->get($e->subject_id)))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function oversight(Request $request, User $user, JurisdictionOptions $options): array
    {
        $states = $options->states($user);
        $requestsList = app(AccessRequestsExporter::class);
        $filters = $requestsList->filters($request->query(), $user);
        $state = isset($filters['state']) ? $states->firstWhere('ulid', $filters['state']) : null;
        $officer = isset($filters['officer']) ? AccessRequestParties::byUlid((string) $filters['officer']) : null;
        [$from, $to] = AccessRequestsExporter::period($filters);

        // The same queries as the oversight exports (Phase 11E).
        $requests = $requestsList->query($user, $filters)
            ->with(['owningState', 'requesterState', 'requesterLga', 'matchEvent', 'grant'])
            ->paginate(self::OVERSIGHT_PAGE, ['*'], 'requests_page')->withQueryString();

        $events = $this->inReach($user, RecordMatchEvent::query())
            ->with(['owningState'])
            ->when($state, fn (Builder $q) => $q->where('owning_state_id', $state?->id))
            ->when($officer, fn (Builder $q) => $q->where('user_id', $officer?->id))
            ->whereBetween('created_at', [$from, $to])
            ->latest('id')->paginate(self::OVERSIGHT_PAGE, ['*'], 'events_page')->withQueryString();

        $grants = app(AccessGrantsExporter::class)->query($user, $filters)->with(['request.owningState', 'request.requesterState', 'request.requesterLga'])
            ->paginate(self::OVERSIGHT_PAGE, ['*'], 'grants_page')->withQueryString();
        AccessRequestParties::attach([...$requests->items(), ...$events->items(), ...$grants->items()]);

        $soft = (int) config('buffalo.cross_jurisdiction.soft_daily_limit', 20);
        $today = CarbonImmutable::today();
        $perOfficer = $this->inReach($user, RecordMatchEvent::query())
            ->when($state, fn (Builder $q) => $q->where('owning_state_id', $state?->id))
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id')
            ->selectRaw('user_id, count(*) FILTER (WHERE NOT suppressed) AS hits, count(*) FILTER (WHERE suppressed) AS suppressed, count(*) FILTER (WHERE NOT suppressed AND created_at >= ?) AS today', [$today])
            ->orderByDesc('hits')
            ->limit(50)
            ->toBase()->get();
        $officers = AccessRequestParties::users($perOfficer->pluck('user_id')->map(fn ($id) => (int) $id)->all());

        return [
            'filters' => [
                'state' => $filters['state'] ?? '',
                'officer' => $filters['officer'] ?? '',
                'status' => $filters['status'] ?? '',
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'states' => $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values(),
            'statuses' => array_map(fn (AccessRequestStatus $s) => AccessRequestPresenter::option($s), AccessRequestStatus::cases()),
            'requests' => $requests->through(fn (RecordAccessRequest $r) => AccessRequestPresenter::row($r)),
            'grants' => $grants->through(fn (RecordAccessGrant $g) => [
                ...AccessRequestPresenter::grant($g),
                'request' => AccessRequestPresenter::row($g->request),
                'granted_by' => $g->granter->name,
                'can_revoke' => $user->can('revoke', $g),
            ]),
            'events' => $events->through(fn (RecordMatchEvent $e) => AccessRequestPresenter::event($e)),
            'officers' => array_values($perOfficer->map(function (object $row) use ($officers, $soft): array {
                $officer = $officers->get($row->user_id);

                return [
                    'ulid' => $officer?->ulid,
                    'name' => $officer?->name,
                    'place' => $officer?->jurisdictionLabel(),
                    'hits' => (int) $row->hits,
                    'suppressed' => (int) $row->suppressed,
                    'today' => (int) $row->today,
                    'over_soft_limit' => (int) $row->today > $soft,
                ];
            })->all()),
            'softLimit' => $soft,
        ];
    }

    /**
     * Requester history for the decision screen: the last 90 days.
     *
     * @return array{total: int, approved: int, rejected: int, cancelled: int, pending: int}
     */
    private function history(User $requester): array
    {
        $counts = RecordAccessRequest::query()
            ->where('requester_id', $requester->id)
            ->where('created_at', '>=', CarbonImmutable::now()->subDays(90))
            ->groupBy('status')->selectRaw('status, count(*) AS total')->toBase()->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'approved' => (int) ($counts['approved'] ?? 0),
            'rejected' => (int) ($counts['rejected'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'pending' => (int) (($counts['pending'] ?? 0) + ($counts['escalated'] ?? 0)),
        ];
    }

    /**
     * Records the viewer can see (their scopes), by id.
     *
     * @param  Collection<int, RecordAccessRequest>  $requests
     * @return \Illuminate\Database\Eloquent\Collection<int, Person>
     */
    private function visibleRecords(Collection $requests): \Illuminate\Database\Eloquent\Collection
    {
        return Person::query()->whereIn('id', $requests->pluck('subject_id')->unique())->get()->keyBy('id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, RecordAccessRequest>  $requests
     * @return list<array<string, mixed>>
     */
    private function presentRequests(\Illuminate\Database\Eloquent\Collection $requests): array
    {
        $requests->load(['owningState', 'requesterState', 'requesterLga', 'matchEvent', 'grant']);
        AccessRequestParties::attach($requests);
        $records = $this->visibleRecords($requests);

        return array_values($requests->map(fn (RecordAccessRequest $r) => AccessRequestPresenter::row($r, $records->get($r->subject_id)))->all());
    }

    /**
     * Restricts a query of requests or match events to the owning states
     * within the user's national or zonal reach.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inReach(User $user, Builder $query): Builder
    {
        return OversightReach::apply($user, $query);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
