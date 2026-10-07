import type { ReactNode } from 'react';
import HelpLink from '@/components/help-link';

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
            <h1 className="flex items-center gap-1 text-xl font-semibold">
                {title}
                <HelpLink
                    topic="start/getting-started"
                    section="the-dashboard"
                />
            </h1>
            {subtitle && (
                <p className="text-sm text-muted-foreground">{subtitle}</p>
            )}
            {children}
        </div>
    );
}
