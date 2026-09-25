import { router } from '@inertiajs/react';
import { useState } from 'react';
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
import { Label } from '@/components/ui/label';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    url: string;
    destructive?: boolean;
    requireReason?: boolean;
};

/**
 * Confirmation for a user administration action (POST), optionally with a
 * required reason that is recorded in the audit log.
 */
export default function ConfirmActionDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    url,
    destructive = false,
    requireReason = false,
}: Props) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        router.post(url, requireReason ? { reason } : {}, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) =>
                setError(
                    errors.reason ?? errors.user ?? Object.values(errors)[0],
                ),
            onSuccess: () => {
                setReason('');
                setError(undefined);
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                {requireReason && (
                    <div className="grid gap-2">
                        <Label htmlFor="reason">
                            Reason (recorded in the audit log)
                        </Label>
                        <textarea
                            id="reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            maxLength={1000}
                            rows={3}
                            className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                    </div>
                )}
                <InputError message={error} />

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={confirm}
                        disabled={
                            processing ||
                            (requireReason && reason.trim() === '')
                        }
                        data-test="confirm-action"
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
