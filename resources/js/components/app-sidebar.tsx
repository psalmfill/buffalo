import { Link, usePage } from '@inertiajs/react';
import { History, LayoutGrid, ScrollText, Users } from 'lucide-react';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import LoginHistoryController from '@/actions/App/Http/Controllers/Audit/LoginHistoryController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

/**
 * Minimal permission-based links. Every route is authorised on the server;
 * these flags only decide which links are shown. Role-aware navigation is
 * Phase 1E.
 */
function useMainNavItems(): NavItem[] {
    const { can } = usePage().props.auth;

    return [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        ...(can.viewUsers
            ? [{ title: 'Users', href: UserController.index(), icon: Users }]
            : []),
        ...(can.viewAudit
            ? [
                  {
                      title: 'Audit log',
                      href: AuditLogController.index(),
                      icon: ScrollText,
                  },
              ]
            : []),
        ...(can.viewLoginHistory
            ? [
                  {
                      title: 'Login history',
                      href: LoginHistoryController.index(),
                      icon: History,
                  },
              ]
            : []),
    ];
}

// External links (starter kit repository and docs) removed: BUFFALO makes no
// requests to, and links nowhere on, external hosts.
const footerNavItems: NavItem[] = [];

export function AppSidebar() {
    const mainNavItems = useMainNavItems();

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
