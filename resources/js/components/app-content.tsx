import type * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';

export function AppContent({
    className,
    children,
    ...props
}: React.ComponentProps<'main'>) {
    return (
        <SidebarInset
            className={cn('min-h-[calc(100svh-var(--banner-h))]', className)}
            {...props}
        >
            {children}
        </SidebarInset>
    );
}
