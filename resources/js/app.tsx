import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { initializeEcho } from '@/lib/echo';

initializeEcho();

const appName = import.meta.env.VITE_APP_NAME || 'BUFFALO';

void createInertiaApp({
    // "Page Name — BUFFALO"
    title: (title) => (title ? `${title} — ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('errors/'):
                // Error pages draw their own full-page layout: they are
                // shown to guests and signed-in users alike.
                return null;
            case name.endsWith('/print'):
                // Print views are bare pages (no app shell).
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
