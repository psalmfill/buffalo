import { useForm } from '@inertiajs/react';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import ThreatLifecycleController from '@/actions/App/Http/Controllers/Threats/ThreatLifecycleController';
import InputError from '@/components/input-error';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import ActionDialog from '@/components/threats/action-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useState } from 'react';

const STATUS_TEXT: Record<string, { title: string; description: string }> = {
    mitigated: {
        title: 'Mark as mitigated',
        description:
            'Action taken has removed or reduced the threat. Say what was done.',
    },
    materialised: {
        title: 'Mark as materialised',
        description:
            'The threat happened. Link the incident it materialised as, and add a note.',
    },
    withdrawn: {
        title: 'Withdraw',
        description:
            'The report was mistaken or is no longer considered credible. Say why.',
    },
};

/**
 * Mitigated, materialised or withdrawn, with the required note. For
 * materialised, an incident may be picked (or given, from an incident page).
 */
export function StatusDialog({
    threatUlid,
    status,
    incident,
    onClose,
}: {
    threatUlid: string;
    status: string;
    /** Preset from an incident page. */
    incident?: PickedEntity;
    onClose: () => void;
}) {
    const [picked, setPicked] = useState<PickedEntity | null>(incident ?? null);
    const form = useForm({
        status,
        note: incident ? `Materialised as ${incident.label}.` : '',
        incident: incident?.value ?? '',
    });
    const text = STATUS_TEXT[status];

    const confirm = () =>
        form.post(ThreatLifecycleController.status(threatUlid).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });

    return (
        <ActionDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={text.title}
            description={text.description}
            confirmLabel={text.title}
            processing={form.processing}
            onConfirm={confirm}
        >
            {status === 'materialised' && !incident && (
                <div className="grid gap-2">
                    <Label htmlFor="incident">Incident</Label>
                    <EntityPicker
                        id="incident"
                        endpoint={PickerController.incidents().url}
                        placeholder="Reference or title"
                        value={picked}
                        onChange={(value) => {
                            setPicked(value);
                            form.setData('incident', value?.value ?? '');
                        }}
                    />
                    <p className="text-xs text-muted-foreground">
                        Needed unless an incident is already linked.
                    </p>
                    <InputError message={form.errors.incident} />
                </div>
            )}
            <div className="grid gap-2">
                <Label htmlFor="note">Note</Label>
                <Textarea
                    id="note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
                <InputError message={form.errors.note ?? form.errors.status} />
            </div>
        </ActionDialog>
    );
}

/** Extend (active) or reactivate (expired, mitigated, withdrawn). */
export function ExpiryDialog({
    threatUlid,
    mode,
    maxDays,
    onClose,
}: {
    threatUlid: string;
    mode: 'extend' | 'reactivate';
    maxDays: number;
    onClose: () => void;
}) {
    const form = useForm({ expires_at: '', comment: '' });
    const url =
        mode === 'extend'
            ? ThreatLifecycleController.extend(threatUlid).url
            : ThreatLifecycleController.reactivate(threatUlid).url;

    return (
        <ActionDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={mode === 'extend' ? 'Extend expiry' : 'Reactivate'}
            description={`Set a new expiry, at most ${maxDays} days from now, and say why the threat is still relevant.`}
            confirmLabel={mode === 'extend' ? 'Extend' : 'Reactivate'}
            processing={form.processing}
            onConfirm={() =>
                form.post(url, { preserveScroll: true, onSuccess: onClose })
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="expires_at">New expiry</Label>
                <Input
                    id="expires_at"
                    type="datetime-local"
                    value={form.data.expires_at}
                    onChange={(e) => form.setData('expires_at', e.target.value)}
                />
                <InputError message={form.errors.expires_at} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="comment">Comment</Label>
                <Textarea
                    id="comment"
                    value={form.data.comment}
                    onChange={(e) => form.setData('comment', e.target.value)}
                />
                <InputError message={form.errors.comment} />
            </div>
        </ActionDialog>
    );
}
