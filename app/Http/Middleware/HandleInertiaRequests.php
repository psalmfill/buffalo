<?php

namespace App\Http\Middleware;

use App\Enums\CoverageReportStatus;
use App\Enums\Permission;
use App\Enums\ThreatUrgency;
use App\Http\Presenters\CurrentUserPresenter;
use App\Models\CoverageReport;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Dashboard\DisseminationFigures;
use App\Support\Geo\BoundaryAttribution;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\StateLens;
use App\Support\SystemBanner;
use App\Support\Uat\Uat;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Controllers flash a toast with redirect()->with('toast', [...]).
     * Inertia 3 only sends its own flash data to the page (the client's
     * "flash" event, use-flash-toast.ts), so a session toast is handed on
     * as Inertia flash data for the page this request renders. Found by
     * the Phase 11 browser check: before this, those toasts never showed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $toast = $request->hasSession() ? $request->session()->get('toast') : null;

        if (is_array($toast) && isset($toast['type'], $toast['message'])) {
            Inertia::flash('toast', ['type' => (string) $toast['type'], 'message' => (string) $toast['message']]);
        }

        return parent::handle($request, $next);
    }

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'app' => self::appData(),
            // Never numeric ids (CLAUDE.md): CurrentUserPresenter shares
            // ULIDs and names only.
            'auth' => [
                'user' => fn (): ?array => $user instanceof User ? CurrentUserPresenter::present($user) : null,
                // For showing and hiding links only; every route is
                // authorised on the server.
                'permissions' => fn (): array => $user instanceof User
                    ? $user->getAllPermissions()->pluck('name')->sort()->values()->all()
                    : [],
            ],
            'notifications' => [
                // Unread count for the bell; kept live over Echo afterwards.
                'unreadCount' => fn (): int => $user instanceof User
                    ? $user->unreadNotifications()->count()
                    : 0,
            ],
            // Sidebar counts (Phase 4A): coverage reports the user may review.
            'badges' => [
                'coverageReview' => fn (): int => $user instanceof User && $user->checkPermissionTo(Permission::CoverageReview->value)
                    ? CoverageReport::query()->where('status', CoverageReportStatus::Submitted->value)->count()
                    : 0,
                // Active immediate threat reports in reach (Phase 6B), through their scopes.
                'immediateThreats' => fn (): int => $user instanceof User && $user->checkPermissionTo(Permission::ThreatsView->value)
                    ? ThreatReport::query()->active()->where('urgency', ThreatUrgency::Immediate->value)->count()
                    : 0,
                // Records shared with the user and not yet acknowledged (Phase 7A).
                'disseminations' => fn (): int => $user instanceof User
                    ? (app(DisseminationFigures::class)->unacknowledged($user)['count'] ?? 0)
                    : 0,
            ],
            // Attribution of the imported boundaries, shown on every map (Phase 6A).
            'boundaryAttribution' => fn (): ?string => $user instanceof User ? BoundaryAttribution::current() : null,
            // The state lens control (Phase 11A.2): national users only.
            'lens' => fn (): ?array => app(StateLens::class)->share($request),
            // States whose page the user may open (Phase 11F), so state names
            // shown as text can link to them. For links only; the state pages
            // are authorised on the server.
            'stateLinks' => fn (): array => $user instanceof User && $user->can('viewAny', State::class)
                ? app(JurisdictionOptions::class)->stateQuery($user)->active()->pluck('ulid')->all()
                : [],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Application data every page needs, including pages rendered outside
     * this middleware (error pages).
     *
     * @return array{name: string, banner: array{text: string, level: string}|null, sessionIdleMinutes: int, uat: bool}
     */
    public static function appData(): array
    {
        return [
            'name' => (string) config('app.name'),
            'banner' => SystemBanner::current(),
            // The UAT environment (Phase 9B.5): the feedback button.
            'uat' => Uat::enabled(),
            // Idle timeout, for the frontend idle warning.
            'sessionIdleMinutes' => (int) config('session.lifetime'),
        ];
    }
}
