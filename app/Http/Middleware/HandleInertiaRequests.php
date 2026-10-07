<?php

namespace App\Http\Middleware;

use App\Enums\CoverageReportStatus;
use App\Enums\Permission;
use App\Enums\ThreatUrgency;
use App\Http\Presenters\CurrentUserPresenter;
use App\Models\CoverageReport;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Dashboard\DisseminationFigures;
use App\Support\Geo\BoundaryAttribution;
use App\Support\SystemBanner;
use App\Support\Uat\Uat;
use Illuminate\Http\Request;
use Inertia\Middleware;

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
