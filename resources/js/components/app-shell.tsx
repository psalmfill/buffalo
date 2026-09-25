import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';

export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage().props.sidebarOpen;

    return (
        <SidebarProvider
            defaultOpen={isOpen}
            // Full height below the system banner, if any.
            className="min-h-[calc(100svh-var(--banner-h))]"
        >
            {children}
        </SidebarProvider>
    );
}
