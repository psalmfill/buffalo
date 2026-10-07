import { Head, Link } from '@inertiajs/react';
import { Check, Copy, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import OnboardingController from '@/actions/App/Http/Controllers/Users/OnboardingController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { HttpError, postJson } from '@/lib/http';
import type { OnboardingUser } from '@/types/onboarding';
import { STAGE_LABELS } from '@/types/onboarding';

/**
 * Handover session (Phase 9B.2): reveal one user's setup link at a time,
 * for in-person or phone handover. Each reveal issues a new one-time link
 * (any earlier link stops working) and is recorded in the audit log. The
 * link is held only in this page's memory and is never stored or exported.
 */
export default function Handover({ users }: { users: OnboardingUser[] }) {
    const [revealed, setRevealed] = useState<{
        ulid: string;
        url: string;
    } | null>(null);
    const [handedOver, setHandedOver] = useState<string[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [copied, copy] = useClipboard();

    const reveal = async (user: OnboardingUser) => {
        setBusy(user.ulid);
        setError(null);
        setRevealed(null);

        try {
            const result = await postJson<{ url: string }>(
                UserController.issueSetupLink(user.ulid).url,
                { delivery: 'handover' },
            );
            setRevealed({ ulid: user.ulid, url: result.url });
        } catch (e) {
            setError(
                e instanceof HttpError
                    ? e.message
                    : 'The link could not be issued.',
            );
        } finally {
            setBusy(null);
        }
    };

    const done = (ulid: string) => {
        setRevealed(null);
        setHandedOver((current) => [...current, ulid]);
    };

    return (
        <>
            <Head title="Handover session" />
            <div className="space-y-6 p-4">
                <Heading
                    help={{ topic: 'roles/super-admin', section: 'accounts' }}
                    title="Handover session"
                    description="Reveal each officer's setup link when they are with you (or on the phone), let them copy or type it, then hide it. Only one link is shown at a time, and each reveal is recorded."
                />

                {error && (
                    <p className="text-sm text-destructive" role="alert">
                        {error}
                    </p>
                )}

                <ul
                    className="divide-y rounded-lg border"
                    data-test="handover-list"
                >
                    {users.map((user) => (
                        <li key={user.ulid} className="space-y-3 p-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <div className="font-medium">
                                        {user.name}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {user.email} · {user.role} ·{' '}
                                        {user.place}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {handedOver.includes(user.ulid) ? (
                                        <Badge>Handed over</Badge>
                                    ) : (
                                        <Badge variant="outline">
                                            {STAGE_LABELS[user.stage]}
                                        </Badge>
                                    )}
                                    {revealed?.ulid !== user.ulid && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => reveal(user)}
                                            disabled={busy !== null}
                                            data-test={`reveal-${user.ulid}`}
                                        >
                                            <Eye /> Reveal link
                                        </Button>
                                    )}
                                </div>
                            </div>

                            {revealed?.ulid === user.ulid && (
                                <div className="space-y-2 rounded-md bg-muted/40 p-3">
                                    <div className="flex gap-2">
                                        <Input
                                            readOnly
                                            value={revealed.url}
                                            className="font-mono text-xs"
                                            onFocus={(e) => e.target.select()}
                                            aria-label="Setup link"
                                        />
                                        <Button
                                            variant="outline"
                                            onClick={() => copy(revealed.url)}
                                        >
                                            {copied ? <Check /> : <Copy />}
                                            {copied ? 'Copied' : 'Copy'}
                                        </Button>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Single use, valid for a limited time.
                                        Revealing it again issues a new link and
                                        cancels this one.
                                    </p>
                                    <Button
                                        size="sm"
                                        onClick={() => done(user.ulid)}
                                    >
                                        <EyeOff /> Hide link
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>

                <Button variant="outline" asChild>
                    <Link href={OnboardingController.index()}>End session</Link>
                </Button>
            </div>
        </>
    );
}

Handover.layout = {
    breadcrumbs: [
        { title: 'Users', href: UserController.index() },
        { title: 'Onboarding', href: OnboardingController.index() },
        { title: 'Handover session', href: OnboardingController.handover() },
    ],
};
