import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import EmptyState from '@/components/empty-state';

export type LinkRow = {
    key: string;
    href: string;
    title: string;
    /** e.g. reference, type and date. */
    meta: string;
    /** Shown under the meta line (e.g. the link note). */
    note?: string | null;
    action?: ReactNode;
};

/** Linked or suggested records on a flashpoint, each with one action. */
export default function LinkRows({
    rows,
    empty,
    label,
}: {
    rows: LinkRow[];
    empty: string;
    label: string;
}) {
    if (rows.length === 0) {
        return <EmptyState title={empty} />;
    }

    return (
        <ul className="divide-y rounded-lg border" aria-label={label}>
            {rows.map((row) => (
                <li
                    key={row.key}
                    className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
                >
                    <div className="min-w-0 space-y-0.5">
                        <Link
                            href={row.href}
                            className="text-sm font-medium hover:underline"
                        >
                            {row.title}
                        </Link>
                        <p className="text-xs text-muted-foreground">
                            {row.meta}
                        </p>
                        {row.note && (
                            <p className="text-xs whitespace-pre-line">
                                {row.note}
                            </p>
                        )}
                    </div>
                    {row.action}
                </li>
            ))}
        </ul>
    );
}
