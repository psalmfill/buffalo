import SessionIdleWarning from '@/components/session-idle-warning';
import UatFeedbackButton from '@/components/uat-feedback-button';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
            <SessionIdleWarning />
            <UatFeedbackButton />
        </AppLayoutTemplate>
    );
}
