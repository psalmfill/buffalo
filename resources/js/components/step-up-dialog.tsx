import { router } from '@inertiajs/react';
import type { PendingVisit } from '@inertiajs/core';
import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import StepUpController from '@/actions/App/Http/Controllers/Auth/StepUpController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { HttpError, postJson } from '@/lib/http';
import {
    isStepUpResponse,
    registerStepUpHandler,
    requestStepUp,
    StepUpCancelled,
} from '@/lib/step-up';

type Pending = { resolve: () => void; reject: (error: Error) => void };

/**
 * Step-up authentication dialog (Phase 8B.2). Opens when a sensitive action
 * answers 428 (step-up required): the user enters the current code from
 * their authenticator app (recovery codes are not accepted) and the action
 * is retried. Inertia visits are retried automatically; fetch() calls
 * through lib/http retry themselves.
 */
export default function StepUpDialog() {
    const [pending, setPending] = useState<Pending | null>(null);
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [busy, setBusy] = useState(false);
    const lastVisit = useRef<PendingVisit | null>(null);

    useEffect(() => registerStepUpHandler((next) => setPending(next)), []);

    // Inertia visits: remember the last one; on a step-up answer, confirm
    // and run it again instead of showing Inertia's error dialog.
    useEffect(() => {
        const offStart = router.on('start', (event) => {
            lastVisit.current = event.detail.visit;
        });
        const offException = router.on('httpException', (event) => {
            const { status, data } = event.detail.response;

            if (!isStepUpResponse(status, data)) {
                return;
            }

            event.preventDefault();
            const visit = lastVisit.current;

            requestStepUp()
                .then(() => {
                    if (visit) {
                        router.visit(visit.url, {
                            method: visit.method,
                            data: visit.data,
                            headers: visit.headers,
                            preserveScroll: true,
                            preserveState: true,
                        });
                    }
                })
                .catch(() => undefined);
        });

        return () => {
            offStart();
            offException();
        };
    }, []);

    const close = (confirmed: boolean) => {
        if (pending) {
            if (confirmed) {
                pending.resolve();
            } else {
                pending.reject(new StepUpCancelled());
            }
        }
        setPending(null);
        setCode('');
        setError(undefined);
    };

    const submit = async () => {
        setBusy(true);
        setError(undefined);

        try {
            await postJson(StepUpController.store.url(), { code });
            close(true);
        } catch (e) {
            setCode('');
            setError(
                e instanceof HttpError
                    ? (e.body.errors?.code?.[0] ?? e.message)
                    : 'The code could not be checked. Try again.',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && close(false)}
        >
            <DialogContent className="sm:max-w-sm" data-test="step-up-dialog">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldCheck className="size-5" /> Confirm it is you
                    </DialogTitle>
                    <DialogDescription>
                        This action needs the current 6-digit code from your
                        authenticator app. Recovery codes cannot be used here.
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="flex flex-col items-center gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void submit();
                    }}
                >
                    <InputOTP
                        maxLength={6}
                        value={code}
                        onChange={setCode}
                        disabled={busy}
                        pattern={REGEXP_ONLY_DIGITS}
                        autoFocus
                    >
                        <InputOTPGroup>
                            {Array.from({ length: 6 }, (_, index) => (
                                <InputOTPSlot key={index} index={index} />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError message={error} />
                    <DialogFooter className="w-full sm:justify-between">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => close(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={busy || code.length !== 6}
                        >
                            {busy ? 'Checking…' : 'Confirm'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
