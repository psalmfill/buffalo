import { useForm } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Circle } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import IncidentWorkflowController from '@/actions/App/Http/Controllers/Incidents/IncidentWorkflowController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import ConsolidationPreview from '@/components/incidents/consolidation-preview';
import { Field } from '@/components/incidents/editor-parts';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import { getJson } from '@/lib/http';
import { formatDateTime } from '@/lib/time';
import type { ConsolidationPreview as ConsolidationPreviewData } from '@/types/incidents';
import type {
    DuplicateRow,
    IncidentRecord,
    WorkflowCan,
    WorkflowOptions,
} from '@/types/incidents';

type Step =
    | 'submit'
    | 'return'
    | 'verify'
    | 'start-investigation'
    | 'resolve'
    | 'reopen'
    | 'close'
    | 'invalidate'
    | 'escalate'
    | 'acknowledge';

/** Text each simple step asks for. */
const STEPS: Record<
    Exclude<Step, 'submit' | 'invalidate'>,
    {
        label: string;
        title: string;
        field: 'reason' | 'summary' | 'comment' | null;
        prompt?: string;
        destructive?: boolean;
    }
> = {
    return: {
        label: 'Return',
        title: 'Return to the reporting officer',
        field: 'reason',
        prompt: 'What needs to change? (required)',
    },
    verify: {
        label: 'Verify',
        title: 'Verify this incident',
        field: 'comment',
        prompt: 'Comment (optional)',
    },
    'start-investigation': {
        label: 'Start investigation',
        title: 'Start the investigation',
        field: 'comment',
        prompt: 'Comment (optional)',
    },
    resolve: {
        label: 'Resolve',
        title: 'Resolve this incident',
        field: 'summary',
        prompt: 'Resolution summary (required)',
    },
    reopen: {
        label: 'Reopen',
        title: 'Reopen this incident',
        field: 'reason',
        prompt: 'Why is it being reopened? (required)',
    },
    close: {
        label: 'Close',
        title: 'Close this incident',
        field: 'comment',
        prompt: 'Comment (optional). A closed incident is locked: no more edits, evidence or follow-ups.',
        destructive: true,
    },
    escalate: {
        label: 'Escalate',
        title: 'Escalate to national level',
        field: 'reason',
        prompt: 'Why does national level need to know? (required)',
    },
    acknowledge: {
        label: 'Acknowledge escalation',
        title: 'Acknowledge the escalation',
        field: null,
    },
};

const ABILITY: Record<Step, keyof WorkflowCan> = {
    submit: 'submit',
    return: 'return',
    verify: 'verify',
    'start-investigation': 'startInvestigation',
    resolve: 'resolve',
    reopen: 'reopen',
    close: 'close',
    invalidate: 'invalidate',
    escalate: 'escalate',
    acknowledge: 'acknowledge',
};

/**
 * The workflow buttons the viewer may use (Phase 5C), each opening a dialog
 * for its required fields. The server checks every step again.
 */
export default function WorkflowActions({
    record,
    can,
    options,
    possibleDuplicates,
    openSubmit,
}: {
    record: IncidentRecord;
    can: WorkflowCan;
    options: WorkflowOptions;
    possibleDuplicates: DuplicateRow[];
    openSubmit: boolean;
}) {
    const [open, setOpen] = useState<Step | null>(openSubmit ? 'submit' : null);
    const order: Step[] = [
        'submit',
        'verify',
        'return',
        'escalate',
        'acknowledge',
        'start-investigation',
        'resolve',
        'reopen',
        'close',
        'invalidate',
    ];
    const available = order.filter((step) => can[ABILITY[step]]);

    if (available.length === 0) {
        return null;
    }

    return (
        <>
            <div className="flex flex-wrap gap-2" data-test="workflow-actions">
                {available.map((step) => (
                    <Button
                        key={step}
                        variant={
                            step === 'submit' ||
                            step === 'verify' ||
                            step === 'acknowledge'
                                ? 'default'
                                : 'outline'
                        }
                        onClick={() => setOpen(step)}
                    >
                        {step === 'submit'
                            ? 'Submit'
                            : step === 'invalidate'
                              ? 'Mark invalid'
                              : STEPS[step].label}
                    </Button>
                ))}
            </div>
            {open === 'submit' && (
                <SubmitDialog record={record} onClose={() => setOpen(null)} />
            )}
            {open === 'invalidate' && (
                <InvalidateDialog
                    incidentUlid={record.incident.ulid}
                    reasons={options.invalidReasons}
                    possibleDuplicates={possibleDuplicates}
                    onClose={() => setOpen(null)}
                />
            )}
            {open !== null && open !== 'submit' && open !== 'invalidate' && (
                <StepDialog
                    step={open}
                    incidentUlid={record.incident.ulid}
                    onClose={() => setOpen(null)}
                />
            )}
        </>
    );
}

