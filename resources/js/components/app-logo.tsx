import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

/** BUFFALO emblem (placeholder shield) and name. */
export default function AppLogo() {
    const { name } = usePage().props.app;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <AppLogoIcon className="size-5 fill-current text-white dark:text-black" />
            </div>
            <span className="ml-1 hidden text-sm font-semibold tracking-[0.2em] sm:inline">
                {name}
            </span>
        </>
    );
}
