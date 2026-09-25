import type { ReactNode } from 'react';

/** Dashboard title, the jurisdiction it covers, and optional details. */
export default function DashboardHeader({
    title,
    subtitle,
    children,
}: {
    title: string;
    subtitle?: string;
    children?: ReactNode;
}) {
    return (
        <div className="space-y-1">
            <h1 className="text-xl font-semibold">{title}</h1>
            {subtitle && (
                <p className="text-sm text-muted-foreground">{subtitle}</p>
            )}
            {children}
        </div>
    );
}
