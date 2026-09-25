<?php

namespace App\Actions\Dashboard;

use App\Enums\LoginEvent;
use App\Enums\Role;
use App\Http\Presenters\ActivityPresenter;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\Dashboard\QueueHealth;
use App\Support\Users\UserStatusFilter;
use Spatie\Activitylog\Models\Activity;

/**
 * Super admin dashboard: system administration only, no intelligence
 * content. User counts go through the jurisdiction scope (a super admin
 * sees all users).
 */
final class BuildSystemDashboard
{
    public function __construct(private readonly QueueHealth $queue) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $admin): array
    {
        $byRole = array_map(fn (Role $role): array => [
            'role' => $role->value,
            'label' => $role->label(),
            'count' => User::query()->role($role->value)->count(),
        ], Role::cases());

        $byRole[] = ['role' => null, 'label' => 'No role', 'count' => User::query()->doesntHave('roles')->count()];

        $byStatus = collect(['active', 'inactive', 'locked', 'setup_pending'])
            ->mapWithKeys(fn (string $status): array => [$status => UserStatusFilter::apply(User::query(), $status)->count()])
            ->all();

        return [
            'usersByRole' => $byRole,
            'usersByStatus' => $byStatus,
            'failedLogins24h' => LoginHistory::query()
                ->whereIn('event', [LoginEvent::LoginFailed->value, LoginEvent::TwoFactorFailed->value])
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'lockouts7d' => LoginHistory::query()
                ->where('event', LoginEvent::Locked->value)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            // Recent audit events only for holders of the audit viewer gate.
            'recentAudit' => $admin->can('viewAudit')
                ? Activity::query()->with(['causer', 'subject'])->latest('id')->limit(10)->get()
                    ->map(fn (Activity $activity): array => ActivityPresenter::present($activity))->all()
                : null,
            'queueStatus' => $this->queue->status(),
        ];
    }
}
