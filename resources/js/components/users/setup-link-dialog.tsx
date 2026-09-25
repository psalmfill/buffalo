import { Check, Copy, Mail, Link2 } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useClipboard } from '@/hooks/use-clipboard';
import { HttpError, postJson } from '@/lib/http';

type Props = {
    userUlid: string;
    userEmail: string;
    mailEnabled: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    intro?: string;
};

type Result =
    { delivery: 'manual'; url: string } | { delivery: 'email'; sentTo: string };

/**
 * Issues a one-time account setup link: emailed to the user, or shown here
 * ONCE for manual handover. A manually delivered link is fetched as JSON and
 * held only in this component's memory.
 */
export default function SetupLinkDialog({
    userUlid,
    userEmail,
    mailEnabled,
    open,
    onOpenChange,
    intro,
}: Props) {
    const [result, setResult] = useState<Result | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [copied, copy] = useClipboard();

    const issue = async (delivery: 'email' | 'manual') => {
        setBusy(true);
        setError(null);

        try {
            setResult(
                await postJson<Result>(
                    UserController.issueSetupLink(userUlid).url,
                    { delivery },
                ),
            );
        } catch (e) {
            setError(
                e instanceof HttpError
                    ? e.message
                    : 'The link could not be issued.',
            );
        } finally {
            setBusy(false);
        }
    };

    const close = (next: boolean) => {
        if (!next) {
            setResult(null);
            setError(null);
        }

        onOpenChange(next);
    };

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Account setup link</DialogTitle>
                    <DialogDescription>
                        {intro ??
                            'A new one-time link lets the user choose a password and set up two-factor authentication. Any earlier unused link stops working. Links expire after 72 hours.'}
                    </DialogDescription>
                </DialogHeader>

                {result === null && (
                    <div className="grid gap-3">
                        <Button
                            onClick={() => void issue('email')}
                            disabled={!mailEnabled || busy}
                            data-test="setup-link-email"
                        >
                            <Mail /> Send by email to {userEmail}
                        </Button>
                        {!mailEnabled && (
                            <p className="text-xs text-muted-foreground">
                                Email is not enabled in this deployment
                                (BUFFALO_MAIL_ENABLED). Show the link once and
                                hand it over by another secure route.
                            </p>
                        )}
                        <Button
                            variant="secondary"
                            onClick={() => void issue('manual')}
                            disabled={busy}
                            data-test="setup-link-manual"
                        >
                            <Link2 /> Show link once
                        </Button>
                    </div>
                )}

                {result?.delivery === 'manual' && (
                    <div className="grid gap-3">
                        <div
                            className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm"
                            role="alert"
                        >
                            Copy this link now. It will not be shown again. Hand
                            it only to the user, by a secure route.
                        </div>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                value={result.url}
                                onFocus={(event) => event.target.select()}
                                data-test="setup-link-url"
                            />
                            <Button
                                variant="outline"
                                size="icon"
                                onClick={() => void copy(result.url)}
                                aria-label="Copy link"
                            >
                                {copied === result.url ? <Check /> : <Copy />}
                            </Button>
                        </div>
                    </div>
                )}

                {result?.delivery === 'email' && (
                    <p className="text-sm">
                        The setup link was emailed to {result.sentTo}.
                    </p>
                )}

                {error && <p className="text-sm text-destructive">{error}</p>}

                <DialogFooter>
                    <Button variant="outline" onClick={() => close(false)}>
                        {result ? 'Done' : 'Cancel'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
