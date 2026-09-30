import { useEcho } from '@laravel/echo-react';
import { useEffect } from 'react';
import { useCurrentUser } from '@/hooks/use-auth';

type Props = {
    grantUlid: string;
    expiresAt: string;
    onEnded: () => void;
};

/**
 * Ends a page shown under a cross-jurisdiction grant (Phase 4A.0): on the
 * `access-grant.ended` broadcast for this grant (revoked or expired), and at
 * the expiry time even if no broadcast arrives. Renders nothing.
 */
export default function GrantEndedWatcher({
    grantUlid,
    expiresAt,
    onEnded,
}: Props) {
    const userUlid = useCurrentUser().ulid;

    useEcho<{ grant: string }>(
        `users.${userUlid}`,
        '.access-grant.ended',
        (payload) => {
            if (payload.grant === grantUlid) {
                onEnded();
            }
        },
        [grantUlid, onEnded],
    );

    useEffect(() => {
        const remaining = new Date(expiresAt).getTime() - Date.now();

        if (remaining <= 0) {
            onEnded();

            return;
        }

        // setTimeout overflows beyond ~24.8 days (grants last up to 30);
        // a page open that long relies on the broadcast instead.
        if (remaining > 2_000_000_000) {
            return;
        }

        const timer = window.setTimeout(onEnded, remaining);

        return () => window.clearTimeout(timer);
    }, [expiresAt, onEnded]);

    return null;
}
