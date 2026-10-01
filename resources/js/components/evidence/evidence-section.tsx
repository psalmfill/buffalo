import { router } from '@inertiajs/react';
import {
    FileAudio,
    FileText,
    FileVideo,
    ImageIcon,
    Loader2,
    Upload,
    X,
} from 'lucide-react';
import type { DragEvent } from 'react';
import { useRef, useState } from 'react';
import EvidenceFileController from '@/actions/App/Http/Controllers/Evidence/EvidenceFileController';
import IncidentEvidenceController from '@/actions/App/Http/Controllers/Evidence/IncidentEvidenceController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ClassificationSelect from '@/components/community-information/classification-select';
import EvidenceDetails, {
    formatBytes,
} from '@/components/evidence/evidence-details';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { xsrfToken } from '@/lib/http';
import type { ClassificationLevel } from '@/types/auth';
import type {
    EvidenceItem,
    EvidenceKind,
    EvidenceProps,
} from '@/types/evidence';

type Pending = {
    key: string;
    file: File;
    title: string;
    description: string;
    classification: ClassificationLevel;
    progress: number | null;
    errors: Record<string, string>;
};

const ICONS: Record<EvidenceKind, typeof FileText> = {
    image: ImageIcon,
    document: FileText,
    video: FileVideo,
    audio: FileAudio,
};

let counter = 0;

/**
 * Evidence on an incident (Phase 5B): upload (drag and drop, one request per
 * file with progress), an image gallery, and a list of documents, video and
 * audio. Items above the viewer's clearance are never sent by the server.
 */
