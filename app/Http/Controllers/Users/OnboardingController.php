<?php

namespace App\Http\Controllers\Users;

use App\Actions\Auth\SendAccountSetupLink;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\State;
use App\Models\User;
use App\Support\Auth\MailConfiguration;
use App\Support\Auth\StepUp;
use App\Support\Users\UserStatusFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issuing setup links in batches (Phase 9B.2), super admins:
 *
 * - by email (mail enabled): each selected user gets their own link;
 * - in a handover session: a page listing the selected users where the
 *   admin reveals one link at a time (UserController::issueSetupLink with
 *   delivery "handover"; each reveal is audited, shown once, never stored).
 *
 * Links are never listed, exported or written to files.
 */
class OnboardingController extends Controller
{
    public const MAX_SELECTION = 500;

    private const SESSION_KEY = 'onboarding.handover';

    public function index(Request $request): Response
    {
        $this->authorize('create', User::class);

        $filters = $request->validate([
            'state' => ['nullable', 'string', 'size:26'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::in(['setup_pending', 'no_2fa', 'all'])],
        ]);
        $status = $filters['status'] ?? 'setup_pending';

        $query = $this->users()
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->whereIn('state_id', State::query()->where('ulid', $state)->select('id')))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($status !== 'all', fn (Builder $query) => UserStatusFilter::apply($query, $status))
            ->when($status === 'no_2fa', fn (Builder $query) => $query->whereNotNull('password_changed_at'));

        $total = (clone $query)->count();

        return Inertia::render('users/onboarding', [
            'users' => $this->present($query->orderBy('name')->limit(self::MAX_SELECTION)->get()),
            'total' => $total,
            'limit' => self::MAX_SELECTION,
            'filters' => ['state' => $filters['state'] ?? '', 'role' => $filters['role'] ?? '', 'status' => $status],
            'states' => State::query()->orderBy('name')->get()->map(fn (State $state) => ['ulid' => $state->ulid, 'name' => $state->name])->values(),
            'roles' => array_map(fn (Role $role) => ['value' => $role->value, 'label' => $role->label()], Role::cases()),
            'mailEnabled' => MailConfiguration::isConfigured(),
        ]);
    }

    /** Emails each selected user their own setup link (step-up). */
    public function email(Request $request, SendAccountSetupLink $send): RedirectResponse
    {
        $this->authorize('create', User::class);
        $users = $this->selected($request);
        StepUp::ensure($request);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $sent = 0;
        foreach ($users as $user) {
            // Batches only reach accounts still waiting for setup: a link
            // to an account in use would amount to a password reset.
            if ($user->password_changed_at !== null || ! $actor->can('issueSetupLink', $user)) {
                continue;
            }
            $send($user, $actor);
            $sent++;
        }

        return back()->with('toast', ['type' => 'success', 'message' => sprintf(
            '%d setup link(s) emailed%s.', $sent, $sent < $users->count() ? ', '.($users->count() - $sent).' skipped (setup already done or not allowed)' : '',
        )]);
    }

    /** Starts a handover session for the selected users. */
    public function startHandover(Request $request): RedirectResponse
    {
        $this->authorize('create', User::class);
        $users = $this->selected($request);

        $request->session()->put(self::SESSION_KEY, $users->pluck('ulid')->all());

        return redirect()->route('users.onboarding.handover');
    }

    public function handover(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', User::class);

        /** @var list<string> $ulids */
        $ulids = (array) $request->session()->get(self::SESSION_KEY, []);

        if ($ulids === []) {
            return redirect()->route('users.onboarding');
        }

        $users = $this->users()->whereIn('ulid', $ulids)->orderBy('name')->get();

        return Inertia::render('users/handover', [
            'users' => $this->present($users),
        ]);
    }

    /**
     * @return Builder<User>
     */
    private function users(): Builder
    {
        return User::query()->where('is_active', true)->with(['roles', 'state', 'lga', 'zones']);
    }

    /**
     * @return Collection<int, User>
     */
    private function selected(Request $request): Collection
    {
        $ulids = $request->validate([
            'users' => ['required', 'array', 'min:1', 'max:'.self::MAX_SELECTION],
            'users.*' => ['string', 'size:26'],
        ])['users'];

        return $this->users()->whereIn('ulid', $ulids)->get();
    }

    /**
     * @param  iterable<User>  $users
     * @return list<array{ulid: string, name: string, email: string, role: string, place: string, stage: string}>
     */
    private function present(iterable $users): array
    {
        $collection = collect($users);
        $withLink = DB::table('account_setup_tokens')
            ->whereIn('user_id', $collection->pluck('id'))
            ->whereNull('used_at')->where('expires_at', '>', now())
            ->pluck('user_id')->flip();

        return array_values($collection->map(fn (User $user): array => [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRole()?->label() ?? '',
            'place' => $user->lga->name ?? $user->state->name ?? ($user->zones->isNotEmpty() ? $user->zones->pluck('name')->implode(', ') : 'National'),
            'stage' => match (true) {
                $user->two_factor_confirmed_at !== null => 'ready',
                $user->password_changed_at !== null => 'no_2fa',
                $withLink->has($user->id) => 'link_issued',
                default => 'no_link',
            },
        ])->all());
    }
}
