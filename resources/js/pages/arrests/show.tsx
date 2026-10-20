import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import ArrestController from '@/actions/App/Http/Controllers/Arrests/ArrestController';
import ArrestStatusController from '@/actions/App/Http/Controllers/Arrests/ArrestStatusController';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import ArrestComparisonNotice from '@/components/arrests/arrest-comparison';
import ClassificationBadge from '@/components/community-information/classification-badge';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import RichTextView from '@/components/rich-text/rich-text-view';
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
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    ArrestComparison,
    ArrestDetail,
    ArrestOptions,
    ArrestPersonRow,
    ArrestTimelineEntry,
} from '@/types/arrests';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'timeline' | 'history';

const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    timeline: 'Status timeline',
    history: 'History',
};

type Props = {
    arrest: ArrestDetail;
    persons: ArrestPersonRow[];
    comparison: ArrestComparison | null;
    tab: Tab;
    timeline: ArrestTimelineEntry[] | null;
    history: Paginated<ActivityEntry> | null;
    options: ArrestOptions;
    can: { update: boolean; delete: boolean; amendIncident: boolean };
};

/** Who a row is, as the viewer may see them (never a hidden name). */
function personLabel(row: ArrestPersonRow): ReactNode {
    if (row.person) {
        return (
            <Link
                href={PersonController.show(row.person.ulid)}
                className="font-medium underline"
            >
                {row.person.name}
            </Link>
        );
    }

    return row.person_hidden ? (
        <span className="text-muted-foreground">
            Person on record (not visible to you)
        </span>
    ) : (
        <span>Unidentified: {row.unidentified_description}</span>
    );
}