export default function EvidenceSection({
    evidence,
    incidentUlid,
    incidentLevel,
}: {
    evidence: EvidenceProps;
    incidentUlid: string;
    incidentLevel: ClassificationLevel;
}) {
    const [pending, setPending] = useState<Pending[]>([]);
    const [open, setOpen] = useState<EvidenceItem | null>(null);
    // The fresh copy after a reload; gone once removed (the dialog closes).
    const current =
        open === null
            ? null
            : (evidence.items.find((i) => i.ulid === open.ulid) ?? null);
    const [dragging, setDragging] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    const images = evidence.items.filter((i) => i.type.value === 'image');
    const others = evidence.items.filter((i) => i.type.value !== 'image');
    const accept = evidence.extensions.map((e) => `.${e}`).join(',');

    const add = (files: FileList | null) => {
        if (!files) {
            return;
        }

        // Copy now: a FileList is live, and the input is cleared right after.
        const added: Pending[] = Array.from(files).map((file) => ({
            key: `f-${++counter}`,
            file,
            title: file.name.replace(/\.[^.]+$/, '').slice(0, 200),
            description: '',
            classification: incidentLevel,
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
            data.append('title', row.title);
            data.append('description', row.description);
            data.append('classification', row.classification);

            const xhr = new XMLHttpRequest();
            xhr.open(
                'POST',
                IncidentEvidenceController.store(incidentUlid).url,
            );
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
                        file: 'You cannot add evidence to this incident.',
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
            router.reload({ only: ['evidence'] });
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
            id="evidence"
            aria-labelledby="evidence-heading"
            className="space-y-4 rounded-lg border p-4"
        >
            <h2 id="evidence-heading" className="text-sm font-semibold">
                Evidence
            </h2>

            {evidence.can_upload && (
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
                        <p>Drop images, documents, video or audio here</p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => input.current?.click()}
                        >
                            Choose files
                        </Button>
                        <input
                            ref={input}
                            type="file"
                            multiple
                            accept={accept}
                            className="sr-only"
                            aria-label="Evidence files"
                            data-test="evidence-input"
                            onChange={(e) => {
                                add(e.target.files);
                                e.target.value = '';
                            }}
                        />
                        <p className="text-xs">
                            Up to {evidence.max_mb.image} MB per image or
                            document, {evidence.max_mb.video} MB per video,{' '}
                            {evidence.max_mb.audio} MB per audio file. Originals
                            are stored exactly as uploaded.
                        </p>
                    </div>

                    {pending.map((row) => (
                        <fieldset
                            key={row.key}
                            className="space-y-2 rounded-lg border p-3"
                            aria-label={`Upload ${row.file.name}`}
                        >
                            <div className="flex items-center justify-between gap-2 text-sm">
                                <span className="truncate font-medium">
                                    {row.file.name}{' '}
                                    <span className="text-muted-foreground">
                                        ({formatBytes(row.file.size)})
                                    </span>
                                </span>
                                {row.progress === null && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        aria-label={`Remove ${row.file.name}`}
                                        onClick={() =>
                                            setPending((rows) =>
                                                rows.filter(
                                                    (r) => r.key !== row.key,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                )}
                            </div>
                            <InputError message={row.errors.file} />
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor={`${row.key}-title`}>
                                        Title
                                    </Label>
                                    <Input
                                        id={`${row.key}-title`}
                                        value={row.title}
                                        maxLength={200}
                                        disabled={row.progress !== null}
                                        onChange={(e) =>
                                            update(row.key, {
                                                title: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError message={row.errors.title} />
                                </div>
                                <div className="grid gap-1">
                                    <Label
                                        htmlFor={`${row.key}-classification`}
                                    >
                                        Classification
                                    </Label>
                                    <ClassificationSelect
                                        id={`${row.key}-classification`}
                                        value={row.classification}
                                        minimum={incidentLevel}
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
                                <div className="grid gap-1 sm:col-span-2">
                                    <Label htmlFor={`${row.key}-description`}>
                                        Description (optional)
                                    </Label>
                                    <Textarea
                                        id={`${row.key}-description`}
                                        rows={2}
                                        value={row.description}
                                        disabled={row.progress !== null}
                                        onChange={(e) =>
                                            update(row.key, {
                                                description: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                            {row.progress !== null && (
                                <div
                                    className="h-2 overflow-hidden rounded-full bg-muted"
                                    role="progressbar"
                                    aria-valuenow={row.progress}
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-label={`Uploading ${row.file.name}`}
                                >
                                    <div
                                        className="h-full bg-primary transition-all"
                                        style={{ width: `${row.progress}%` }}
                                    />
                                </div>
                            )}
                        </fieldset>
                    ))}

                    {pending.length > 0 && (
                        <Button
                            type="button"
                            onClick={uploadAll}
                            disabled={busy}
                        >
                            {busy && <Loader2 className="animate-spin" />}
                            Upload{' '}
                            {pending.length === 1
                                ? 'file'
                                : `${pending.length} files`}
                        </Button>
                    )}
                </div>
            )}

            {evidence.items.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No evidence yet.
                </p>
            ) : (
                <>
                    {images.length > 0 && (
                        <ul
                            className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                            aria-label="Images"
                        >
                            {images.map((item) => (
                                <li
                                    key={item.ulid}
                                    id={`evidence-${item.ulid}`}
                                >
                                    <button
                                        type="button"
                                        onClick={() => setOpen(item)}
                                        className="group block w-full overflow-hidden rounded-lg border text-left"
                                    >
                                        {item.has_thumb ? (
                                            <img
                                                src={
                                                    EvidenceFileController.thumb(
                                                        item.ulid,
                                                    ).url
                                                }
                                                alt={item.title}
                                                loading="lazy"
                                                className="aspect-square w-full bg-muted object-cover"
                                            />
                                        ) : (
                                            <span className="flex aspect-square w-full items-center justify-center bg-muted text-xs text-muted-foreground">
                                                Preparing preview…
                                            </span>
                                        )}
                                        <span className="flex items-center justify-between gap-1 p-2 text-xs">
                                            <span className="truncate font-medium">
                                                {item.title}
                                            </span>
                                            <ClassificationBadge
                                                level={
                                                    item.classification.value
                                                }
                                            />
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}

                    {others.length > 0 && (
                        <ul
                            className="divide-y rounded-lg border"
                            aria-label="Documents, video and audio"
                        >
                            {others.map((item) => {
                                const Icon = ICONS[item.type.value];

                                return (
                                    <li
                                        key={item.ulid}
                                        id={`evidence-${item.ulid}`}
                                        className="flex items-center gap-3 px-3 py-2 text-sm"
                                    >
                                        <Icon className="size-5 shrink-0 text-muted-foreground" />
                                        <button
                                            type="button"
                                            onClick={() => setOpen(item)}
                                            className="min-w-0 flex-1 text-left"
                                        >
                                            <span className="block truncate font-medium hover:underline">
                                                {item.title}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {item.type.label} ·{' '}
                                                {formatBytes(item.size_bytes)}
                                                {item.processing &&
                                                    ' · processing'}
                                            </span>
                                        </button>
                                        <ClassificationBadge
                                            level={item.classification.value}
                                        />
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </>
            )}

            {current && (
                <EvidenceDetails
                    item={current}
                    incidentLevel={incidentLevel}
                    onClose={() => setOpen(null)}
                />
            )}
        </section>
    );
}
