import { NavMain } from '@/components/nav-main';
import { Sidebar, SidebarContent } from '@/components/ui/sidebar';
import { visibleNavigation } from '@/config/navigation';
import { useAuth } from '@/hooks/use-auth';

/**
 * Configuration-driven sidebar (resources/js/config/navigation.ts): items
 * are shown by permission. Every route is authorised on the server.
 */
export function AppSidebar() {
    const { permissions } = useAuth();

    return (
        <Sidebar
            collapsible="icon"
            variant="inset"
            // Start below the system banner, if any.
            className="top-(--banner-h) h-[calc(100svh-var(--banner-h))]"
        >
            <SidebarContent className="pt-2">
                <NavMain sections={visibleNavigation(permissions)} />
            </SidebarContent>
        </Sidebar>
    );
}
