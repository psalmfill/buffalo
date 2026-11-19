import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import TaskingController from '@/actions/App/Http/Controllers/Taskings/TaskingController';
import EmptyState from '@/components/empty-state';
import ExportListButton from '@/components/exports/export-list-button';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import {
    PriorityBadge,
    TaskingStatusBadge,
    useLiveTaskings,
} from '@/components/taskings/tasking-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCurrentUser } from '@/hooks/use-auth';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { TaskingOptions, TaskingRow } from '@/types/taskings';

type Filters = {
    status: string;
    priority: string;
    target: string;
    overdue: string;
    unread: string;
    state: string;
    zone: string;
    q: string;
};

const BOX_LABELS: Record<string, string> = {
    sent: 'Sent',
    drafts: 'Drafts',
    inbox: 'Inbox',
};

/**
 * Taskings (Phase 11H). Directors: Sent and Drafts; LGA chiefs: Inbox
 * (their LGA's taskings only, never which other LGAs received them);
 * national users: every tasking in reach, read-only.
 */
export default function TaskingsIndex({
    taskings,
    box,
    boxes,
    filters,
    options,
    states,
    zones,
    isLga,
    can,
}: {
    taskings: Paginated<TaskingRow>;
    box: string;
    boxes: string[];
    filters: Filters;
    options: TaskingOptions;
    states: Option[];
    zones: Option[];
    isLga: boolean;
    can: { create: boolean };
}) {
    const user = useCurrentUser();
    const { values, setValues, apply, reset } = useFilters(
        TaskingController.index({ query: { box } }).url,
        filters,
    );
    useLiveTaskings(
        isLga || user.jurisdiction.level !== 'state'
            ? `users.${user.ulid}`
            : `states.${user.jurisdiction.state?.ulid ?? ''}.taskings`,
        ['taskings'],
    );
    const drafts = box === 'drafts';
    const national = !isLga && user.jurisdiction.level !== 'state';

    const select = (
        field: keyof Filters,
        label: string,
        items: Option[],
        all: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <NativeSelect
                id={`filter-${field}`}
                value={values[field]}
                onChange={(e) => apply({ [field]: e.target.value })}
            >
                <option value="">{all}</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    return (
        <>
            <Head title="Taskings" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{ topic: 'guides/taskings' }}
                        title="Taskings"
                        description={
                            isLga
                                ? 'Instructions from your State Director to your LGA. Acknowledge each one and report back when it is done.'
                                : national
                                  ? 'Instructions from State Directors to their LGA Security Chiefs, read-only.'
                                  : 'Instructions to the LGA Security Chiefs of your state, and who has read and acted on them.'
                        }
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {!drafts && (
                            <ExportListButton
                                list="taskings"
                                filters={{ ...filters, box }}
                            />
                        )}
                        {can.create && (
                            <Button asChild>
                                <Link
                                    href={TaskingController.create()}
                                    data-test="new-tasking"
                                >
                                    <Plus /> New tasking
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {boxes.length > 1 && (
                    <nav
                        className="flex gap-1 border-b"
                        aria-label="Tasking boxes"
                    >
                        {boxes.map((name) => (
                            <Link
                                key={name}
                                href={TaskingController.index({
                                    query: { box: name },
                                })}
                                className={cn(
                                    '-mb-px border-b-2 px-3 py-2 text-sm',
                                    box === name
                                        ? 'border-primary font-medium'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                                aria-current={box === name ? 'page' : undefined}
                            >
                                {BOX_LABELS[name] ?? name}
                            </Link>
                        ))}
                    </nav>
                )}

                {!drafts && (
                    <FilterBar onApply={() => apply()} onReset={reset}>
                        <div className="grid gap-2">
                            <Label htmlFor="filter-q">Reference or title</Label>
                            <Input
                                id="filter-q"
                                value={values.q}
                                onChange={(e) =>
                                    setValues({ ...values, q: e.target.value })
                                }
                            />
                        </div>
                        {states.length > 0 &&
                            select('state', 'State', states, 'All states')}
                        {zones.length > 0 &&
                            select('zone', 'Zone', zones, 'All zones')}
                        {select(
                            'priority',
                            'Priority',
                            options.priorities,
                            'Any',
                        )}
                        {select(
                            'status',
                            'Status',
                            options.statuses.filter((s) => s.value !== 'draft'),
                            'Any',
                        )}
                        {!isLga &&
                            select(
                                'target',
                                'Sent to',
                                options.targetModes,
                                'Any',
                            )}
                        {select(
                            'overdue',
                            'Overdue',
                            [{ value: 'yes', label: 'Overdue only' }],
                            'Any',
                        )}
                        {isLga &&
                            select(
                                'unread',
                                'Unread',
                                [{ value: 'yes', label: 'Unread only' }],
                                'Any',
                            )}
                    </FilterBar>
                )}

                <div className="overflow-x-auto rounded-lg border">
                    <table
                        className="w-full text-sm"
                        data-test="taskings-table"
                    >
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Tasking
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Priority
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    {drafts ? 'Last saved' : 'Sent'}
                                </th>
                                <th className="px-3 py-2 font-medium">Due</th>
                                <th className="px-3 py-2 font-medium">
                                    {isLga ? 'Your status' : 'Progress'}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {taskings.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-0">
                                        <EmptyState
                                            title={
                                                drafts
                                                    ? 'No drafts'
                                                    : 'No taskings'
                                            }
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {taskings.data.map((t) => {
                                const unread = t.mine?.unread === true;
                                const overdue = isLga
                                    ? t.mine?.overdue === true
                                    : (t.progress?.overdue ?? 0) > 0;

                                return (
                                    <tr key={t.ulid} data-test="tasking-row">
                                        <td className="px-3 py-2">
                                            <Link
                                                href={
                                                    drafts
                                                        ? TaskingController.edit(
                                                              t.ulid,
                                                          )
                                                        : TaskingController.show(
                                                              t.ulid,
                                                          )
                                                }
                                                className={cn(
                                                    'hover:underline',
                                                    unread
                                                        ? 'font-semibold'
                                                        : 'font-medium',
                                                )}
                                            >
                                                {t.has_title
                                                    ? t.title
                                                    : t.first_line || t.title}
                                            </Link>
                                            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                {t.reference ?? 'Draft'}
                                                {national &&
                                                    t.state &&
                                                    ` · ${t.state}`}
                                                {t.target_mode &&
                                                    ` · ${t.target_mode.label}`}
                                                {t.status.value ===
                                                    'recalled' && (
                                                    <TaskingStatusBadge
                                                        status={t.status}
                                                    />
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            <PriorityBadge
                                                priority={t.priority}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            {formatDateTime(
                                                drafts
                                                    ? t.updated_at
                                                    : t.sent_at,
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {t.response_due_at
                                                ? formatDateTime(
                                                      t.response_due_at,
                                                  )
                                                : '—'}
                                            {overdue && (
                                                <Badge
                                                    variant="destructive"
                                                    className="ml-2"
                                                    data-test="overdue-flag"
                                                >
                                                    Overdue
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {isLga && t.mine
                                                ? t.mine.status.label
                                                : (t.progress?.line ?? '—')}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <Pagination page={taskings} />
            </div>
        </>
    );
}

TaskingsIndex.layout = {
    breadcrumbs: [{ title: 'Taskings', href: TaskingController.index() }],
};
