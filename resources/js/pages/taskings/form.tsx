import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import TaskingAttachmentController from '@/actions/App/Http/Controllers/Taskings/TaskingAttachmentController';
import TaskingController from '@/actions/App/Http/Controllers/Taskings/TaskingController';
import ClassificationSelect from '@/components/community-information/classification-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import RichTextEditor from '@/components/rich-text/rich-text-editor';
import ReportAttachments from '@/components/security/report-attachments';
import RecipientPicker, {
    recipientSentence,
} from '@/components/taskings/recipient-picker';
import { taskingAttachmentEndpoints } from '@/components/taskings/tasking-display';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import type { ClassificationLevel } from '@/types/auth';
import type { ReportAttachment } from '@/types/coverage';
import type {
    PickerLga,
    TaskingDetail,
    TaskingLimits,
    TaskingOption,
    TaskingOptions,
} from '@/types/taskings';

type FormData = {
    title: string;
    body: string;
    priority: string;
    response_due_at: string;
    classification: ClassificationLevel;
    lgas: string[];
    send: boolean;
};

const LEVEL_RANK: Record<ClassificationLevel, number> = {
    restricted: 1,
    confidential: 2,
    secret: 3,
};

/**
 * New tasking / edit draft (Phase 11H): one recipient picker (one LGA,
 * several, by district, or all), the instruction, its priority, due date
 * and classification, and attachments once the draft is saved. Send asks
 * for confirmation naming the recipients and any vacant LGAs.
 */
