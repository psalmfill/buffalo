import { router, useForm } from '@inertiajs/react';
import { Download, Pencil, ShieldCheck, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import EvidenceFileController from '@/actions/App/Http/Controllers/Evidence/EvidenceFileController';
import IncidentEvidenceController from '@/actions/App/Http/Controllers/Evidence/IncidentEvidenceController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ClassificationSelect from '@/components/community-information/classification-select';
import InputError from '@/components/input-error';
import BuffaloMap from '@/components/map/BuffaloMap';
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
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { getJson } from '@/lib/http';
import { formatDateTime } from '@/lib/time';
import type { ClassificationLevel } from '@/types/auth';
import type { CustodyEntry, EvidenceItem } from '@/types/evidence';

export function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * One evidence item: the image preview or player, metadata, EXIF-derived
 * capture time and place, actions, and the chain of custody. Opening it
 * with a preview or player logs a view (server side).
 */
export default function EvidenceDetails({
    item,
    incidentLevel,
    onClose,
}: {
    item: EvidenceItem;
    incidentLevel: ClassificationLevel;
    onClose: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle className="flex flex-wrap items-center gap-2">
                        {item.title}
                        <ClassificationBadge
                            level={item.classification.value}
                        />
                    </DialogTitle>
                    <DialogDescription>
                        {item.type.label} · {formatBytes(item.size_bytes)} ·
                        uploaded by {item.uploaded_by ?? 'an officer'}{' '}
                        {formatDateTime(item.uploaded_at)}
                    </DialogDescription>
                </DialogHeader>

                {item.scan.value === 'infected' ? (
                    <p
                        className="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                        data-test="evidence-infected"
                    >
                        Malware was found in this file. It has been moved to
                        quarantine and is never served. Security staff have been
                        notified; the record and its hash are kept.
                    </p>
                ) : item.scan.value === 'quarantined' ? (
                    <p
                        className="rounded-md border border-dashed p-4 text-sm text-muted-foreground"
                        data-test="evidence-quarantined"
                    >
                        In quarantine: this file is waiting for its malware scan
                        and cannot be viewed or downloaded until it is found
                        clean.
                    </p>
                ) : item.processing ? (
                    <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                        Processing: the file is being verified. It will be
                        available shortly.
                    </p>
                ) : (
                    <Viewer item={item} />
                )}

                {item.description && (
                    <p className="text-sm whitespace-pre-line">
                        {item.description}
                    </p>
                )}

                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    <Fact label="Captured">
                        {item.captured_at
                            ? formatDateTime(item.captured_at)
                            : '—'}
                    </Fact>
                    <Fact label="Camera">{item.camera ?? '—'}</Fact>
                    <Fact label="Original file name">
                        {item.original_filename}
                    </Fact>
                    <Fact label="Content type">{item.mime_type}</Fact>
                    <div className="sm:col-span-2">
                        <Fact label="SHA-256 (recorded at upload)">
                            <code className="text-xs break-all">
                                {item.sha256}
                            </code>
                        </Fact>
                    </div>
                </dl>

                {item.latitude !== null && item.longitude !== null && (
                    <div className="space-y-1">
                        <p className="text-xs text-muted-foreground">
                            Capture location ({item.latitude.toFixed(5)},{' '}
                            {item.longitude.toFixed(5)})
                        </p>
                        <BuffaloMap
                            className="h-56"
                            center={[item.latitude, item.longitude]}
                            zoom={13}
                            markers={[
                                {
                                    id: item.ulid,
                                    position: [item.latitude, item.longitude],
                                    title: item.title,
                                },
                            ]}
                        />
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    {item.can.download && !item.processing && (
                        <Button variant="outline" asChild>
                            <a
                                href={
                                    EvidenceFileController.download(item.ulid)
                                        .url
                                }
                            >
                                <Download /> Download original
                            </a>
                        </Button>
                    )}
                    {item.can.verify && (
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    IncidentEvidenceController.verify(item.ulid)
                                        .url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <ShieldCheck /> Verify integrity
                        </Button>
                    )}
                    {item.can.update && (
                        <Button
                            variant="outline"
                            onClick={() => setEditing(true)}
                        >
                            <Pencil /> Edit details
                        </Button>
                    )}
                    {item.can.delete && (
                        <Button
                            variant="outline"
                            onClick={() => setDeleting(true)}
                        >
                            <Trash2 /> Remove
                        </Button>
                    )}
                </div>

                <Custody ulid={item.ulid} />

                {editing && (
                    <EditEvidence
                        item={item}
                        incidentLevel={incidentLevel}
                        onClose={() => setEditing(false)}
                    />
                )}
                <ConfirmActionDialog
                    open={deleting}
                    onOpenChange={setDeleting}
                    title="Remove this evidence?"
                    description="The item is removed from the incident. The stored file and its custody record are kept."
                    confirmLabel="Remove"
                    url={IncidentEvidenceController.destroy(item.ulid).url}
                    method="delete"
                    destructive
                    requireReason
                />
            </DialogContent>
        </Dialog>
    );
}

function Viewer({ item }: { item: EvidenceItem }) {
    if (item.type.value === 'image') {
        return item.has_preview ? (
            <img
                src={EvidenceFileController.preview(item.ulid).url}
                alt={item.title}
                className="max-h-[60vh] w-full rounded-md bg-muted object-contain"
            />
        ) : (
            <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                The preview is being prepared.
            </p>
        );
    }

    if (item.type.value === 'video') {
        return (
            <video
                controls
                preload="metadata"
                className="max-h-[60vh] w-full rounded-md bg-black"
                src={EvidenceFileController.stream(item.ulid).url}
            />
        );
    }

    if (item.type.value === 'audio') {
        return (
            <audio
                controls
                preload="metadata"
                className="w-full"
                src={EvidenceFileController.stream(item.ulid).url}
            />
        );
    }

    return (
        <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
            Documents are not shown in the browser. Download the original to
            open it.
        </p>
    );
}

/** The chain of custody, fetched when the item is opened. */
function Custody({ ulid }: { ulid: string }) {
    const [entries, setEntries] = useState<CustodyEntry[] | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;

        getJson<{ entries: CustodyEntry[] }>(
            IncidentEvidenceController.custody(ulid).url,
        )
            .then((data) => active && setEntries(data.entries))
            .catch(() => active && setFailed(true));

        return () => {
            active = false;
        };
    }, [ulid]);

    return (
        <section aria-label="Custody" className="space-y-2">
            <h3 className="text-sm font-semibold">Custody</h3>
            {failed ? (
                <p className="text-sm text-destructive">
                    The custody record could not be loaded.
                </p>
            ) : entries === null ? (
                <p className="text-sm text-muted-foreground">Loading…</p>
            ) : (
                <ol className="divide-y rounded-md border text-sm">
                    {entries.map((entry, index) => (
                        <li
                            key={index}
                            className={
                                entry.event === 'integrity_failure'
                                    ? 'bg-destructive/10 px-3 py-2'
                                    : 'px-3 py-2'
                            }
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="font-medium">
                                    {entry.label}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(entry.at)}
                                </span>
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {entry.who ?? 'System'}
                                {entry.ip && ` · ${entry.ip}`}
                                {entry.detail && (
                                    <span className="block break-all">
                                        {entry.detail}
                                    </span>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
            )}
        </section>
    );
}

function EditEvidence({
    item,
    incidentLevel,
    onClose,
}: {
    item: EvidenceItem;
    incidentLevel: ClassificationLevel;
    onClose: () => void;
}) {
    const form = useForm({
        title: item.title,
        description: item.description ?? '',
        captured_at: item.captured_at_local ?? '',
        latitude: item.latitude?.toString() ?? '',
        longitude: item.longitude?.toString() ?? '',
        classification: item.classification.value,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            description: data.description || null,
            captured_at: data.captured_at || null,
            latitude: data.latitude || null,
            longitude: data.longitude || null,
        }));
        form.put(IncidentEvidenceController.update(item.ulid).url, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-3">
                    <DialogHeader>
                        <DialogTitle>Edit evidence details</DialogTitle>
                        <DialogDescription>
                            The file itself never changes. Every edit is in the
                            custody record.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="ev-title">Title</Label>
                        <Input
                            id="ev-title"
                            value={form.data.title}
                            onChange={(e) =>
                                form.setData('title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.title} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ev-description">Description</Label>
                        <Textarea
                            id="ev-description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ev-captured">Captured at</Label>
                        <Input
                            id="ev-captured"
                            type="datetime-local"
                            value={form.data.captured_at}
                            onChange={(e) =>
                                form.setData('captured_at', e.target.value)
                            }
                        />
                        <InputError message={form.errors.captured_at} />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="grid gap-1">
                            <Label htmlFor="ev-lat">Latitude</Label>
                            <Input
                                id="ev-lat"
                                inputMode="decimal"
                                value={form.data.latitude}
                                onChange={(e) =>
                                    form.setData('latitude', e.target.value)
                                }
                            />
                            <InputError message={form.errors.latitude} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="ev-lng">Longitude</Label>
                            <Input
                                id="ev-lng"
                                inputMode="decimal"
                                value={form.data.longitude}
                                onChange={(e) =>
                                    form.setData('longitude', e.target.value)
                                }
                            />
                            <InputError message={form.errors.longitude} />
                        </div>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ev-classification">
                            Classification
                        </Label>
                        <ClassificationSelect
                            id="ev-classification"
                            value={form.data.classification}
                            minimum={incidentLevel}
                            onChange={(level) =>
                                form.setData('classification', level)
                            }
                        />
                        <InputError message={form.errors.classification} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
