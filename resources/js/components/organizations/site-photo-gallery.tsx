import { router } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    Download,
    ImageUp,
    Pencil,
    Star,
    Trash2,
} from 'lucide-react';
import type { DragEvent, FormEvent } from 'react';
import { useRef, useState } from 'react';
import SitePhotoController from '@/actions/App/Http/Controllers/Organizations/SitePhotoController';
import InputError from '@/components/input-error';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { SitePhoto } from '@/types/organizations';

type Upload = {
    id: number;
    name: string;
    progress: number;
    status: 'waiting' | 'uploading' | 'done' | 'failed';
    error?: string;
};

const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

const formatDate = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/**
 * A site's photos (Phase 11D): cover first, then in the chosen order, with
 * a lightbox of previews. Users who may manage the site can upload (drag
 * and drop, one request per file with progress), edit captions and dates,
 * reorder, set the cover and delete with a reason. Every image is fetched
 * through an authorised route; views of classified sites' photos are
 * recorded in the access log.
 */
export default function SitePhotoGallery({
    siteUlid,
    siteName,
    photos,
    limit,
    canManage,
    logged,
}: {
    siteUlid: string;
    siteName: string;
    photos: SitePhoto[];
    limit: number;
    canManage: boolean;
    /** The site is confidential or secret: photo views are logged. */
    logged: boolean;
}) {
    const [open, setOpen] = useState<number | null>(null);
    const [editing, setEditing] = useState<SitePhoto | null>(null);
    const [deleting, setDeleting] = useState<SitePhoto | null>(null);
    const current = open === null ? null : (photos[open] ?? null);

    const cover = photos.find((p) => p.is_cover) ?? null;
    const others = photos.filter((p) => !p.is_cover);

    const move = (photo: SitePhoto, by: -1 | 1) => {
        const order = [...others];
        const from = order.findIndex((p) => p.ulid === photo.ulid);
        const to = from + by;

        if (from < 0 || to < 0 || to >= order.length) {
            return;
        }

        [order[from], order[to]] = [order[to], order[from]];
        router.put(
            SitePhotoController.reorder(siteUlid).url,
            {
                order: [...(cover ? [cover] : []), ...order].map((p) => p.ulid),
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const setCover = (photo: SitePhoto) =>
        router.put(
            SitePhotoController.cover({ site: siteUlid, photo: photo.ulid })
                .url,
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <section className="space-y-3" aria-label="Photos">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    Photos ({photos.length})
                </h2>
                {logged && photos.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Viewing these photos is recorded in the access log.
                    </p>
                )}
            </div>

            {photos.length === 0 && !canManage && (
                <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                    No photos of this site.
                </p>
            )}

            {photos.length > 0 && (
                <ul
                    className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
                    data-test="site-photos"
                >
                    {photos.map((photo, index) => {
                        const position = others.findIndex(
                            (p) => p.ulid === photo.ulid,
                        );

                        return (
                            <li
                                key={photo.ulid}
                                className="overflow-hidden rounded-lg border"
                            >
                                <button
                                    type="button"
                                    className="relative block aspect-[4/3] w-full bg-muted"
                                    onClick={() => setOpen(index)}
                                    aria-label={`Open photo${photo.caption ? `: ${photo.caption}` : ` ${index + 1}`}`}
                                >
                                    <img
                                        src={photo.urls.thumb}
                                        alt={photo.caption ?? ''}
                                        className="size-full object-cover"
                                        loading="lazy"
                                    />
                                    {photo.is_cover && (
                                        <Badge className="absolute top-1.5 left-1.5">
                                            Cover
                                        </Badge>
                                    )}
                                </button>
                                <div className="space-y-0.5 p-2 text-xs">
                                    <p className="line-clamp-2">
                                        {photo.caption ?? (
                                            <span className="text-muted-foreground">
                                                No caption
                                            </span>
                                        )}
                                    </p>
                                    {photo.taken_on && (
                                        <p className="text-muted-foreground">
                                            Taken {formatDate(photo.taken_on)}
                                        </p>
                                    )}
                                </div>
                                {canManage && (
                                    <div className="flex flex-wrap gap-0.5 border-t p-1">
                                        {!photo.is_cover && (
                                            <>
                                                <IconButton
                                                    label="Move earlier"
                                                    disabled={position <= 0}
                                                    onClick={() =>
                                                        move(photo, -1)
                                                    }
                                                >
                                                    <ArrowLeft />
                                                </IconButton>
                                                <IconButton
                                                    label="Move later"
                                                    disabled={
                                                        position ===
                                                        others.length - 1
                                                    }
                                                    onClick={() =>
                                                        move(photo, 1)
                                                    }
                                                >
                                                    <ArrowRight />
                                                </IconButton>
                                                <IconButton
                                                    label="Set as cover"
                                                    onClick={() =>
                                                        setCover(photo)
                                                    }
                                                >
                                                    <Star />
                                                </IconButton>
                                            </>
                                        )}
                                        <IconButton
                                            label="Edit caption and date"
                                            onClick={() => setEditing(photo)}
                                        >
                                            <Pencil />
                                        </IconButton>
                                        <IconButton
                                            label="Delete photo"
                                            onClick={() => setDeleting(photo)}
                                        >
                                            <Trash2 />
                                        </IconButton>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}

            {canManage && (
                <PhotoUploader
                    siteUlid={siteUlid}
                    remaining={Math.max(0, limit - photos.length)}
                    limit={limit}
                />
            )}

            <Dialog
                open={current !== null}
                onOpenChange={(value) => !value && setOpen(null)}
            >
                {current && open !== null && (
                    <DialogContent className="sm:max-w-4xl">
                        <DialogHeader>
                            <DialogTitle>
                                {siteName}: photo {open + 1} of {photos.length}
                                {current.is_cover ? ' (cover)' : ''}
                            </DialogTitle>
                            <DialogDescription>
                                {current.caption ?? 'No caption'}
                                {current.taken_on
                                    ? ` · Taken ${formatDate(current.taken_on)}`
                                    : ''}
                                {current.uploaded_by
                                    ? ` · Uploaded by ${current.uploaded_by}`
                                    : ''}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex items-center justify-center bg-muted">
                            <img
                                src={current.urls.preview}
                                alt={current.caption ?? ''}
                                className="max-h-[70vh] w-auto object-contain"
                            />
                        </div>
                        <DialogFooter className="flex-row flex-wrap justify-between gap-2 sm:justify-between">
                            <div className="flex gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={open === 0}
                                    onClick={() => setOpen(open - 1)}
                                >
                                    <ChevronLeft /> Previous
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={open === photos.length - 1}
                                    onClick={() => setOpen(open + 1)}
                                >
                                    Next <ChevronRight />
                                </Button>
                            </div>
                            <Button variant="outline" size="sm" asChild>
                                <a href={current.urls.original}>
                                    <Download /> Download original
                                </a>
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                )}
            </Dialog>

            {editing && (
                <EditPhotoDialog
                    siteUlid={siteUlid}
                    photo={editing}
                    onClose={() => setEditing(null)}
                />
            )}
            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(value) => !value && setDeleting(null)}
                    title="Delete this photo?"
                    description="The photo is removed from the site. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={
                        SitePhotoController.destroy({
                            site: siteUlid,
                            photo: deleting.ulid,
                        }).url
                    }
                />
            )}
        </section>
    );
}

function IconButton({
    label,
    onClick,
    disabled = false,
    children,
}: {
    label: string;
    onClick: () => void;
    disabled?: boolean;
    children: React.ReactNode;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-7"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

/**
 * Drag-and-drop (or browse) upload. Files go one at a time, each with its
 * own progress and error; the server checks type, size, malware and the
 * photo limit.
 */
function PhotoUploader({
    siteUlid,
    remaining,
    limit,
}: {
    siteUlid: string;
    remaining: number;
    limit: number;
}) {
    const input = useRef<HTMLInputElement>(null);
    const nextId = useRef(1);
    const [dragging, setDragging] = useState(false);
    const [uploads, setUploads] = useState<Upload[]>([]);
    const busy = uploads.some(
        (u) => u.status === 'waiting' || u.status === 'uploading',
    );

    const update = (id: number, change: Partial<Upload>) =>
        setUploads((list) =>
            list.map((u) => (u.id === id ? { ...u, ...change } : u)),
        );

    const send = (id: number, file: File) =>
        new Promise<void>((resolve) => {
            router.post(
                SitePhotoController.store(siteUlid).url,
                { photo: file },
                {
                    forceFormData: true,
                    preserveScroll: true,
                    preserveState: true,
                    onStart: () => update(id, { status: 'uploading' }),
                    onProgress: (progress) =>
                        update(id, {
                            progress: Math.round(progress?.percentage ?? 0),
                        }),
                    onSuccess: () =>
                        update(id, { status: 'done', progress: 100 }),
                    onError: (errors) =>
                        update(id, {
                            status: 'failed',
                            error:
                                errors.photo ??
                                Object.values(errors)[0] ??
                                'The upload failed.',
                        }),
                    onFinish: () => resolve(),
                },
            );
        });

    const add = async (files: File[]) => {
        const queued = files.map((file) => {
            const id = nextId.current++;
            const accepted = ACCEPTED.includes(file.type);

            return {
                file,
                upload: {
                    id,
                    name: file.name,
                    progress: 0,
                    status: accepted ? 'waiting' : 'failed',
                    error: accepted
                        ? undefined
                        : 'Only JPEG, PNG and WebP images can be uploaded.',
                } satisfies Upload,
            };
        });

        setUploads((list) => [
            ...list.filter((u) => u.status !== 'done'),
            ...queued.map((q) => q.upload),
        ]);

        for (const { file, upload } of queued) {
            if (upload.status === 'waiting') {
                await send(upload.id, file);
            }
        }

        if (input.current) {
            input.current.value = '';
        }
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);

        if (!busy && remaining > 0) {
            void add(Array.from(event.dataTransfer.files));
        }
    };

    return (
        <div className="space-y-2">
            <div
                className={cn(
                    'flex flex-col items-center gap-2 rounded-lg border border-dashed p-4 text-center text-sm',
                    dragging && 'border-primary bg-primary/5',
                    remaining === 0 && 'opacity-60',
                )}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                data-test="photo-dropzone"
            >
                <ImageUp className="size-6 text-muted-foreground" />
                {remaining === 0 ? (
                    <p>
                        This site has the maximum of {limit} photos. Delete one
                        to add another.
                    </p>
                ) : (
                    <>
                        <p>
                            Drag photos here, or{' '}
                            <button
                                type="button"
                                className="font-medium underline"
                                disabled={busy}
                                onClick={() => input.current?.click()}
                            >
                                choose files
                            </button>
                            .
                        </p>
                        <p className="text-xs text-muted-foreground">
                            JPEG, PNG or WebP, up to 15 MB each; {remaining} of{' '}
                            {limit} left. Location and camera data are removed.
                        </p>
                    </>
                )}
                <input
                    ref={input}
                    type="file"
                    multiple
                    accept={ACCEPTED.join(',')}
                    className="sr-only"
                    aria-label="Photo files"
                    data-test="photo-input"
                    onChange={(event) =>
                        void add(Array.from(event.target.files ?? []))
                    }
                />
            </div>
            {uploads.length > 0 && (
                <ul className="space-y-1 text-xs" aria-label="Uploads">
                    {uploads.map((u) => (
                        <li key={u.id} className="space-y-0.5">
                            <div className="flex justify-between gap-2">
                                <span className="truncate">{u.name}</span>
                                <span className="shrink-0 text-muted-foreground">
                                    {u.status === 'waiting' && 'Waiting'}
                                    {u.status === 'uploading' &&
                                        `${u.progress}%`}
                                    {u.status === 'done' && 'Uploaded'}
                                    {u.status === 'failed' && 'Failed'}
                                </span>
                            </div>
                            {u.status === 'uploading' && (
                                <div
                                    className="h-1 overflow-hidden rounded bg-muted"
                                    role="progressbar"
                                    aria-valuenow={u.progress}
                                    aria-valuemin={0}
                                    aria-valuemax={100}
                                    aria-label={`Uploading ${u.name}`}
                                >
                                    <div
                                        className="h-full bg-primary transition-all"
                                        style={{ width: `${u.progress}%` }}
                                    />
                                </div>
                            )}
                            {u.error && <InputError message={u.error} />}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function EditPhotoDialog({
    siteUlid,
    photo,
    onClose,
}: {
    siteUlid: string;
    photo: SitePhoto;
    onClose: () => void;
}) {
    const [caption, setCaption] = useState(photo.caption ?? '');
    const [takenOn, setTakenOn] = useState(photo.taken_on ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.put(
            SitePhotoController.update({ site: siteUlid, photo: photo.ulid })
                .url,
            { caption, taken_on: takenOn },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
                onError: (e) => setErrors(e),
            },
        );
    };

    return (
        <Dialog open onOpenChange={(value) => !value && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Photo details</DialogTitle>
                        <DialogDescription>
                            A short caption helps officers recognise the
                            facility.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1">
                        <Label htmlFor="photo-caption">Caption</Label>
                        <Input
                            id="photo-caption"
                            value={caption}
                            maxLength={200}
                            onChange={(e) => setCaption(e.target.value)}
                        />
                        <InputError message={errors.caption} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="photo-taken-on">Date taken</Label>
                        <Input
                            id="photo-taken-on"
                            type="date"
                            value={takenOn}
                            onChange={(e) => setTakenOn(e.target.value)}
                        />
                        <InputError message={errors.taken_on} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
