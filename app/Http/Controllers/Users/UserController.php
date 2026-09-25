<?php

namespace App\Http\Controllers\Users;

use App\Actions\Auth\IssueAccountSetupLink;
use App\Actions\Auth\ResetTwoFactor;
use App\Actions\Auth\SendAccountSetupLink;
use App\Actions\Users\CreateUser;
use App\Actions\Users\DeactivateUser;
use App\Actions\Users\ReactivateUser;
use App\Actions\Users\UnlockUser;
use App\Actions\Users\UpdateUserJurisdiction;
use App\Actions\Users\UpdateUserProfile;
use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\SetupLinkDelivery;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\UserPresenter;
use App\Http\Requests\Users\ReasonRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserJurisdictionRequest;
use App\Http\Requests\Users\UpdateUserProfileRequest;
use App\Models\GeopoliticalZone;
use App\Models\LoginHistory;
use App\Models\State;
use App\Models\User;
use App\Support\Auth\MailConfiguration;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Users\UserStatusFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * User management. Every action is authorised through UserPolicy; queries
 * and route model binding are jurisdiction-scoped, so users outside the
 * viewer's jurisdiction do not exist for them (404).
 */
class UserController extends Controller
{
    public const PER_PAGE = 25;

    private const SORTABLE = ['name', 'email', 'service_number', 'rank', 'last_login_at', 'created_at'];

    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'state' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(UserStatusFilter::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';

        $users = User::query()
            ->with(['roles', 'state', 'lga', 'zones'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $q) => $q->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('service_number', 'ilike', $term));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->whereIn('state_id', State::query()->where('ulid', $state)->select('id')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => UserStatusFilter::apply($query, $status))
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user) => UserPresenter::row($user));

        $actor = $this->actor($request);
        $states = $actor->checkPermissionTo(Permission::UsersManage->value) || $actor->jurisdiction_level === JurisdictionLevel::National
            ? State::query()->active()->orderBy('name')->get()
            : $options->states($actor);

        return Inertia::render('users/index', [
            'users' => $users,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'role' => $filters['role'] ?? '',
                'state' => $filters['state'] ?? '',
                'status' => $filters['status'] ?? '',
                'sort' => $sort,
                'direction' => $direction,
            ],
            'roles' => $this->roleOptions(),
            'states' => $states->map(fn (State $state) => ['ulid' => $state->ulid, 'name' => $state->name])->values(),
            'can' => ['create' => $actor->can('create', User::class)],
        ]);
    }

    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/create', $this->jurisdictionFormOptions($this->actor($request), $options));
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        /** @var array{name: string, email: string, phone?: string|null, service_number?: string|null, rank?: string|null} $profile */
        $profile = $request->safe()->only(['name', 'email', 'phone', 'service_number', 'rank']);

        $user = $createUser(
            $profile,
            $request->jurisdiction(),
            $this->actor($request),
        );

        return redirect()
            ->route('users.show', $user)
            ->with('userCreated', true);
    }

    public function show(Request $request, User $user, JurisdictionOptions $options): Response
    {
        $this->authorize('view', $user);

        $actor = $this->actor($request);
        $user->load(['roles', 'state', 'lga', 'zones']);

        $canSeeLogins = $actor->can('viewLoginHistory');
        $canSeeActivity = $actor->can('viewAudit');
        $tabs = array_values(array_filter([
            'profile',
            'postings',
            $canSeeLogins ? 'logins' : null,
            $canSeeActivity ? 'activity' : null,
        ]));
        $tab = in_array($request->query('tab'), $tabs, true) ? (string) $request->query('tab') : 'profile';

        $canChangeRole = $actor->can('changeRole', $user);

        return Inertia::render('users/show', [
            'user' => UserPresenter::detail($user),
            'tab' => $tab,
            'tabs' => $tabs,
            'postings' => $tab === 'postings'
                ? $user->postings()->with(['state', 'lga'])->latest('started_at')->get()->map(fn ($p) => UserPresenter::posting($p))->values()
                : null,
            'logins' => $tab === 'logins'
                ? LoginHistory::query()->where('user_id', $user->id)->latest('id')->paginate(self::PER_PAGE, ['*'], 'page')->withQueryString()->through(fn (LoginHistory $entry) => [
                    'event' => $entry->event->value,
                    'email_attempted' => $entry->email_attempted,
                    'ip_address' => $entry->ip_address,
                    'user_agent' => $entry->user_agent,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])
                : null,
            'activity' => $tab === 'activity'
                ? Activity::query()
                    ->where(fn ($q) => $q->where(fn ($s) => $s->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id))
                        ->orWhere(fn ($c) => $c->where('causer_type', $user->getMorphClass())->where('causer_id', $user->id)))
                    ->with(['causer', 'subject'])
                    ->latest('id')
                    ->paginate(self::PER_PAGE, ['*'], 'page')
                    ->withQueryString()
                    ->through(fn (Activity $activity) => ActivityPresenter::present($activity))
                : null,
            'userCreated' => (bool) $request->session()->get('userCreated', false),
            'mailEnabled' => MailConfiguration::isConfigured(),
            'can' => [
                'update' => $actor->can('update', $user),
                'changeRole' => $canChangeRole,
                'deactivate' => $actor->can('deactivate', $user),
                'reactivate' => $actor->can('reactivate', $user),
                'unlock' => $actor->can('unlock', $user),
                'resetTwoFactor' => $actor->can('resetTwoFactor', $user),
                'issueSetupLink' => $actor->can('issueSetupLink', $user),
            ],
            'jurisdictionOptions' => $canChangeRole ? $this->jurisdictionFormOptions($actor, $options) : null,
        ]);
    }

    public function update(UpdateUserProfileRequest $request, User $user, UpdateUserProfile $update): RedirectResponse
    {
        /** @var array{name: string, email: string, phone?: string|null, service_number?: string|null, rank?: string|null} $data */
        $data = $request->validated();
        $update($user, $data, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Profile updated.']);
    }

    public function updateJurisdiction(UpdateUserJurisdictionRequest $request, User $user, UpdateUserJurisdiction $update): RedirectResponse
    {
        $update($user, $request->jurisdiction(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Role and jurisdiction updated.']);
    }

    public function deactivate(ReasonRequest $request, User $user, DeactivateUser $deactivate): RedirectResponse
    {
        $deactivate($user, $request->string('reason')->toString(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'User deactivated and signed out.']);
    }

    public function reactivate(ReasonRequest $request, User $user, ReactivateUser $reactivate): RedirectResponse
    {
        $reactivate($user, $request->string('reason')->toString(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'User reactivated.']);
    }

    public function unlock(Request $request, User $user, UnlockUser $unlock): RedirectResponse
    {
        $this->authorize('unlock', $user);
        $unlock($user, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Account unlocked.']);
    }

    public function resetTwoFactor(Request $request, User $user, ResetTwoFactor $reset): RedirectResponse
    {
        $this->authorize('resetTwoFactor', $user);
        $reset($user, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Two-factor authentication reset. The user was signed out.']);
    }

    /**
     * JSON only: a manually delivered link is returned once in this response
     * and never stored or shown again (it does not pass through the session).
     */
    public function issueSetupLink(Request $request, User $user, IssueAccountSetupLink $issue, SendAccountSetupLink $send): JsonResponse
    {
        $this->authorize('issueSetupLink', $user);

        $delivery = SetupLinkDelivery::from((string) $request->validate([
            'delivery' => ['required', Rule::enum(SetupLinkDelivery::class)],
        ])['delivery']);

        if ($delivery === SetupLinkDelivery::Email) {
            $send($user, $this->actor($request));

            return response()->json(['delivery' => 'email', 'sentTo' => $user->email]);
        }

        return response()
            ->json(['delivery' => 'manual', 'url' => $issue($user, $this->actor($request), SetupLinkDelivery::Manual)])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return list<array{value: string, label: string, level: string, allowsZones: bool}>
     */
    private function roleOptions(): array
    {
        return array_map(fn (Role $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'level' => $role->jurisdictionLevel()->value,
            'allowsZones' => $role->allowsZones(),
        ], Role::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function jurisdictionFormOptions(User $actor, JurisdictionOptions $options): array
    {
        return [
            'roles' => $this->roleOptions(),
            'zones' => GeopoliticalZone::query()->active()->orderBy('name')->get()->map(fn ($zone) => ['ulid' => $zone->ulid, 'name' => $zone->name])->values(),
            'states' => $options->assignableStates($actor)->map(fn ($state) => ['ulid' => $state->ulid, 'name' => $state->name])->values(),
            'lgas' => $options->assignableLgas($actor)->map(fn ($lga) => ['ulid' => $lga->ulid, 'name' => $lga->name, 'state' => $lga->state->ulid ?? null])->values(),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
