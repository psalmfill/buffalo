<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own notifications. Every query goes through
 * $user->notifications(), so users can only ever see or change their own.
 */
class NotificationController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), ['unread', 'read'], true) ? $request->query('filter') : 'all';
        $user = $this->user($request);

        $notifications = $user->notifications()
            ->when($filter === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->when($filter === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DatabaseNotification $n) => self::present($n));

        return Inertia::render('notifications/index', [
            'notifications' => $notifications,
            'filter' => $filter,
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Latest 10 for the bell dropdown.
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json([
            'notifications' => $user->notifications()->limit(10)->get()->map(fn (DatabaseNotification $n) => self::present($n)),
            'unreadCount' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse|RedirectResponse
    {
        $record = $this->user($request)->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['url' => self::present($record)['url']])
            : back();
    }

    public function markAllRead(Request $request): JsonResponse|RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() && ! $request->header('X-Inertia')
            ? response()->json(['unreadCount' => 0])
            : back();
    }

    /**
     * @return array{id: string, type: string|null, title: string|null, body: string|null, url: string|null, severity: string, read_at: string|null, created_at: string|null}
     */
    public static function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $url = is_string($data['url'] ?? null) && str_starts_with($data['url'], '/') && ! str_starts_with($data['url'], '//') ? $data['url'] : null;

        return [
            'id' => (string) $notification->id,
            'type' => is_string($data['type'] ?? null) ? $data['type'] : null,
            'title' => is_string($data['title'] ?? null) ? $data['title'] : null,
            'body' => is_string($data['body'] ?? null) ? $data['body'] : null,
            'url' => $url,
            'severity' => in_array($data['severity'] ?? null, ['info', 'warning', 'critical'], true) ? (string) $data['severity'] : 'info',
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
