import { Link } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import NotificationBell from '@/components/notification-bell';
import GlobalSearch from '@/components/search/global-search';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { UserMenu } from '@/components/user-menu';
import { useCurrentUser } from '@/hooks/use-auth';
import { dashboard } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

/**
 * App header: BUFFALO name and logo, breadcrumbs, global search, the user's
 * jurisdiction, the notification bell and the user menu.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const user = useCurrentUser();

    return (
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-sidebar-border/50 px-4 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12">
            <SidebarTrigger className="-ml-1" />
            <Link
                href={dashboard()}
                className="flex items-center gap-1"
                aria-label="BUFFALO home"
            >
                <AppLogo />
            </Link>
            <div className="hidden min-w-0 border-l border-sidebar-border/70 pl-3 lg:block">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="ml-auto flex min-w-0 items-center gap-2">
                {/* Global search (Phase 7B): intelligence roles only (checked on the server too). */}
                {user.maxClassification !== null && <GlobalSearch />}
                <span
                    className="hidden max-w-72 items-center gap-1 truncate rounded-md border px-2 py-1 text-xs text-muted-foreground sm:inline-flex"
                    title={user.jurisdictionLabel}
                    data-test="jurisdiction-label"
                >
                    <MapPin className="size-3 shrink-0" />
                    <span className="truncate">{user.jurisdictionLabel}</span>
                </span>
                <NotificationBell />
                <UserMenu />
            </div>
        </header>
    );
}
