import { router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import { useCurrentUser } from '@/hooks/use-auth';

type Props = {
    /** Every active dissemination through which the page is seen. */
    active: string[];
    /** The latest expiry among them. */
    expiresAt: string;
};

/**
 * Closes a page seen through a dissemination (Phase 7A) as soon as the last
 * one reaching the viewer ends: on the `dissemination.ended` broadcast
 * (revoked or expired), and at the expiry time even without one. Renders
 * nothing.
 */
export default function DisseminationEndedWatcher({
    active,
    expiresAt,
}: Props) {
    const userUlid = useCurrentUser().ulid;
    const remaining = useRef(new Set(active));

    useEffect(() => {
        remaining.current = new Set(active);
    }, [active]);

    const close = () => {
        toast.info('This record is no longer shared with you.');
        router.visit(DisseminationController.index());
    };

    useEcho<{ dissemination: string }>(
        `users.${userUlid}`,
        '.dissemination.ended',
        (payload) => {
            if (!remaining.current.has(payload.dissemination)) {
                return;
            }

            remaining.current.delete(payload.dissemination);

            if (remaining.current.size === 0) {
                close();
            }
        },
        [],
    );

    useEffect(() => {
        const left = new Date(expiresAt).getTime() - Date.now();

        // setTimeout overflows beyond ~24.8 days; long-open pages rely on the broadcast.
        if (left > 2_000_000_000) {
            return;
        }

        const timer = window.setTimeout(close, Math.max(left, 0));

        return () => window.clearTimeout(timer);
    }, [expiresAt]);

    return null;
}
