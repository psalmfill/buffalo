import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';

// Placeholder landing page. The starter kit's version linked to external
// sites; BUFFALO must work fully offline.
export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-screen flex-col items-center justify-center gap-8 bg-background p-6 text-foreground">
                <div className="flex flex-col items-center gap-4 text-center">
                    <AppLogoIcon className="size-12 fill-current" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {name}
                    </h1>
                    <p className="max-w-md text-sm text-muted-foreground">
                        Security Management and Intelligence Reporting System
                    </p>
                </div>

                <nav className="flex items-center gap-3">
                    {auth.user ? (
                        <Button asChild>
                            <Link href={dashboard()}>Dashboard</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild>
                                <Link href={login()}>Log in</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={register()}>Register</Link>
                            </Button>
                        </>
                    )}
                </nav>
            </div>
        </>
    );
}
