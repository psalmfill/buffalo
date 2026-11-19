import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Pencil, Undo2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import TaskingAttachmentController from '@/actions/App/Http/Controllers/Taskings/TaskingAttachmentController';
import TaskingController from '@/actions/App/Http/Controllers/Taskings/TaskingController';
import TaskingFollowUpController from '@/actions/App/Http/Controllers/Taskings/TaskingFollowUpController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import PrintRecordButton from '@/components/reports/print-record-button';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import RichTextView from '@/components/rich-text/rich-text-view';
import ReportAttachments from '@/components/security/report-attachments';
import {
    PriorityBadge,
    TaskingStatusBadge,
    taskingAttachmentEndpoints,
    useLiveTaskings,
} from '@/components/taskings/tasking-display';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
import { Textarea } from '@/components/ui/textarea';
import { useCurrentUser } from '@/hooks/use-auth';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { ReportAttachment } from '@/types/coverage';
import type {
    TaskingDetail,
    TaskingLimits,
    TaskingNote,
    TaskingOptions,
    TaskingProgress,
    TaskingRecipientDetail,
    TaskingRecipientRow,
} from '@/types/taskings';

type Mine = TaskingRecipientDetail & {
    can: { respond: boolean; upload: boolean };
};

/**
 * One tasking (Phase 11H). Directors and national users: the instruction,
 * follow-up notes, progress and the recipients table (a row opens that
 * LGA's report back). An LGA chief: the instruction, notes and their own
 * LGA's panel only (acknowledge, mark completed, further responses).
 */
