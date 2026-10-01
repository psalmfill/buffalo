import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import EmptyState from '@/components/empty-state';
import { formatDateTime } from '@/lib/time';
import type { CrossBorderItem, SharedItem } from '@/types/disseminations';

function Panel({
    title,
    count,
    action,
    children,
}: {
    title: string;
    count: number;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card">
            <div className="flex items-center justify-between border-b px-4 py-3">
                <h2 className="text-sm font-medium">
                    {title}{' '}
                    <span className="text-muted-foreground">({count})</span>
                </h2>
                {action}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

const inboxLink = (
    <Link
        href={DisseminationController.index()}
        className="text-xs text-muted-foreground hover:underline"
    >
        All shared records
    </Link>
);

/** State and LGA dashboards (Phase 7A): records shared with us, not yet acknowledged. */
export function SharedWithUs({
    data,
}: {
    data: { count: number; rows: SharedItem[] };
}) {
    return (
        <Panel
            title="Shared with us, unacknowledged"
            count={data.count}
            action={inboxLink}
        >
            {data.rows.length === 0 ? (
                <EmptyState
                    title="Nothing waiting"
                    description="Every record shared with you has been acknowledged."
                />
            ) : (
                <ul
                    className="divide-y text-sm"
                    aria-label="Shared with us, unacknowledged"
                >
                    {data.rows.map((r) => (
                        <li
                            key={r.ulid}
                            className="flex flex-wrap items-baseline justify-between gap-2 py-2"
                        >
                            <Link
                                href={r.href}
                                className="font-medium hover:underline"
                            >
                                {r.kind} {r.label}
                            </Link>
                            <span className="text-xs text-muted-foreground">
                                {r.source} · {formatDateTime(r.disseminated_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/** National dashboard (Phase 7A): urgent records reaching places they have not been shared with. */
export function CrossBorderGaps({
    data,
}: {
    data: { count: number; rows: CrossBorderItem[] };
}) {
    return (
        <Panel title="Cross-border threats not disseminated" count={data.count}>
            {data.rows.length === 0 ? (
                <EmptyState
                    title="None"
                    description="Every urgent threat report and high-concern flashpoint reaching another state or LGA has been shared with it."
                />
            ) : (
                <ul
                    className="divide-y text-sm"
                    aria-label="Cross-border threats not disseminated"
                >
                    {data.rows.map((r) => (
                        <li key={r.href} className="space-y-0.5 py-2">
                            <Link
                                href={r.href}
                                className="font-medium hover:underline"
                            >
                                {r.kind} {r.label}
                            </Link>
                            {r.state && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {r.state} State
                                </span>
                            )}
                            <p className="text-xs text-muted-foreground">
                                Reaches {r.places.join(', ')}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}
