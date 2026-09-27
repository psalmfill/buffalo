import { Link } from '@inertiajs/react';
import AccessRequestController from '@/actions/App/Http/Controllers/AccessRequests/AccessRequestController';
import StatCard from '@/components/dashboard/stat-card';

export type AccessFiguresData = {
    mine?: {
        openRequests: number;
        activeGrants: { state: string; expires_at: string; request: string }[];
    };
    owner?: { pending: number; urgent: number; activeGrants: number };
    oversight?: {
        escalated: number;
        hitsToday: number;
        suppressedToday: number;
        overSoftLimit: { name: string; hits: number }[];
    };
};

/** Cross-jurisdiction access figures (Phase 3C): counts and dates only. */
export default function AccessFigures({ data }: { data: AccessFiguresData }) {
    const tab = (name: string) =>
        AccessRequestController.index({ query: { tab: name } });

    return (
        <section className="space-y-3" aria-label="Cross-jurisdiction access">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {data.owner && (
                    <>
                        <StatCard
                            label="Access requests awaiting decision"
                            value={data.owner.pending}
                            hint={
                                data.owner.urgent > 0
                                    ? `${data.owner.urgent} urgent`
                                    : undefined
                            }
                            tone={
                                data.owner.urgent > 0
                                    ? 'danger'
                                    : data.owner.pending > 0
                                      ? 'warning'
                                      : 'default'
                            }
                            href={tab('decide')}
                        />
                        <StatCard
                            label="Active grants on our records"
                            value={data.owner.activeGrants}
                            href={tab('grants')}
                        />
                    </>
                )}
                {data.mine && (
                    <>
                        <StatCard
                            label="My open access requests"
                            value={data.mine.openRequests}
                            href={tab('mine')}
                        />
                        <StatCard
                            label="My active grants"
                            value={data.mine.activeGrants.length}
                            href={tab('grants')}
                        />
                    </>
                )}
                {data.oversight && (
                    <>
                        <StatCard
                            label="Escalated access requests"
                            value={data.oversight.escalated}
                            tone={
                                data.oversight.escalated > 0
                                    ? 'warning'
                                    : 'default'
                            }
                            href={tab('escalated')}
                        />
                        <StatCard
                            label="Cross-jurisdiction hits today"
                            value={data.oversight.hitsToday}
                            hint={`${data.oversight.suppressedToday} suppressed`}
                            href={tab('oversight')}
                        />
                        <StatCard
                            label="Officers over the soft limit today"
                            value={data.oversight.overSoftLimit.length}
                            tone={
                                data.oversight.overSoftLimit.length > 0
                                    ? 'warning'
                                    : 'default'
                            }
                            href={tab('oversight')}
                        />
                    </>
                )}
            </div>
            {data.mine && data.mine.activeGrants.length > 0 && (
                <ul className="divide-y rounded-lg border text-sm">
                    {data.mine.activeGrants.map((g) => (
                        <li
                            key={g.request}
                            className="flex justify-between px-3 py-2"
                        >
                            <Link
                                href={AccessRequestController.show(g.request)}
                                className="hover:underline"
                            >
                                Record of {g.state} State
                            </Link>
                            <span className="text-muted-foreground">
                                Expires{' '}
                                {new Date(g.expires_at).toLocaleString('en-NG')}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {data.oversight && data.oversight.overSoftLimit.length > 0 && (
                <p className="text-sm text-muted-foreground">
                    Over the soft limit:{' '}
                    {data.oversight.overSoftLimit
                        .map((o) => `${o.name} (${o.hits})`)
                        .join(', ')}
                </p>
            )}
        </section>
    );
}