export default function TaskingShow({
    tasking,
    notes,
    attachments,
    progress,
    recipients,
    selected,
    mine,
    limits,
    options,
    can,
}: {
    tasking: TaskingDetail;
    notes: TaskingNote[];
    attachments: ReportAttachment[];
    progress: TaskingProgress | null;
    recipients: TaskingRecipientRow[] | null;
    selected: TaskingRecipientDetail | null;
    mine: Mine | null;
    limits: TaskingLimits;
    options: TaskingOptions;
    can: {
        update: boolean;
        delete: boolean;
        recall: boolean;
        addNote: boolean;
        print: boolean;
    };
}) {
    const user = useCurrentUser();
    const [recalling, setRecalling] = useState(false);
    useLiveTaskings(
        mine !== null || user.jurisdiction.level !== 'state'
            ? `users.${user.ulid}`
            : `states.${user.jurisdiction.state?.ulid ?? ''}.taskings`,
        ['tasking', 'notes', 'progress', 'recipients', 'selected', 'mine'],
    );

    return (
        <>
            <Head title={tasking.reference ?? 'Tasking'} />
            <div className="max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1
                            className="text-xl font-semibold"
                            data-test="tasking-title"
                        >
                            {tasking.title}
                        </h1>
                        <div className="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                            <span>{tasking.reference ?? 'Draft'}</span>
                            <PriorityBadge priority={tasking.priority} />
                            <TaskingStatusBadge status={tasking.status} />
                            <ClassificationBadge
                                level={tasking.classification.value}
                            />
                            {tasking.target_mode && recipients && (
                                <span>
                                    {tasking.target_mode.label} (
                                    {recipients.length})
                                </span>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {tasking.sent_at
                                ? `Sent ${formatDateTime(tasking.sent_at)}${tasking.sent_by ? ` by ${tasking.sent_by}` : ''}`
                                : 'Not sent yet'}
                            {tasking.response_due_at &&
                                ` · response due ${formatDateTime(tasking.response_due_at)}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.print && (
                            <PrintRecordButton
                                type="tasking"
                                parameter="tasking"
                                ulid={tasking.ulid}
                            />
                        )}
                        {can.update && (
                            <Button asChild variant="outline">
                                <Link
                                    href={TaskingController.edit(tasking.ulid)}
                                >
                                    <Pencil /> Edit draft
                                </Link>
                            </Button>
                        )}
                        {can.recall && (
                            <Button
                                variant="outline"
                                onClick={() => setRecalling(true)}
                                data-test="recall"
                            >
                                <Undo2 /> Recall
                            </Button>
                        )}
                    </div>
                </div>

                {tasking.recall && (
                    <Alert variant="destructive" data-test="recall-notice">
                        <AlertTitle>
                            Recalled by {tasking.recall.by ?? 'the Director'}
                            {tasking.recall.at &&
                                ` on ${formatDateTime(tasking.recall.at)}`}
                        </AlertTitle>
                        <AlertDescription>
                            {tasking.recall.reason}
                        </AlertDescription>
                    </Alert>
                )}

                <section
                    className="rounded-lg border p-4"
                    data-test="tasking-body"
                >
                    <RichTextView html={tasking.body} />
                </section>

                {attachments.length > 0 && (
                    <ReportAttachments
                        reportLevel={tasking.classification.value}
                        attachments={attachments}
                        canUpload={false}
                        limits={limits}
                        endpoints={taskingAttachmentEndpoints('')}
                        withDocuments
                        ownerLabel="tasking"
                        testId="tasking-attachments"
                    />
                )}

                <Notes
                    notes={notes}
                    canAdd={can.addNote}
                    tasking={tasking}
                    limit={limits.noteMaxCharacters}
                />

                {mine && (
                    <MyLga mine={mine} tasking={tasking} limits={limits} />
                )}

                {recipients && (
                    <Recipients
                        tasking={tasking}
                        rows={recipients}
                        progress={progress}
                        selected={selected}
                        options={options}
                        limits={limits}
                    />
                )}
            </div>

            {recalling && (
                <RecallDialog
                    tasking={tasking}
                    onClose={() => setRecalling(false)}
                />
            )}
        </>
    );
}

function Notes({
    notes,
    canAdd,
    tasking,
    limit,
}: {
    notes: TaskingNote[];
    canAdd: boolean;
    tasking: TaskingDetail;
    limit: number;
}) {
    const form = useForm({ body: '' });

    if (notes.length === 0 && !canAdd) {
        return null;
    }

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(TaskingFollowUpController.note(tasking.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <section
            className="space-y-3 rounded-lg border p-4"
            data-test="tasking-notes"
        >
            <h2 className="text-sm font-semibold">Follow-up notes</h2>
            {notes.length === 0 && (
                <p className="text-sm text-muted-foreground">None yet.</p>
            )}
            <ol className="space-y-3">
                {notes.map((n) => (
                    <li key={n.ulid} className="rounded-md bg-muted/40 p-3">
                        <p className="text-xs text-muted-foreground">
                            {formatDateTime(n.at)}
                            {n.by && ` · ${n.by}`}
                        </p>
                        <RichTextView html={n.body} />
                    </li>
                ))}
            </ol>
            {canAdd && (
                <form className="space-y-2" onSubmit={submit}>
                    <Label htmlFor="note-body">Add a follow-up note</Label>
                    <RichTextEditor
                        id="note-body"
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        limit={limit}
                        invalid={form.errors.body !== undefined}
                    />
                    <InputError message={form.errors.body} />
                    <p className="text-xs text-muted-foreground">
                        Notes cannot be changed or removed; every recipient LGA
                        is told.
                    </p>
                    <Button type="submit" disabled={form.processing}>
                        Add note
                    </Button>
                </form>
            )}
        </section>
    );
}

/** The LGA chief's own LGA: acknowledge, complete, further responses. */
function MyLga({
    mine,
    tasking,
    limits,
}: {
    mine: Mine;
    tasking: TaskingDetail;
    limits: TaskingLimits;
}) {
    const complete = useForm({ response: '' });
    const respond = useForm({ body: '' });
    const done = mine.status.value === 'completed';
    const acknowledged = mine.status.value === 'acknowledged' || done;

    return (
        <section
            className="space-y-4 rounded-lg border p-4"
            data-test="my-lga"
            aria-labelledby="my-lga-heading"
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="my-lga-heading" className="text-sm font-semibold">
                    {mine.lga.name} LGA
                </h2>
                <div className="flex items-center gap-2">
                    <Badge variant="outline" data-test="my-status">
                        {mine.status.label}
                    </Badge>
                    {mine.overdue && (
                        <Badge variant="destructive">Overdue</Badge>
                    )}
                </div>
            </div>

            {mine.can.respond && !acknowledged && (
                <Button
                    onClick={() =>
                        router.post(
                            TaskingFollowUpController.acknowledge(mine.ulid)
                                .url,
                            {},
                            { preserveScroll: true },
                        )
                    }
                    data-test="acknowledge"
                >
                    <CheckCircle2 /> Acknowledge
                </Button>
            )}

            {done ? (
                <div className="space-y-2" data-test="report-back">
                    <h3 className="text-sm font-medium">
                        Report back ({formatDateTime(mine.completed.at)}
                        {mine.completed.by && `, ${mine.completed.by}`})
                    </h3>
                    <RichTextView html={mine.response} />
                </div>
            ) : (
                mine.can.respond && (
                    <form
                        className="space-y-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            complete.post(
                                TaskingFollowUpController.complete(mine.ulid)
                                    .url,
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <Label htmlFor="response">
                            Report back (required to mark completed)
                        </Label>
                        <RichTextEditor
                            id="response"
                            value={complete.data.response}
                            onChange={(html) =>
                                complete.setData('response', html)
                            }
                            limit={limits.responseMaxCharacters}
                            invalid={complete.errors.response !== undefined}
                            minHeight="min-h-48"
                        />
                        <InputError message={complete.errors.response} />
                        <Button
                            type="submit"
                            disabled={complete.processing}
                            data-test="mark-completed"
                        >
                            Mark completed
                        </Button>
                    </form>
                )
            )}

            {(mine.attachments.length > 0 || mine.can.upload) && (
                <ReportAttachments
                    reportLevel={tasking.classification.value}
                    attachments={mine.attachments}
                    canUpload={mine.can.upload}
                    limits={limits}
                    endpoints={taskingAttachmentEndpoints(
                        TaskingAttachmentController.storeForResponse(mine.ulid)
                            .url,
                    )}
                    withDocuments
                    heading="Attachments to your report back"
                    reloadOnly={['mine']}
                    ownerLabel="report back"
                    testId="response-attachments"
                />
            )}

            {mine.responses.length > 0 && (
                <ol className="space-y-3">
                    {mine.responses.map((r) => (
                        <li key={r.ulid} className="rounded-md bg-muted/40 p-3">
                            <p className="text-xs text-muted-foreground">
                                Further response, {formatDateTime(r.at)}
                                {r.by && ` · ${r.by}`}
                            </p>
                            <RichTextView html={r.body} />
                        </li>
                    ))}
                </ol>
            )}

            {done && mine.can.respond && (
                <form
                    className="space-y-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        respond.post(
                            TaskingFollowUpController.respond(mine.ulid).url,
                            {
                                preserveScroll: true,
                                onSuccess: () => respond.reset(),
                            },
                        );
                    }}
                >
                    <Label htmlFor="further">Add a further response</Label>
                    <RichTextEditor
                        id="further"
                        value={respond.data.body}
                        onChange={(html) => respond.setData('body', html)}
                        limit={limits.responseMaxCharacters}
                        invalid={respond.errors.body !== undefined}
                    />
                    <InputError message={respond.errors.body} />
                    <Button type="submit" disabled={respond.processing}>
                        Add response
                    </Button>
                </form>
            )}
        </section>
    );
}

/** The directors' and national users' view of every recipient LGA. */
function Recipients({
    tasking,
    rows,
    progress,
    selected,
    options,
    limits,
}: {
    tasking: TaskingDetail;
    rows: TaskingRecipientRow[];
    progress: TaskingProgress | null;
    selected: TaskingRecipientDetail | null;
    options: TaskingOptions;
    limits: TaskingLimits;
}) {
    const [status, setStatus] = useState('');
    const [overdueOnly, setOverdueOnly] = useState(false);
    const visible = useMemo(
        () =>
            rows.filter(
                (r) =>
                    (status === '' || r.status.value === status) &&
                    (!overdueOnly || r.overdue),
            ),
        [rows, status, overdueOnly],
    );

    const open = (ulid: string) =>
        router.get(
            TaskingController.show(tasking.ulid, {
                query: { recipient: ulid },
            }).url,
            {},
            { only: ['selected'], preserveScroll: true, preserveState: true },
        );

    return (
        <section className="space-y-3" data-test="recipients">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-sm font-semibold">
                        Recipients ({rows.length})
                    </h2>
                    {progress && (
                        <p className="text-sm" data-test="progress-line">
                            {progress.line}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="recipient-status" className="text-xs">
                            Status
                        </Label>
                        <NativeSelect
                            id="recipient-status"
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                        >
                            <option value="">Any</option>
                            {options.recipientStatuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <label className="flex items-center gap-2 pt-4 text-sm">
                        <input
                            type="checkbox"
                            checked={overdueOnly}
                            onChange={(e) => setOverdueOnly(e.target.checked)}
                        />
                        Overdue only
                    </label>
                </div>
            </div>

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm" data-test="recipients-table">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">LGA</th>
                            <th className="px-3 py-2 font-medium">Chief</th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="px-3 py-2 font-medium">Read</th>
                            <th className="px-3 py-2 font-medium">
                                Acknowledged
                            </th>
                            <th className="px-3 py-2 font-medium">Completed</th>
                            <th className="px-3 py-2 font-medium">Overdue</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {visible.map((r) => (
                            <tr
                                key={r.ulid}
                                className={cn(
                                    'cursor-pointer hover:bg-muted/50',
                                    selected?.ulid === r.ulid && 'bg-muted',
                                )}
                                onClick={() => open(r.ulid)}
                                data-test="recipient-row"
                            >
                                <td className="px-3 py-2 font-medium">
                                    <button
                                        type="button"
                                        className="hover:underline"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            open(r.ulid);
                                        }}
                                    >
                                        {r.lga.name}
                                    </button>
                                </td>
                                <td className="px-3 py-2">
                                    {r.chief ?? (
                                        <span className="text-muted-foreground">
                                            No chief assigned
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2">{r.status.label}</td>
                                <td className="px-3 py-2">
                                    {r.read_at
                                        ? formatDateTime(r.read_at)
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">
                                    {r.acknowledged_at
                                        ? formatDateTime(r.acknowledged_at)
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">
                                    {r.completed_at
                                        ? formatDateTime(r.completed_at)
                                        : '—'}
                                </td>
                                <td className="px-3 py-2">
                                    {r.overdue && (
                                        <Badge variant="destructive">
                                            Overdue
                                        </Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {selected && (
                <section
                    className="space-y-3 rounded-lg border p-4"
                    data-test="selected-recipient"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">
                            {selected.lga.name} LGA: {selected.status.label}
                        </h3>
                        {selected.overdue && (
                            <Badge variant="destructive">Overdue</Badge>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {selected.read.at &&
                            `Read ${formatDateTime(selected.read.at)}${selected.read.by ? ` by ${selected.read.by}` : ''}. `}
                        {selected.acknowledged.at &&
                            `Acknowledged ${formatDateTime(selected.acknowledged.at)}. `}
                        {selected.completed.at &&
                            `Completed ${formatDateTime(selected.completed.at)}.`}
                    </p>
                    {selected.response === '' ? (
                        <p className="text-sm text-muted-foreground">
                            No report back yet.
                        </p>
                    ) : (
                        <RichTextView html={selected.response} />
                    )}
                    {selected.responses.map((r) => (
                        <div
                            key={r.ulid}
                            className="rounded-md bg-muted/40 p-3"
                        >
                            <p className="text-xs text-muted-foreground">
                                Further response, {formatDateTime(r.at)}
                                {r.by && ` · ${r.by}`}
                            </p>
                            <RichTextView html={r.body} />
                        </div>
                    ))}
                    {selected.attachments.length > 0 && (
                        <ReportAttachments
                            reportLevel={tasking.classification.value}
                            attachments={selected.attachments}
                            canUpload={false}
                            limits={limits}
                            endpoints={taskingAttachmentEndpoints('')}
                            withDocuments
                            heading="Attachments"
                            reloadOnly={['selected']}
                            ownerLabel="report back"
                            testId="selected-attachments"
                        />
                    )}
                </section>
            )}
        </section>
    );
}

function RecallDialog({
    tasking,
    onClose,
}: {
    tasking: TaskingDetail;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Recall this tasking?</DialogTitle>
                    <DialogDescription>
                        Recipients keep seeing it, marked as recalled with your
                        reason, and can no longer act on it. To correct it, send
                        a new tasking.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="recall-reason">Reason</Label>
                    <Textarea
                        id="recall-reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={
                            form.processing ||
                            form.data.reason.trim().length < 5
                        }
                        onClick={() =>
                            form.post(
                                TaskingController.recall(tasking.ulid).url,
                                { preserveScroll: true, onSuccess: onClose },
                            )
                        }
                        data-test="confirm-recall"
                    >
                        Recall
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

TaskingShow.layout = {
    breadcrumbs: [{ title: 'Taskings', href: TaskingController.index() }],
};
