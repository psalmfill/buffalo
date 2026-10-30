import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';

export type EntryField = {
    name: string;
    label: string;
    help?: string;
    required?: boolean;
    /** A select instead of a text box. */
    options?: { value: string; label: string }[];
    mono?: boolean;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    submitLabel: string;
    url: string;
    method: 'post' | 'put';
    fields: EntryField[];
    initial?: Record<string, string>;
    /** Extra fields sent unchanged. */
    data?: Record<string, string | boolean>;
    destructive?: boolean;
};

/**
 * A change in Administration › Hierarchy: the entry's fields plus the
 * required reason (recorded in the audit log). The server asks for step-up
 * when needed and the visit is retried after the code is confirmed, so the
 * dialog follows the router's events rather than one visit's callbacks.
 */
export default function EntryDialog({
    open,
    onOpenChange,
    title,
    description,
    submitLabel,
    url,
    method,
    fields,
    initial = {},
    data = {},
    destructive = false,
}: Props) {
    const [values, setValues] = useState<Record<string, string>>(initial);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const offSuccess = router.on('success', (event) => {
            const pageErrors = event.detail.page.props.errors ?? {};

            if (Object.keys(pageErrors).length === 0) {
                setReason('');
                setErrors({});
                onOpenChange(false);
            }
        });
        const offError = router.on('error', (event) =>
            setErrors(event.detail.errors as Record<string, string>),
        );
        const offFinish = router.on('finish', () => setProcessing(false));

        return () => {
            offSuccess();
            offError();
            offFinish();
        };
    }, [open, onOpenChange]);

    const submit = () => {
        setProcessing(true);
        router.visit(url, {
            method,
            data: { ...data, ...values, reason },
            preserveScroll: true,
            preserveState: true,
        });
    };

    const otherError = Object.entries(errors).find(
        ([key]) => key !== 'reason' && !fields.some((f) => f.name === key),
    )?.[1];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submit();
                    }}
                >
                    {fields.map((field) => (
                        <div key={field.name} className="grid gap-2">
                            <Label htmlFor={`entry-${field.name}`}>
                                {field.label}
                            </Label>
                            {field.options ? (
                                <NativeSelect
                                    id={`entry-${field.name}`}
                                    value={values[field.name] ?? ''}
                                    required={field.required}
                                    onChange={(event) =>
                                        setValues({
                                            ...values,
                                            [field.name]: event.target.value,
                                        })
                                    }
                                >
                                    <option value="">Choose…</option>
                                    {field.options.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            ) : (
                                <Input
                                    id={`entry-${field.name}`}
                                    value={values[field.name] ?? ''}
                                    required={field.required}
                                    className={
                                        field.mono ? 'font-mono' : undefined
                                    }
                                    aria-describedby={
                                        field.help
                                            ? `entry-${field.name}-help`
                                            : undefined
                                    }
                                    onChange={(event) =>
                                        setValues({
                                            ...values,
                                            [field.name]: event.target.value,
                                        })
                                    }
                                />
                            )}
                            {field.help && (
                                <p
                                    id={`entry-${field.name}-help`}
                                    className="text-xs text-muted-foreground"
                                >
                                    {field.help}
                                </p>
                            )}
                            <InputError message={errors[field.name]} />
                        </div>
                    ))}

                    <div className="grid gap-2">
                        <Label htmlFor="entry-reason">Reason</Label>
                        <Textarea
                            id="entry-reason"
                            value={reason}
                            required
                            maxLength={1000}
                            placeholder="Why is this change needed? It is recorded in the audit log."
                            onChange={(event) => setReason(event.target.value)}
                        />
                        <InputError message={errors.reason} />
                    </div>

                    <InputError message={otherError} />

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing || reason.trim() === ''}
                        >
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
