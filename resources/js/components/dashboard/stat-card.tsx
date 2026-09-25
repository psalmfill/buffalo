import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A single figure with a label, optionally linking to its detail. */
export default function StatCard({
    label,
    value,
    hint,
    href,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    hint?: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    tone?: 'default' | 'warning' | 'danger' | 'success';
}) {
    const body = (
        <>
            <div className="text-sm text-muted-foreground">{label}</div>
            <div
                className={cn('mt-1 text-2xl font-semibold tabular-nums', {
                    'text-amber-600 dark:text-amber-400': tone === 'warning',
                    'text-red-600 dark:text-red-400': tone === 'danger',
                    'text-emerald-600 dark:text-emerald-400':
                        tone === 'success',
                })}
            >
                {value}
            </div>
            {hint && (
                <div className="mt-1 text-xs text-muted-foreground">{hint}</div>
            )}
        </>
    );

    const className = 'block rounded-xl border bg-card p-4';

    return href ? (
        <Link href={href} className={cn(className, 'hover:bg-muted/40')}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}
