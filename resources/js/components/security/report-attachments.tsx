import { router } from '@inertiajs/react';
import {
    Download,
    FileVideo,
    ImageIcon,
    Loader2,
    Upload,
    X,
} from 'lucide-react';
import type { DragEvent } from 'react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import ReportAttachmentController from '@/actions/App/Http/Controllers/Security/ReportAttachmentController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ClassificationSelect from '@/components/community-information/classification-select';
import { formatBytes } from '@/components/evidence/evidence-details';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import { formatDateTime } from '@/lib/time';
import type { ClassificationLevel } from '@/types/auth';
import type { ReportAttachment, ReportLimits } from '@/types/coverage';

type Pending = {
    key: string;
    file: File;
    caption: string;
    classification: ClassificationLevel;
    progress: number | null;
    errors: Record<string, string>;
};

const ACCEPT = '.jpg,.jpeg,.png,.webp,.mp4,.m4v,.webm';

let counter = 0;

/**
 * Photos and videos of a security report (Phase 11G): upload while the
 * report is a draft or returned (one request per file, with progress), an
 * image gallery opening a larger preview, and an inline player for videos.
 * Files are served only through their own routes, after the malware scan;
 * items above the viewer's clearance are never sent by the server.
 */
export default function ReportAttachments({
    reportUlid,
    reportLevel,
    attachments,
    canUpload,
    limits,
}: {
    reportUlid: string;
    reportLevel: ClassificationLevel;
    attachments: ReportAttachment[];
    canUpload: boolean;
    limits: Pick<ReportLimits, 'maxAttachments' | 'imageMb' | 'videoMb'>;
}) {
    const [pending, setPending] = useState<Pending[]>([]);
    const [dragging, setDragging] = useState(false);
    const [open, setOpen] = useState<ReportAttachment | null>(null);
    const [editing, setEditing] = useState<ReportAttachment | null>(null);
    const [removing, setRemoving] = useState<ReportAttachment | null>(null);
    const input = useRef<HTMLInputElement>(null);

    const images = attachments.filter((a) => a.type.value === 'image');
    const videos = attachments.filter((a) => a.type.value === 'video');
    const room = limits.maxAttachments - attachments.length - pending.length;

    const add = (files: FileList | null) => {
        if (!files) {
            return;
        }

        // Copy now: a FileList is live, and the input is cleared right after.
        const added: Pending[] = Array.from(files).map((file) => ({
            key: `a-${++counter}`,
            file,
            caption: '',
            classification: reportLevel,
            progress: null,
            errors: {},
        }));

        setPending((rows) => [...rows, ...added]);
    };

    const update = (key: string, changes: Partial<Pending>) =>
        setPending((rows) =>
            rows.map((r) => (r.key === key ? { ...r, ...changes } : r)),
        );

    const upload = (row: Pending): Promise<boolean> =>
        new Promise((resolve) => {
            const data = new FormData();
            data.append('file', row.file);
            data.append('caption', row.caption);
            data.append('classification', row.classification);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', ReportAttachmentController.store(reportUlid).url);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-XSRF-TOKEN', xsrfToken());
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = (event) => {
                if (event.lengthComputable) {
                    update(row.key, {
                        progress: Math.round(
                            (event.loaded / event.total) * 100,
                        ),
                    });
                }
            };
            xhr.onload = () => {
                if (xhr.status === 201) {
                    const notice = (
                        JSON.parse(xhr.responseText) as {
                            notice: string | null;
                        }
                    ).notice;

                    if (notice) {
                        toast.warning(notice);
                    }

                    setPending((rows) => rows.filter((r) => r.key !== row.key));
                    resolve(true);

                    return;
                }

                let errors: Record<string, string> = {
                    file: 'The upload failed. Try again.',
                };

                if (xhr.status === 413) {
                    errors = { file: 'The file is too large.' };
                } else if (xhr.status === 422) {
                    try {
                        const body = JSON.parse(xhr.responseText) as {
                            errors?: Record<string, string[]>;
                        };
                        errors = Object.fromEntries(
                            Object.entries(body.errors ?? {}).map(([k, v]) => [
                                k,
                                v[0],
                            ]),
                        );
                    } catch {
                        // Keep the generic message.
                    }
                } else if (xhr.status === 403 || xhr.status === 404) {
                    errors = {
                        file: 'You cannot add attachments to this report.',
                    };
                }

                update(row.key, { progress: null, errors });
                resolve(false);
            };
            xhr.onerror = () => {
                update(row.key, {
                    progress: null,
                    errors: {
                        file: 'The upload failed. Check the connection and try again.',
                    },
                });
                resolve(false);
            };

            update(row.key, { progress: 0, errors: {} });
            xhr.send(data);
        });

    const uploadAll = async () => {
        let any = false;

        for (const row of pending.filter((r) => r.progress === null)) {
            any = (await upload(row)) || any;
        }

        if (any) {
            router.reload({ only: ['attachments'] });
        }
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        add(event.dataTransfer.files);
    };

    const busy = pending.some((r) => r.progress !== null);

    return (
        <section
            id="attachments"
            aria-labelledby="attachments-heading"
            className="space-y-4 rounded-lg border p-4"
            data-test="report-attachments"
        >
            <h2 id="attachments-heading" className="text-sm font-semibold">
                Photos and videos ({attachments.length})
            </h2>

            {canUpload && (
                <div className="space-y-3">
                    <div
                        onDragOver={(e) => {
                            e.preventDefault();
                            setDragging(true);
                        }}
                        onDragLeave={() => setDragging(false)}
                        onDrop={onDrop}
                        className={`flex flex-col items-center gap-2 rounded-lg border-2 border-dashed p-6 text-center text-sm ${dragging ? 'border-primary bg-primary/5' : 'text-muted-foreground'}`}
                    >
                        <Upload className="size-5" />
                        <p>Drop photos or videos here</p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={room <= 0}
                            onClick={() => input.current?.click()}
                        >
                            Choose files
                        </Button>
                        <input
                            ref={input}
                            type="file"
                            multiple
                            accept={ACCEPT}
                            className="sr-only"
                            aria-label="Photos and videos"
                            data-test="attachment-input"
                            onChange={(e) => {
                                add(e.target.files);
                                e.target.value = '';
                            }}
                        />
                        <p className="text-xs">
                            JPEG, PNG or WebP photos up to {limits.imageMb} MB;
                            MP4 or WebM videos up to {limits.videoMb} MB; at
                            most {limits.maxAttachments} per report. Files are
                            checked for malware and stored exactly as uploaded.
                        </p>
                    </div>

                    {pending.map((row) => (
                        <div
                            key={row.key}
                            className="grid gap-3 rounded-lg border p-3 md:grid-cols-[1fr_1fr_auto]"
                            data-test="pending-attachment"
                        >
                            <div className="space-y-1">
                                <p className="truncate text-sm font-medium">
                                    {row.file.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatBytes(row.file.size)}
                                    {row.progress !== null &&
                                        ` · ${row.progress}%`}
                                </p>
                                <InputError message={row.errors.file} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor={`caption-${row.key}`}>
                                    Caption (optional)
                                </Label>
                                <Input
                                    id={`caption-${row.key}`}
                                    maxLength={200}
                                    value={row.caption}
                                    onChange={(e) =>
                                        update(row.key, {
                                            caption: e.target.value,
                                        })
                                    }
                                />
                                <InputError message={row.errors.caption} />
                                <Label htmlFor={`level-${row.key}`}>
                                    Classification
                                </Label>
                                <ClassificationSelect
                                    id={`level-${row.key}`}
                                    value={row.classification}
                                    minimum={reportLevel}
                                    onChange={(level) =>
                                        update(row.key, {
                                            classification: level,
                                        })
                                    }
                                />
                                <InputError
                                    message={row.errors.classification}
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label={`Remove ${row.file.name}`}
                                disabled={row.progress !== null}
                                onClick={() =>
                                    setPending((rows) =>
                                        rows.filter((r) => r.key !== row.key),
                                    )
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}

                    {pending.length > 0 && (
                        <Button
                            type="button"
                            disabled={busy}
                            onClick={() => void uploadAll()}
                            data-test="upload-attachments"
                        >
                            {busy && <Loader2 className="animate-spin" />}
                            Upload {pending.length}{' '}
                            {pending.length === 1 ? 'file' : 'files'}
                        </Button>
                    )}
                </div>
            )}

            {attachments.length === 0 && pending.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    No photos or videos.
                </p>
            )}

            {images.length > 0 && (
                <ul
                    className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                    aria-label="Photos"
                >
                    {images.map((item) => (
                        <li key={item.ulid} className="space-y-1">
                            <button
                                type="button"
                                className="block aspect-square w-full overflow-hidden rounded-md border bg-muted"
                                onClick={() => setOpen(item)}
                                disabled={!item.has_preview}
                                aria-label={`Open ${item.caption ?? 'photo'}`}
                            >
                                {item.has_thumb ? (
                                    <img
                                        src={
                                            ReportAttachmentController.thumb(
                                                item.ulid,
                                            ).url
                                        }
                                        alt={item.caption ?? ''}
                                        className="size-full object-cover"
                                    />
                                ) : (
                                    <span className="flex size-full flex-col items-center justify-center gap-1 text-xs text-muted-foreground">
                                        <ImageIcon className="size-5" />
                                        {item.scan.value === 'clean'
                                            ? 'Preparing preview…'
                                            : item.scan.label}
                                    </span>
                                )}
                            </button>
                            <AttachmentMeta
                                item={item}
                                onEdit={() => setEditing(item)}
                                onRemove={() => setRemoving(item)}
                            />
                        </li>
                    ))}
                </ul>
            )}

            {videos.length > 0 && (
                <ul className="grid gap-4 md:grid-cols-2" aria-label="Videos">
                    {videos.map((item) => (
                        <li key={item.ulid} className="space-y-1">
                            {item.playable ? (
                                <video
                                    controls
                                    preload="metadata"
                                    className="w-full rounded-md border bg-black"
                                    src={
                                        ReportAttachmentController.stream(
                                            item.ulid,
                                        ).url
                                    }
                                />
                            ) : (
                                <div className="flex aspect-video items-center justify-center gap-2 rounded-md border bg-muted text-sm text-muted-foreground">
                                    <FileVideo className="size-5" />
                                    {item.scan.label}
                                </div>
                            )}
                            <AttachmentMeta
                                item={item}
                                onEdit={() => setEditing(item)}
                                onRemove={() => setRemoving(item)}
                            />
                        </li>
                    ))}
                </ul>
            )}

            <Dialog
                open={open !== null}
                onOpenChange={(value) => !value && setOpen(null)}
            >
                <DialogContent className="max-w-4xl">
                    <DialogHeader>
                        <DialogTitle>{open?.caption ?? 'Photo'}</DialogTitle>
                        <DialogDescription>
                            {open?.captured_at
                                ? `Captured ${formatDateTime(open.captured_at)}`
                                : 'Capture time unknown'}
                        </DialogDescription>
                    </DialogHeader>
                    {open?.has_preview && (
                        <img
                            src={
                                ReportAttachmentController.preview(open.ulid)
                                    .url
                            }
                            alt={open.caption ?? ''}
                            className="max-h-[70vh] w-full object-contain"
                        />
                    )}
                </DialogContent>
            </Dialog>

            {editing && (
                <EditAttachment
                    item={editing}
                    reportLevel={reportLevel}
                    onClose={() => setEditing(null)}
                />
            )}
            {removing && (
                <RemoveAttachment
                    item={removing}
                    onClose={() => setRemoving(null)}
                />
            )}
        </section>
    );
}

function AttachmentMeta({
    item,
    onEdit,
    onRemove,
}: {
    item: ReportAttachment;
    onEdit: () => void;
    onRemove: () => void;
}) {
    return (
        <div className="space-y-1 text-xs">
            <p className="font-medium">{item.caption ?? 'No caption'}</p>
            <div className="flex flex-wrap items-center gap-1 text-muted-foreground">
                <ClassificationBadge level={item.classification.value} />
                <span>{formatBytes(item.size_bytes)}</span>
                {item.processing && <span>· processing</span>}
            </div>
            <div className="flex flex-wrap gap-2">
                {item.can.download && (
                    <a
                        href={
                            ReportAttachmentController.download(item.ulid).url
                        }
                        className="inline-flex items-center gap-1 underline"
                    >
                        <Download className="size-3" /> Original
                    </a>
                )}
                {item.can.update && (
                    <button
                        type="button"
                        className="underline"
                        onClick={onEdit}
                    >
                        Edit
                    </button>
                )}
                {item.can.delete && (
                    <button
                        type="button"
                        className="text-destructive underline"
                        onClick={onRemove}
                    >
                        Remove
                    </button>
                )}
            </div>
        </div>
    );
}

function EditAttachment({
    item,
    reportLevel,
    onClose,
}: {
    item: ReportAttachment;
    reportLevel: ClassificationLevel;
    onClose: () => void;
}) {
    const [caption, setCaption] = useState(item.caption ?? '');
    const [capturedAt, setCapturedAt] = useState(item.captured_at_local ?? '');
    const [classification, setClassification] = useState<ClassificationLevel>(
        item.classification.value,
    );
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () =>
        router.put(
            ReportAttachmentController.update(item.ulid).url,
            {
                caption,
                captured_at: capturedAt === '' ? null : capturedAt,
                latitude: item.latitude,
                longitude: item.longitude,
                classification,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (e) => setErrors(e as Record<string, string>),
            },
        );

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Edit attachment</DialogTitle>
                    <DialogDescription>
                        The file itself never changes.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-3">
                    <div className="grid gap-2">
                        <Label htmlFor="edit-caption">Caption</Label>
                        <Input
                            id="edit-caption"
                            maxLength={200}
                            value={caption}
                            onChange={(e) => setCaption(e.target.value)}
                        />
                        <InputError message={errors.caption} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-captured">Captured at</Label>
                        <Input
                            id="edit-captured"
                            type="datetime-local"
                            value={capturedAt}
                            onChange={(e) => setCapturedAt(e.target.value)}
                        />
                        <InputError message={errors.captured_at} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="edit-level">Classification</Label>
                        <ClassificationSelect
                            id="edit-level"
                            value={classification}
                            minimum={reportLevel}
                            onChange={setClassification}
                        />
                        <InputError message={errors.classification} />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RemoveAttachment({
    item,
    onClose,
}: {
    item: ReportAttachment;
    onClose: () => void;
}) {
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | undefined>();

    const remove = () =>
        router.delete(ReportAttachmentController.destroy(item.ulid).url, {
            data: { reason },
            preserveScroll: true,
            onSuccess: onClose,
            onError: (e) => setError((e as Record<string, string>).reason),
        });

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Remove this attachment?</DialogTitle>
                    <DialogDescription>
                        It leaves the report; the stored file is kept and the
                        removal is recorded with your reason.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-2">
                    <Label htmlFor="remove-reason">Reason</Label>
                    <Textarea
                        id="remove-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                    <InputError message={error} />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={reason.trim().length < 5}
                        onClick={remove}
                    >
                        Remove
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
