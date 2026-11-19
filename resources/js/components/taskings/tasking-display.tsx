import { Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';
import TaskingAttachmentController from '@/actions/App/Http/Controllers/Taskings/TaskingAttachmentController';
import TaskingController from '@/actions/App/Http/Controllers/Taskings/TaskingController';
import type { AttachmentEndpoints } from '@/components/security/report-attachments';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { TaskingOption, TaskingRow } from '@/types/taskings';

/** One colour per priority, as for threat urgencies. */
const PRIORITY_COLOURS: Record<string, string> = {
    routine: '#2563eb',
    priority: '#ea580c',
    immediate: '#b91c1c',
};

export function PriorityBadge({ priority }: { priority: TaskingOption }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span
                aria-hidden
                className="inline-block size-2.5 rounded-full"
                style={{ background: PRIORITY_COLOURS[priority.value] }}
            />
            {priority.label}
        </span>
    );
}

export function TaskingStatusBadge({ status }: { status: TaskingOption }) {
    return (
        <Badge
            variant={
                status.value === 'recalled'
                    ? 'destructive'
                    : status.value === 'draft'
                      ? 'outline'
                      : 'secondary'
            }
        >
            {status.label}
        </Badge>
    );
}

/** Attachments of a tasking (store on the tasking) or of a report back. */
export function taskingAttachmentEndpoints(store: string): AttachmentEndpoints {
    return {
        store,
        thumb: (u) => TaskingAttachmentController.thumb(u).url,
        preview: (u) => TaskingAttachmentController.preview(u).url,
        stream: (u) => TaskingAttachmentController.stream(u).url,
        download: (u) => TaskingAttachmentController.download(u).url,
        update: (u) => TaskingAttachmentController.update(u).url,
        destroy: (u) => TaskingAttachmentController.destroy(u).url,
    };
}

/**
 * Refetches `only` when tasking events arrive (the state's channel for
 * directors, the user's own channel for LGA chiefs), debounced. Events
 * carry only the tasking's ULID.
 */
export function useLiveTaskings(channel: string, only: string[]) {
    const timer = useRef<number | null>(null);

    useEcho(
        channel,
        ['.tasking.changed'],
        () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }

            timer.current = window.setTimeout(
                () => router.reload({ only: [...only, 'badges'] }),
                1000,
            );
        },
        [channel],
    );

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );
}

/** A compact list of taskings (dashboards). */
export function TaskingList({
    rows,
    forChief = false,
}: {
    rows: TaskingRow[];
    forChief?: boolean;
}) {
    return (
        <ul className="divide-y" data-test="tasking-list">
            {rows.map((row) => {
                const unread = forChief && row.mine?.unread === true;
                const overdue = forChief
                    ? row.mine?.overdue === true
                    : (row.progress?.overdue ?? 0) > 0;

                return (
                    <li key={row.ulid} className="py-2">
                        <Link
                            href={TaskingController.show(row.ulid)}
                            className="block space-y-1 rounded-md px-1 hover:bg-muted/50"
                            data-test="tasking-row"
                        >
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <PriorityBadge priority={row.priority} />
                                <span
                                    className={cn(
                                        'flex-1 truncate',
                                        unread ? 'font-semibold' : '',
                                    )}
                                >
                                    {row.has_title
                                        ? row.title
                                        : row.first_line || row.title}
                                </span>
                                {row.status.value === 'recalled' && (
                                    <TaskingStatusBadge status={row.status} />
                                )}
                                {overdue && (
                                    <Badge
                                        variant="destructive"
                                        data-test="overdue-flag"
                                    >
                                        Overdue
                                    </Badge>
                                )}
                                {forChief && row.mine && (
                                    <Badge variant="outline">
                                        {row.mine.status.label}
                                    </Badge>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {row.reference} · sent{' '}
                                {formatDateTime(row.sent_at)}
                                {row.response_due_at &&
                                    ` · due ${formatDateTime(row.response_due_at)}`}
                            </p>
                            {row.progress && (
                                <p
                                    className="text-xs"
                                    data-test="progress-line"
                                >
                                    {row.progress.line}
                                </p>
                            )}
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
