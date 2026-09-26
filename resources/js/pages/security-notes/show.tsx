import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import SecurityNoteController from '@/actions/App/Http/Controllers/CommunityInformation/SecurityNoteController';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import NoteStatusBadge from '@/components/community-information/note-status-badge';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { formatDateTime } from '@/lib/time';
import type { NoteDetail } from '@/types/community-information';
import type { Option } from '@/types/local-government';
import type { ActivityEntry } from '@/types/users';

type Props = {
    note: NoteDetail;
    statuses: Option[];
    history: ActivityEntry[];
    can: { update: boolean; delete: boolean };
};

export default function SecurityNoteShow({
    note,
    statuses,
    history,
    can,
}: Props) {
    const [status, setStatus] = useState(note.status.value);
    const [resolvedAt, setResolvedAt] = useState(note.resolved_at ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [deleting, setDeleting] = useState(false);

    const changeStatus = () =>
        router.post(
            SecurityNoteController.status(note.ulid).url,
            {
                status,
                resolved_at: status === 'resolved' ? resolvedAt : null,
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
            },
        );

    return (
        <>
            <Head title={note.title} />
            <div className="max-w-5xl space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <ClassificationBadge
                                level={note.classification.value}
                            />
                            <NoteStatusBadge
                                status={note.status.value}
                                label={note.status.label}
                            />
                        </div>
                        <h1 className="text-xl font-semibold">{note.title}</h1>
                        <p className="text-sm text-muted-foreground">
                            {note.category.label} ·{' '}
                            <Link
                                href={CommunityController.show(
                                    note.community.ulid,
                                    { query: { tab: 'security_notes' } },
                                )}
                                className="hover:underline"
                            >
                                {note.community.name}
                            </Link>
                            ,{' '}
                            <Link
                                href={LgaController.show(note.lga.ulid, {
                                    query: { tab: 'security_notes' },
                                })}
                                className="hover:underline"
                            >
                                {note.lga.name} LGA
                            </Link>
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={SecurityNoteController.edit(
                                        note.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="destructive"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <section className="rounded-lg border p-4 lg:col-span-2">
                        <p className="text-sm whitespace-pre-line">
                            {note.description}
                        </p>
                    </section>
                    <section className="space-y-4 rounded-lg border p-4 text-sm">
                        <dl className="grid gap-2">
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Observed on
                                </dt>
                                <dd>{note.observed_at}</dd>
                            </div>
                            {note.resolved_at && (
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Resolved on
                                    </dt>
                                    <dd>{note.resolved_at}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Recorded by
                                </dt>
                                <dd>
                                    {note.created_by ?? '—'}
                                    {note.created_at &&
                                        `, ${formatDateTime(note.created_at)}`}
                                </dd>
                            </div>
                        </dl>
                        {can.update && (
                            <div className="space-y-2 border-t pt-4">
                                <Label htmlFor="status">Change status</Label>
                                <NativeSelect
                                    id="status"
                                    value={status}
                                    onChange={(event) =>
                                        setStatus(event.target.value)
                                    }
                                >
                                    {statuses.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                {status === 'resolved' && (
                                    <Input
                                        type="date"
                                        aria-label="Resolved on"
                                        value={resolvedAt}
                                        onChange={(event) =>
                                            setResolvedAt(event.target.value)
                                        }
                                    />
                                )}
                                <InputError
                                    message={
                                        errors.resolved_at ?? errors.status
                                    }
                                />
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={changeStatus}
                                    disabled={
                                        status === note.status.value &&
                                        (status !== 'resolved' ||
                                            resolvedAt === note.resolved_at)
                                    }
                                >
                                    Update status
                                </Button>
                            </div>
                        )}
                    </section>
                </div>

                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">History</h2>
                    <ActivityTable entries={history} />
                </section>
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title="Delete this security note?"
                    description="It is removed from lists and counts. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={SecurityNoteController.destroy(note.ulid).url}
                />
            )}
        </>
    );
}

SecurityNoteShow.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