const URLS: Record<
    Exclude<Step, 'submit' | 'invalidate'>,
    (ulid: string) => string
> = {
    return: (ulid) => IncidentWorkflowController.returnMethod(ulid).url,
    verify: (ulid) => IncidentWorkflowController.verify(ulid).url,
    'start-investigation': (ulid) =>
        IncidentWorkflowController.startInvestigation(ulid).url,
    resolve: (ulid) => IncidentWorkflowController.resolve(ulid).url,
    reopen: (ulid) => IncidentWorkflowController.reopen(ulid).url,
    close: (ulid) => IncidentWorkflowController.close(ulid).url,
    escalate: (ulid) => IncidentWorkflowController.escalate(ulid).url,
    acknowledge: (ulid) => IncidentWorkflowController.acknowledge(ulid).url,
};

function StepDialog({
    step,
    incidentUlid,
    onClose,
}: {
    step: Exclude<Step, 'submit' | 'invalidate'>;
    incidentUlid: string;
    onClose: () => void;
}) {
    const config = STEPS[step];
    const form = useForm<{ reason: string; summary: string; comment: string }>({
        reason: '',
        summary: '',
        comment: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            config.field === null ? {} : { [config.field]: data[config.field] },
        );
        form.post(URLS[step](incidentUlid), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{config.title}</DialogTitle>
                        {config.field === null && (
                            <DialogDescription>
                                This records that national level has seen the
                                escalation. The status does not change.
                            </DialogDescription>
                        )}
                    </DialogHeader>
                    {config.field !== null && (
                        <Field
                            id={`step-${config.field}`}
                            label={config.prompt ?? ''}
                            error={form.errors[config.field]}
                        >
                            {config.field === 'summary' ? (
                                // The resolution summary is rich text (Phase 11B); reasons and comments stay plain.
                                <RichTextEditor
                                    id="step-summary"
                                    value={form.data.summary}
                                    onChange={(html) =>
                                        form.setData('summary', html)
                                    }
                                    limit={10000}
                                    invalid={!!form.errors.summary}
                                />
                            ) : (
                                <Textarea
                                    id={`step-${config.field}`}
                                    rows={4}
                                    value={form.data[config.field]}
                                    onChange={(e) =>
                                        form.setData(
                                            config.field as
                                                | 'reason'
                                                | 'summary'
                                                | 'comment',
                                            e.target.value,
                                        )
                                    }
                                />
                            )}
                        </Field>
                    )}
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={
                                config.destructive ? 'destructive' : 'default'
                            }
                            disabled={form.processing}
                        >
                            {config.label}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Submission: lists what is still missing (without blocking) and any
 * possible duplicates, which must be confirmed with "Submit anyway".
 */
function SubmitDialog({
    record,
    onClose,
}: {
    record: IncidentRecord;
    onClose: () => void;
}) {
    const [duplicates, setDuplicates] = useState<DuplicateRow[] | null>(null);
    const form = useForm({ confirm_duplicates: false });
    const { incident } = record;

    useEffect(() => {
        let active = true;
        getJson<{ duplicates: DuplicateRow[] }>(
            IncidentWorkflowController.duplicates(incident.ulid).url,
        )
            .then((data) => active && setDuplicates(data.duplicates))
            .catch(() => active && setDuplicates([]));

        return () => {
            active = false;
        };
    }, [incident.ulid]);

    const checklist: [string, boolean][] = [
        ['Casualties recorded', record.casualties.length > 0],
        ['Persons involved recorded', record.persons.length > 0],
        ['Responding agencies recorded', record.agencies.length > 0],
        ['Location on the map', incident.latitude !== null],
        ['Evidence attached', record.evidence.items.length > 0],
    ];

    const send = (confirm: boolean) => {
        form.transform(() => ({ confirm_duplicates: confirm }));
        form.post(IncidentWorkflowController.submit(incident.ulid).url, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const hasDuplicates = (duplicates?.length ?? 0) > 0;
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Submit this incident</DialogTitle>
                    <DialogDescription>
                        The State Director and Deputy are notified at once
                        {incident.severity.value === 'critical'
                            ? ', and national level, since it is critical'
                            : ''}
                        . Missing details can be added afterwards.
                    </DialogDescription>
                </DialogHeader>
                <ul className="space-y-1 text-sm" aria-label="Optional details">
                    {checklist.map(([label, done]) => (
                        <li key={label} className="flex items-center gap-2">
                            {done ? (
                                <CheckCircle2 className="size-4 text-green-600" />
                            ) : (
                                <Circle className="size-4 text-muted-foreground" />
                            )}
                            <span
                                className={done ? '' : 'text-muted-foreground'}
                            >
                                {done
                                    ? label
                                    : `Not yet: ${label.toLowerCase()}`}
                            </span>
                        </li>
                    ))}
                </ul>
                {duplicates === null ? (
                    <p className="text-sm text-muted-foreground">
                        Checking for possible duplicates…
                    </p>
                ) : (
                    hasDuplicates && (
                        <DuplicateList
                            title="Possible duplicates: this may already have been reported"
                            rows={duplicates}
                        />
                    )
                )}
                {['description', 'title', 'duplicates'].map((key) => (
                    <InputError key={key} message={errors[key]} />
                ))}
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={form.processing || duplicates === null}
                        onClick={() => send(hasDuplicates)}
                    >
                        {hasDuplicates ? 'Submit anyway' : 'Submit'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DuplicateList({
    title,
    rows,
    onPick,
}: {
    title: string;
    rows: DuplicateRow[];
    onPick?: (row: DuplicateRow) => void;
}) {
    return (
        <section
            aria-label={title}
            className="space-y-2 rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm dark:bg-amber-950"
        >
            <h3 className="flex items-center gap-2 font-medium">
                <AlertTriangle className="size-4 text-amber-600" /> {title}
            </h3>
            <ul className="divide-y">
                {rows.map((row) => (
                    <li
                        key={row.ulid}
                        className="flex flex-wrap items-center justify-between gap-2 py-1.5"
                    >
                        <a
                            href={`/incidents/${row.ulid}`}
                            target="_blank"
                            rel="noreferrer"
                            className="hover:underline"
                        >
                            <span className="font-medium">
                                {row.reference_no ?? 'Draft'}
                            </span>{' '}
                            · {row.title}
                            <span className="block text-xs text-muted-foreground">
                                {formatDateTime(row.occurred_at)} ·{' '}
                                {row.status.label}
                            </span>
                        </a>
                        {onPick && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onPick(row)}
                            >
                                Invalidate as duplicate of this
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function InvalidateDialog({
    incidentUlid,
    reasons,
    possibleDuplicates,
    preset,
    onClose,
}: {
    incidentUlid: string;
    reasons: { value: string; label: string }[];
    possibleDuplicates: DuplicateRow[];
    preset?: DuplicateRow;
    onClose: () => void;
}) {
    const [original, setOriginal] = useState<PickedEntity | null>(
        preset
            ? { value: preset.ulid, label: preset.reference_no ?? preset.title }
            : null,
    );
    const form = useForm({
        reason: preset ? 'duplicate' : 'false_report',
        note: '',
        // Phase 10B: consolidate the duplicate's content into the original.
        consolidate: true,
        amendment_reason: '',
    });
    const [preview, setPreview] = useState<ConsolidationPreviewData | null>(
        null,
    );
    const originalUlid = original?.value ?? null;
    const wantsPreview = form.data.reason === 'duplicate' && originalUlid;

    useEffect(() => {
        if (!wantsPreview || !originalUlid) {
            return;
        }

        let active = true;
        getJson<ConsolidationPreviewData>(
            IncidentWorkflowController.consolidation(incidentUlid, {
                query: { original: originalUlid },
            }).url,
        )
            .then((data) => active && setPreview(data))
            .catch(() => active && setPreview(null));

        return () => {
            active = false;
        };
    }, [wantsPreview, originalUlid, incidentUlid]);

    const consolidating =
        form.data.reason === 'duplicate' &&
        form.data.consolidate &&
        preview !== null &&
        preview.allowed;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            reason: data.reason,
            note: data.note || null,
            ...(data.reason === 'duplicate'
                ? {
                      duplicate_of: original?.value ?? null,
                      consolidate: consolidating,
                      ...(consolidating && preview?.requiresAmendmentReason
                          ? { amendment_reason: data.amendment_reason }
                          : {}),
                  }
                : {}),
        }));
        form.post(IncidentWorkflowController.invalidate(incidentUlid).url, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Mark this incident invalid</DialogTitle>
                        <DialogDescription>
                            An invalid incident is locked and left out of
                            statistics.
                        </DialogDescription>
                    </DialogHeader>
                    <Field
                        id="invalid-reason"
                        label="Reason"
                        error={errors.reason}
                    >
                        <NativeSelect
                            id="invalid-reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        >
                            {reasons.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {form.data.reason === 'duplicate' && (
                        <div className="space-y-2">
                            {possibleDuplicates.length > 0 && (
                                <DuplicateList
                                    title="Possible duplicates"
                                    rows={possibleDuplicates}
                                    onPick={(row) =>
                                        setOriginal({
                                            value: row.ulid,
                                            label:
                                                row.reference_no ?? row.title,
                                        })
                                    }
                                />
                            )}
                            <Field
                                id="invalid-original"
                                label="The original incident"
                                error={errors.duplicate_of}
                            >
                                <EntityPicker
                                    id="invalid-original"
                                    endpoint={PickerController.incidents().url}
                                    params={{ except: incidentUlid }}
                                    placeholder="Reference number or title"
                                    value={original}
                                    onChange={(value) => {
                                        setPreview(null);
                                        setOriginal(value);
                                    }}
                                />
                            </Field>
                            {original && (
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.consolidate}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'consolidate',
                                                checked === true,
                                            )
                                        }
                                    />
                                    Consolidate into the original
                                </label>
                            )}
                            {original &&
                                form.data.consolidate &&
                                (preview === null ? (
                                    <p className="text-sm text-muted-foreground">
                                        Preparing the consolidation preview…
                                    </p>
                                ) : (
                                    <ConsolidationPreview preview={preview} />
                                ))}
                            {consolidating &&
                                preview?.requiresAmendmentReason && (
                                    <Field
                                        id="consolidate-amendment"
                                        label="Amendment reason (the original is verified)"
                                        error={errors.amendment_reason}
                                    >
                                        <Textarea
                                            id="consolidate-amendment"
                                            rows={2}
                                            value={form.data.amendment_reason}
                                            onChange={(e) =>
                                                form.setData(
                                                    'amendment_reason',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                )}
                        </div>
                    )}
                    <Field
                        id="invalid-note"
                        label={
                            form.data.reason === 'other'
                                ? 'Note (required)'
                                : 'Note (optional)'
                        }
                        error={errors.note}
                    >
                        <Textarea
                            id="invalid-note"
                            rows={3}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {consolidating
                                ? 'Consolidate and mark invalid'
                                : 'Mark invalid'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