export default function TaskingForm({
    tasking,
    attachments,
    state,
    lgas,
    districts,
    chosen,
    limits,
    options,
    can,
}: {
    tasking: TaskingDetail | null;
    attachments: ReportAttachment[];
    state: { ulid: string; name: string };
    lgas: PickerLga[];
    districts: { ulid: string; name: string }[];
    chosen: string[];
    limits: TaskingLimits;
    options: TaskingOptions & {
        classifications: TaskingOption[];
        chiefMaxLevel: ClassificationLevel;
    };
    can: { addAttachment: boolean; delete: boolean };
}) {
    const form = useForm<FormData>({
        title: tasking?.title_input ?? '',
        body: tasking?.body_raw ?? '',
        priority: tasking?.priority.value ?? 'routine',
        response_due_at: tasking?.response_due_local ?? '',
        classification: tasking?.classification.value ?? 'restricted',
        lgas: chosen,
        send: false,
    });
    const [confirming, setConfirming] = useState(false);
    const [discarding, setDiscarding] = useState(false);

    const chosenLgas = useMemo(
        () => lgas.filter((l) => form.data.lgas.includes(l.ulid)),
        [lgas, form.data.lgas],
    );
    const vacant = chosenLgas.filter((l) => l.chief === null);
    const hiddenFromChiefs =
        LEVEL_RANK[form.data.classification] >
        LEVEL_RANK[options.chiefMaxLevel];
    const sentence = recipientSentence(chosenLgas, lgas.length, state.name);

    const submit = (send: boolean) => {
        form.transform((data) => ({
            ...data,
            send,
            response_due_at:
                data.response_due_at === '' ? null : data.response_due_at,
        }));

        const settings = {
            preserveScroll: true,
            onFinish: () => setConfirming(false),
        };

        if (tasking) {
            form.put(TaskingController.update(tasking.ulid).url, settings);
        } else {
            form.post(TaskingController.store().url, settings);
        }
    };

    const onSubmit = (event: FormEvent) => {
        event.preventDefault();
        submit(false);
    };

    return (
        <>
            <Head title={tasking ? 'Edit tasking draft' : 'New tasking'} />
            <form className="max-w-4xl space-y-6 p-4" onSubmit={onSubmit}>
                <Heading
                    help={{ topic: 'guides/taskings' }}
                    title={tasking ? 'Edit tasking draft' : 'New tasking'}
                    description={`An instruction to the LGA Security Chiefs of ${state.name}. Once sent it cannot be changed: recall it with a reason, or add a follow-up note.`}
                />

                <RecipientPicker
                    lgas={lgas}
                    districts={districts}
                    selected={form.data.lgas}
                    onChange={(ulids) => form.setData('lgas', ulids)}
                    error={form.errors.lgas ?? form.errors['lgas.0']}
                />

                {vacant.length > 0 && (
                    <Alert data-test="vacant-warning">
                        <AlertTriangle />
                        <AlertDescription>
                            {vacant.map((l) => (
                                <p key={l.ulid}>
                                    {l.name} LGA has no Security Chief assigned;
                                    the tasking will wait for the next chief.
                                </p>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="title">Title (optional)</Label>
                    <Input
                        id="title"
                        maxLength={200}
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="Tasking from the State Director, [date]"
                    />
                    <InputError message={form.errors.title} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="body">Instruction</Label>
                    <RichTextEditor
                        id="body"
                        value={form.data.body}
                        onChange={(html) => form.setData('body', html)}
                        limit={limits.maxCharacters}
                        invalid={form.errors.body !== undefined}
                        minHeight="min-h-72"
                        placeholder="What should the LGA Security Chiefs do, by when, and how should they report back?"
                    />
                    <InputError message={form.errors.body} />
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="grid gap-2">
                        <Label htmlFor="priority">Priority</Label>
                        <NativeSelect
                            id="priority"
                            value={form.data.priority}
                            onChange={(e) =>
                                form.setData('priority', e.target.value)
                            }
                        >
                            {options.priorities.map((p) => (
                                <option key={p.value} value={p.value}>
                                    {p.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.priority} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="response_due_at">
                            Response due (optional)
                        </Label>
                        <Input
                            id="response_due_at"
                            type="datetime-local"
                            value={form.data.response_due_at}
                            onChange={(e) =>
                                form.setData('response_due_at', e.target.value)
                            }
                        />
                        <InputError message={form.errors.response_due_at} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="classification">Classification</Label>
                        <ClassificationSelect
                            value={form.data.classification}
                            onChange={(level) =>
                                form.setData('classification', level)
                            }
                        />
                        <InputError message={form.errors.classification} />
                    </div>
                </div>

                {hiddenFromChiefs && (
                    <Alert
                        variant="destructive"
                        data-test="classification-warning"
                    >
                        <AlertTriangle />
                        <AlertDescription>
                            LGA Security Chiefs are cleared for Restricted only,
                            so they could not read a tasking at this level.
                            Choose Restricted to send it; you can still save it
                            as a draft.
                        </AlertDescription>
                    </Alert>
                )}

                {tasking ? (
                    <ReportAttachments
                        reportLevel={tasking.classification.value}
                        attachments={attachments}
                        canUpload={can.addAttachment}
                        limits={limits}
                        endpoints={taskingAttachmentEndpoints(
                            TaskingAttachmentController.store(tasking.ulid).url,
                        )}
                        withDocuments
                        ownerLabel="tasking"
                        testId="tasking-attachments"
                    />
                ) : (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        Photos, videos and documents can be attached once the
                        draft is saved.
                    </p>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        disabled={
                            form.processing ||
                            form.data.lgas.length === 0 ||
                            hiddenFromChiefs
                        }
                        onClick={() => setConfirming(true)}
                        data-test="send-tasking"
                    >
                        <Send /> Send
                    </Button>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                        data-test="save-draft"
                    >
                        Save draft
                    </Button>
                    {tasking && can.delete && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setDiscarding(true)}
                        >
                            Discard draft
                        </Button>
                    )}
                    <Button type="button" variant="ghost" asChild>
                        <Link href={TaskingController.index()}>Cancel</Link>
                    </Button>
                </div>
            </form>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent data-test="send-confirmation">
                    <DialogHeader>
                        <DialogTitle>Send this tasking?</DialogTitle>
                        <DialogDescription>
                            It cannot be changed once sent.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm" data-test="confirmation-text">
                        {sentence.all ? (
                            <>
                                Send to <strong>all {lgas.length}</strong> LGA
                                chiefs in {state.name}
                            </>
                        ) : (
                            sentence.text
                        )}
                    </p>
                    {vacant.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            No Security Chief assigned:{' '}
                            {vacant.map((l) => l.name).join(', ')}. The tasking
                            will wait for the next chief.
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            disabled={form.processing}
                            onClick={() => submit(true)}
                            data-test="confirm-send"
                        >
                            <Send /> Send to {chosenLgas.length}{' '}
                            {chosenLgas.length === 1 ? 'LGA' : 'LGAs'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={discarding} onOpenChange={setDiscarding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            It was never sent; nobody has seen it.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDiscarding(false)}
                        >
                            Keep
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() =>
                                tasking &&
                                router.delete(
                                    TaskingController.destroy(tasking.ulid).url,
                                )
                            }
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

TaskingForm.layout = {
    breadcrumbs: [
        { title: 'Taskings', href: TaskingController.index() },
        { title: 'Tasking', href: TaskingController.create() },
    ],
};
