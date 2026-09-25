import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { SystemBanner } from '@/components/system-banner';
import { Button } from '@/components/ui/button';
import { dashboard, home, login, logout } from '@/routes';

type ErrorStatus = 403 | 404 | 419 | 500 | 503;

// Say what happened, never why access was denied or whether a record exists.
const MESSAGES: Record<ErrorStatus, { title: string; description: string }> = {
    403: {
        title: 'Access denied',
        description: 'You do not have permission to view this page.',
    },
    404: {
        title: 'Page not found',
        description: 'The page you asked for could not be found.',
    },
    419: {
        title: 'Session expired',
        description: 'Your session expired, please sign in again.',
    },
    500: {
        title: 'Something went wrong',
        description:
            'The server could not complete your request. Please try again later.',
    },
    503: {
        title: 'Temporarily unavailable',
        description:
            'BUFFALO is undergoing maintenance. Please try again shortly.',
    },
};

export default function ErrorStatusPage({
    status,
    signedIn,
}: {
    status: ErrorStatus;
    signedIn: boolean;
}) {
    const message = MESSAGES[status];

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <Head title={message.title} />
            <SystemBanner />
            <main className="flex flex-1 flex-col items-center justify-center gap-6 p-6 text-center">
                <AppLogoIcon className="size-12 fill-current text-foreground" />
                <div className="space-y-2">
                    <p className="text-sm font-medium tracking-[0.2em] text-muted-foreground">
                        {status}
                    </p>
                    <h1 className="text-2xl font-semibold">{message.title}</h1>
                    <p className="max-w-md text-sm text-muted-foreground">
                        {message.description}
                    </p>
                </div>
                <div className="flex flex-wrap justify-center gap-2">
                    {status === 419 ? (
                        <Button asChild>
                            <a href={login().url}>Sign in</a>
                        </Button>
                    ) : !signedIn ? (
                        // Unknown URLs are answered before the session
                        // starts, so the user may be signed in: "/" sends
                        // them to the dashboard or to sign-in as appropriate.
                        <Button asChild>
                            <a href={home().url}>Continue to BUFFALO</a>
                        </Button>
                    ) : (
                        <>
                            <Button asChild>
                                <Link href={dashboard()}>Go to dashboard</Link>
                            </Button>
                            {status === 403 && (
                                <Button variant="outline" asChild>
                                    <Link href={logout()} as="button">
                                        Sign out
                                    </Link>
                                </Button>
                            )}
                        </>
                    )}
                </div>
            </main>
        </div>
    );
}
