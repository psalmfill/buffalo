import { router, useHttp, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { login, logout } from '@/routes';
import { ping } from '@/routes/session';

const WARNING_BEFORE_MS = 2 * 60 * 1000;

/**
 * Warns two minutes before the idle timeout (SESSION_LIFETIME). Every
 * request to the server resets the idle clock, so the timer restarts on
 * each Inertia visit. "Stay signed in" pings the server.
 */
export default function SessionIdleWarning() {
    const { session } = usePage().props as { session?: { lifetime: number } };
    const lifetimeMs = (session?.lifetime ?? 30) * 60 * 1000;
    const { submit } = useHttp();

    const [lastActivity, setLastActivity] = useState(() => Date.now());
    const [open, setOpen] = useState(false);
    const [secondsLeft, setSecondsLeft] = useState(0);
    const expiredRef = useRef(false);

    useEffect(() => router.on('finish', () => setLastActivity(Date.now())), []);

    useEffect(() => {
        const tick = () => {
            const remaining = lastActivity + lifetimeMs - Date.now();

            if (remaining <= 0 && !expiredRef.current) {
                expiredRef.current = true;
                window.location.assign(login().url);

                return;
            }

            setOpen(remaining <= WARNING_BEFORE_MS);
            setSecondsLeft(Math.max(0, Math.ceil(remaining / 1000)));
        };

        tick();
        const timer = window.setInterval(tick, 1000);

        return () => window.clearInterval(timer);
    }, [lastActivity, lifetimeMs]);

    const staySignedIn = useCallback(async () => {
        try {
            await submit(ping());
            setLastActivity(Date.now());
            setOpen(false);
        } catch {
            window.location.assign(login().url);
        }
    }, [submit]);

    const signOut = useCallback(() => {
        router.flushAll();
        router.post(logout().url);
    }, []);

    const minutes = Math.floor(secondsLeft / 60);
    const seconds = String(secondsLeft % 60).padStart(2, '0');

    return (
        <Dialog open={open} onOpenChange={() => undefined}>
            <DialogContent
                onEscapeKeyDown={(event) => event.preventDefault()}
                onPointerDownOutside={(event) => event.preventDefault()}
                className="[&>button:last-child]:hidden"
            >
                <DialogHeader>
                    <DialogTitle>Are you still there?</DialogTitle>
                    <DialogDescription>
                        For security you will be signed out after a period of
                        inactivity, in {minutes}:{seconds}.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="secondary" onClick={signOut}>
                        Sign out
                    </Button>
                    <Button
                        onClick={() => void staySignedIn()}
                        data-test="stay-signed-in"
                    >
                        Stay signed in
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
