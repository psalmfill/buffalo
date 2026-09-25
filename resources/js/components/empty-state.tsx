import { Inbox } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/** Shown in place of an empty table or list. */
export default function EmptyState({
    title,
    description,
    icon: Icon = Inbox,
    children,
}: {
    title: string;
    description?: string;
    icon?: LucideIcon;
    children?: ReactNode;
}) {
    return (
        <div
            className="flex flex-col items-center justify-center gap-2 px-4 py-10 text-center"
            data-test="empty-state"
        >
            <Icon className="size-8 text-muted-foreground/60" />
            <p className="text-sm font-medium">{title}</p>
            {description && (
                <p className="max-w-sm text-sm text-muted-foreground">
                    {description}
                </p>
            )}
            {children}
        </div>
    );
}
