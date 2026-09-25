<?php

namespace App\Http\Controllers\Audit;

use App\Enums\LoginEvent;
use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only login history viewer (login_history.view with system or
 * national reach). Rows from an IP address with repeated failures in the
 * last 24 hours are flagged.
 */
class LoginHistoryController extends Controller
{
    public const PER_PAGE = 25;

    /** Failures from one IP within 24 hours that count as "repeated". */
    public const REPEATED_FAILURES = 3;

    private const FAILURE_EVENTS = ['login_failed', 'two_factor_failed', 'locked'];

    public function index(Request $request): Response
    {
        $this->authorize('viewLoginHistory');

        $filters = $request->validate([
            'user' => ['nullable', 'string', 'max:26'],
            'email' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', Rule::enum(LoginEvent::class)],
            'ip' => ['nullable', 'string', 'max:45'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = isset($filters['user']) ? User::query()->withTrashed()->where('ulid', $filters['user'])->first() : null;

        $suspiciousIps = DB::table('login_histories')
            ->whereIn('event', self::FAILURE_EVENTS)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->havingRaw('count(*) >= ?', [self::REPEATED_FAILURES])
            ->pluck('ip_address')
            ->all();

        $entries = LoginHistory::query()
            ->with('user')
            ->when($user, fn (Builder $q) => $q->where('user_id', $user?->id))
            ->when(isset($filters['user']) && $user === null, fn (Builder $q) => $q->whereRaw('1 = 0'))
            ->when($filters['email'] ?? null, fn (Builder $q, string $email) => $q->where('email_attempted', 'ilike', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], strtolower($email)).'%'))
            ->when($filters['event'] ?? null, fn (Builder $q, string $event) => $q->where('event', $event))
            ->when($filters['ip'] ?? null, fn (Builder $q, string $ip) => $q->where('ip_address', $ip))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (LoginHistory $entry) => [
                'event' => $entry->event->value,
                'user' => $entry->user === null ? null : ['name' => $entry->user->name, 'ulid' => $entry->user->ulid],
                'email_attempted' => $entry->email_attempted,
                'ip_address' => $entry->ip_address,
                'user_agent' => $entry->user_agent,
                'created_at' => $entry->created_at?->toIso8601String(),
                'repeated_failures' => in_array($entry->event->value, self::FAILURE_EVENTS, true)
                    && in_array($entry->ip_address, $suspiciousIps, true),
            ]);

        return Inertia::render('audit/login-history', [
            'entries' => $entries,
            'filters' => [
                'user' => $filters['user'] ?? '',
                'email' => $filters['email'] ?? '',
                'event' => $filters['event'] ?? '',
                'ip' => $filters['ip'] ?? '',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'events' => array_map(fn (LoginEvent $e) => $e->value, LoginEvent::cases()),
            'users' => User::query()->orderBy('name')->get(['ulid', 'name'])->map(fn (User $u) => ['ulid' => $u->ulid, 'name' => $u->name])->values(),
            'suspiciousIps' => $suspiciousIps,
        ]);
    }
}