/** A status change, arrest-wide or for one person, with a comment. */
function StatusDialog({
    arrestUlid,
    person,
    statuses,
    current,
    onClose,
}: {
    arrestUlid: string;
    person: ArrestPersonRow | null;
    statuses: ArrestOptions['statuses'];
    current: string;
    onClose: () => void;
}) {
    const form = useForm({
        person: person?.ulid ?? null,
        status: current,
        comment: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(ArrestStatusController.store(arrestUlid).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            {person
                                ? 'Change this person’s status'
                                : 'Change the arrest’s status'}
                        </DialogTitle>
                        <DialogDescription>
                            {person
                                ? 'The arrest then shows the most common status of its arrested persons.'
                                : 'Every arrested person takes the new status.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="arrest-status">Status</Label>
                        <NativeSelect
                            id="arrest-status"
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData('status', e.target.value)
                            }
                        >
                            {statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.status} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="arrest-status-comment">Comment</Label>
                        <Textarea
                            id="arrest-status-comment"
                            rows={3}
                            value={form.data.comment}
                            onChange={(e) =>
                                form.setData('comment', e.target.value)
                            }
                        />
                        <InputError message={form.errors.comment} />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Change status
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
            <dd className="text-sm">{children}</dd>
        </div>
    );
}

/** An arrest (Phase 11C): details, arrested persons, timeline and history. */
export default function ArrestShow(props: Props) {
    const { arrest, persons, comparison, tab, options, can } = props;
    const [status, setStatus] = useState<{
        person: ArrestPersonRow | null;
    } | null>(null);
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <Head title={arrest.reference_no} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="flex flex-wrap items-center gap-2 text-xl font-semibold">
                            {arrest.reference_no}
                            <ClassificationBadge
                                level={arrest.classification.value}
                            />
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {arrest.status.label} ·{' '}
                            {formatDateTime(arrest.arrested_at)}
                            {arrest.arrested_at_precision.value !== 'exact' &&
                                ` (${arrest.arrested_at_precision.label.toLowerCase()})`}{' '}
                            · {arrest.lga.name} LGA, {arrest.state.name}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {can.update && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setStatus({ person: null })}
                                >
                                    Change status
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={ArrestController.edit(
                                            arrest.ulid,
                                        )}
                                    >
                                        <Pencil /> Edit
                                    </Link>
                                </Button>
                            </>
                        )}
                        {can.delete && (
                            <Button
                                variant="ghost"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                {comparison && arrest.incident && (
                    <ArrestComparisonNotice
                        comparison={comparison}
                        incidentUlid={arrest.incident.ulid}
                        canAmend={can.amendIncident}
                    />
                )}

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(Object.keys(TAB_LABELS) as Tab[]).map((name) => (
                        <Link
                            key={name}
                            href={ArrestController.show(arrest.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === name ? 'page' : undefined}
                        >
                            {TAB_LABELS[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                        <section
                            className="space-y-3 rounded-lg border p-4"
                            aria-label="Arrested persons"
                        >
                            <h2 className="text-sm font-semibold">
                                Arrested persons ({persons.length})
                            </h2>
                            <ul className="divide-y">
                                {persons.map((row) => (
                                    <li
                                        key={row.ulid}
                                        className="space-y-1 py-3"
                                        data-test="arrested-person"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                            {personLabel(row)}
                                            <span className="flex items-center gap-2">
                                                <span className="text-muted-foreground">
                                                    {row.status.label}
                                                </span>
                                                {can.update && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setStatus({
                                                                person: row,
                                                            })
                                                        }
                                                    >
                                                        Change
                                                    </Button>
                                                )}
                                            </span>
                                        </div>
                                        <p className="text-sm">
                                            {row.offences
                                                .map(
                                                    (o) =>
                                                        o.offence.label +
                                                        (o.details
                                                            ? ` (${o.details})`
                                                            : ''),
                                                )
                                                .join('; ')}
                                        </p>
                                        {row.notes && (
                                            <p className="text-xs whitespace-pre-line text-muted-foreground">
                                                {row.notes}
                                            </p>
                                        )}
                                        {row.suggestion?.kind ===
                                            'poi_detained' && (
                                            <p
                                                className="text-xs"
                                                data-test="suggest-detained"
                                            >
                                                Person of interest (
                                                {row.suggestion.current}).{' '}
                                                <Link
                                                    className="underline"
                                                    href={PersonController.show(
                                                        row.suggestion.person,
                                                        {
                                                            query: {
                                                                tab: 'poi',
                                                            },
                                                        },
                                                    )}
                                                >
                                                    Update their status to
                                                    detained
                                                </Link>
                                            </p>
                                        )}
                                        {row.suggestion?.kind ===
                                            'designate_poi' && (
                                            <p className="text-xs">
                                                <Link
                                                    className="underline"
                                                    href={PersonOfInterestController.create(
                                                        row.suggestion.person,
                                                    )}
                                                >
                                                    Designate as person of
                                                    interest
                                                </Link>
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <section className="space-y-4 rounded-lg border p-4">
                            <dl className="grid gap-3">
                                <Fact label="Arresting agency">
                                    {arrest.agency.name}
                                    {arrest.formation &&
                                        ` · ${arrest.formation.name}`}
                                    {arrest.formation_hidden &&
                                        ' · a formation not visible to you'}
                                </Fact>
                                <Fact label="Linked incident">
                                    {arrest.incident ? (
                                        <Link
                                            className="underline"
                                            href={IncidentController.show(
                                                arrest.incident.ulid,
                                            )}
                                        >
                                            {arrest.incident.reference_no ??
                                                'Draft'}{' '}
                                            · {arrest.incident.title}
                                        </Link>
                                    ) : arrest.incident_hidden ? (
                                        'An incident not visible to you'
                                    ) : (
                                        'None (standalone arrest)'
                                    )}
                                </Fact>
                                <Fact label="Place">
                                    {arrest.community
                                        ? `${arrest.community.name}, `
                                        : ''}
                                    {arrest.lga.name} LGA
                                    {arrest.location_description &&
                                        ` · ${arrest.location_description}`}
                                </Fact>
                                {arrest.notes && (
                                    <Fact label="Notes">
                                        <RichTextView html={arrest.notes} />
                                    </Fact>
                                )}
                            </dl>
                        </section>
                    </div>
                )}

                {tab === 'timeline' && props.timeline && (
                    <ol className="space-y-3">
                        {props.timeline.map((entry, i) => (
                            <li
                                key={i}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <p>
                                    <span className="font-medium">
                                        {formatDateTime(entry.at)}
                                    </span>{' '}
                                    · {entry.scope}:{' '}
                                    {entry.from
                                        ? `${entry.from.label} → ${entry.to.label}`
                                        : entry.to.label}
                                    {entry.who && ` · ${entry.who}`}
                                </p>
                                <p className="whitespace-pre-line text-muted-foreground">
                                    {entry.comment}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}

                {tab === 'history' && props.history && (
                    <div className="space-y-2">
                        <ActivityTable entries={props.history.data} />
                        <Pagination page={props.history} />
                    </div>
                )}
            </div>

            {status && (
                <StatusDialog
                    arrestUlid={arrest.ulid}
                    person={status.person}
                    statuses={options.statuses}
                    current={(status.person?.status ?? arrest.status).value}
                    onClose={() => setStatus(null)}
                />
            )}
            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete ${arrest.reference_no}?`}
                    description="The arrest is removed from lists. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={ArrestController.destroy(arrest.ulid).url}
                />
            )}
        </>
    );
}

ArrestShow.layout = {
    breadcrumbs: [{ title: 'Arrests', href: ArrestController.index() }],
};
