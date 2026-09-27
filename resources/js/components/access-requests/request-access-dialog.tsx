import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AccessRequestController from '@/actions/App/Http/Controllers/AccessRequests/AccessRequestController';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { AccessRequestOptions, Hit } from '@/types/access-requests';

const MIN_REASON = 30;

/**
 * Request temporary read-only access to a hit. The record is identified
 * only by the hit's opaque token, exchanged on the server.
 */
export default function RequestAccessDialog({
    hit,
    options,
    onClose,
}: {
    hit: Hit;
    options: AccessRequestOptions;
    onClose: () => void;
}) {
    const form = useForm({
        token: hit.token,
        reason: '',
        reference: '',
        scope: 'profile',
        urgency: 'normal',
        requested_days: '7',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            reference: data.reference || null,
            requested_days: Number(data.requested_days),
        }));
        form.post(AccessRequestController.store().url, {
            onSuccess: () => onClose(),
        });
    };

    const remaining = MIN_REASON - form.data.reason.trim().length;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Request access</DialogTitle>
                        <DialogDescription>
                            {hit.state} · {hit.category} · {hit.strength}. The
                            owning state decides. Access is read-only and
                            temporary, and every view is recorded.
                        </DialogDescription>
                    </DialogHeader>

                    <InputError
                        message={(form.errors as Record<string, string>).token}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="access-reason">Reason</Label>
                        <Textarea
                            id="access-reason"
                            rows={4}
                            maxLength={2000}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                        <p
                            className={cn(
                                'text-xs',
                                remaining > 0
                                    ? 'text-muted-foreground'
                                    : 'text-emerald-600 dark:text-emerald-400',
                            )}
                        >
                            {remaining > 0
                                ? `${remaining} more characters needed (at least ${MIN_REASON}).`
                                : 'Enough detail.'}
                        </p>
                        <InputError message={form.errors.reason} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="access-reference">
                            Case or file reference (optional)
                        </Label>
                        <Input
                            id="access-reference"
                            maxLength={100}
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reference} />
                    </div>

                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            What you need to see
                        </legend>
                        {options.scopes.map((scope) => (
                            <label
                                key={scope.value}
                                className={cn(
                                    'flex cursor-pointer gap-3 rounded-md border p-3 text-sm',
                                    form.data.scope === scope.value &&
                                        'border-primary',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="scope"
                                    value={scope.value}
                                    checked={form.data.scope === scope.value}
                                    onChange={() =>
                                        form.setData('scope', scope.value)
                                    }
                                />
                                <span>
                                    <span className="font-medium">
                                        {scope.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {scope.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                        <InputError message={form.errors.scope} />
                    </fieldset>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="access-urgency">Urgency</Label>
                            <NativeSelect
                                id="access-urgency"
                                value={form.data.urgency}
                                onChange={(e) =>
                                    form.setData('urgency', e.target.value)
                                }
                            >
                                {options.urgencies.map((u) => (
                                    <option key={u.value} value={u.value}>
                                        {u.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="access-days">Duration</Label>
                            <NativeSelect
                                id="access-days"
                                value={form.data.requested_days}
                                onChange={(e) =>
                                    form.setData(
                                        'requested_days',
                                        e.target.value,
                                    )
                                }
                            >
                                {options.days.map((d) => (
                                    <option key={d.value} value={d.value}>
                                        {d.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || remaining > 0}
                        >
                            {form.processing && <Spinner />}
                            Send request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
