import { Head, Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import LoginHistoryController from '@/actions/App/Http/Controllers/Audit/LoginHistoryController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import StatCard from '@/components/dashboard/stat-card';
import ActivityTable from '@/components/users/activity-table';
import { useAuth } from '@/hooks/use-auth';
import { dashboard } from '@/routes';
import horizon from '@/routes/horizon';
import type { QueueStatus, SystemDashboardProps } from '@/types/dashboard';

const QUEUE_LABELS: Record<QueueStatus, string> = {
    running: 'Running',
    paused: 'Paused',
    inactive: 'Inactive',
};

const QUEUE_TONES = {
    running: 'success',
    paused: 'warning',
    inactive: 'danger',
} as const;

const STATUS_LABELS = {
    active: 'Active',
    inactive: 'Inactive',
    locked: 'Locked',
    setup_pending: 'Setup pending',
} as const;

/**
 * Super admin: system administration only. No map, no intelligence content.
 */
export default function SystemDashboard({
    usersByRole,
    usersByStatus,
    failedLogins24h,
    lockouts7d,
    recentAudit,
    queueStatus,
}: SystemDashboardProps) {
    const { can } = useAuth();

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader
                    title="System overview"
                    subtitle="Accounts, sign-in security and background services"
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <StatCard
                        label="Failed sign-ins (last 24 hours)"
                        value={failedLogins24h}
                        tone={failedLogins24h > 0 ? 'warning' : 'default'}
                        href={LoginHistoryController.index({
                            query: { event: 'login_failed' },
                        })}
                    />
                    <StatCard
                        label="Lockouts (last 7 days)"
                        value={lockouts7d}
                        tone={lockouts7d > 0 ? 'danger' : 'default'}
                        href={LoginHistoryController.index({
                            query: { event: 'locked' },
                        })}
                    />
                    <div className="rounded-xl border bg-card p-4">
                        <div className="text-sm text-muted-foreground">
                            Queue (Horizon)
                        </div>
                        <div
                            className={
                                {
                                    success:
                                        'mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400',
                                    warning:
                                        'mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400',
                                    danger: 'mt-1 text-2xl font-semibold text-red-600 dark:text-red-400',
                                }[QUEUE_TONES[queueStatus]]
                            }
                            data-test="queue-status"
                        >
                            {QUEUE_LABELS[queueStatus]}
                        </div>
                        {can('system.horizon') && (
                            <a
                                href={horizon.index().url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            >
                                Open queue monitor
                                <ExternalLink className="size-3" />
                            </a>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-3 text-sm font-medium">
                            Users by role
                        </h2>
                        <ul className="divide-y text-sm">
                            {usersByRole.map((row) => (
                                <li
                                    key={row.role ?? 'none'}
                                    className="flex items-center justify-between py-2"
                                >
                                    {row.role ? (
                                        <Link
                                            href={UserController.index({
                                                query: { role: row.role },
                                            })}
                                            className="hover:underline"
                                        >
                                            {row.label}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {row.label}
                                        </span>
                                    )}
                                    <span className="font-medium tabular-nums">
                                        {row.count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>

                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-3 text-sm font-medium">
                            Users by status
                        </h2>
                        <ul className="divide-y text-sm">
                            {(
                                Object.keys(STATUS_LABELS) as Array<
                                    keyof typeof STATUS_LABELS
                                >
                            ).map((status) => (
                                <li
                                    key={status}
                                    className="flex items-center justify-between py-2"
                                >
                                    <Link
                                        href={UserController.index({
                                            query: { status },
                                        })}
                                        className="hover:underline"
                                    >
                                        {STATUS_LABELS[status]}
                                    </Link>
                                    <span className="font-medium tabular-nums">
                                        {usersByStatus[status]}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                </div>

                {recentAudit !== null && (
                    <section className="space-y-3">
                        <h2 className="text-sm font-medium">
                            Recent audit events
                        </h2>
                        <ActivityTable entries={recentAudit} />
                    </section>
                )}
            </div>
        </>
    );
}

SystemDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
