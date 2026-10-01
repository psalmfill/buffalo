import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardCheck, Link2, Pencil, Trash2, Unlink } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import SecurityNoteController from '@/actions/App/Http/Controllers/CommunityInformation/SecurityNoteController';
import FlashpointAssessmentController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointAssessmentController';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import FlashpointLinkController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointLinkController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import ConcernBadge from '@/components/flashpoints/concern-badge';
import {
    CONCERN_COLOURS,
    flashpointCircles,
    formatRadius,
} from '@/components/flashpoints/flashpoint-areas';
import LinkRows from '@/components/flashpoints/link-rows';
import type { LinkRow } from '@/components/flashpoints/link-rows';
import InputError from '@/components/input-error';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { formatDate, formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { NoteRow } from '@/types/community-information';
import type { MapView } from '@/types/dashboard';
import type {
    Assessment,
    ConcernLevel,
    FlashpointDetail,
    FlashpointOptions,
    LinkedIncident,
    LinkedNote,
} from '@/types/flashpoints';
import type { IncidentListRow } from '@/types/incidents';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab =
    'overview' | 'assessments' | 'incidents' | 'security_notes' | 'history';

const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    assessments: 'Assessments',
    incidents: 'Incidents',
    security_notes: 'Security notes',
    history: 'History',
};

type Props = {
    flashpoint: FlashpointDetail;
    tab: Tab;
    map: MapView;
    /** Linked incidents with a location (overview map). */
    incidentMarkers: {
        ulid: string;
        title: string;
        reference_no: string | null;
        latitude: number;
        longitude: number;
    }[];
    latest: Assessment | null;
    assessments: Assessment[] | null;
    incidents: {
        linked: LinkedIncident[];
        suggestions: IncidentListRow[];
        sameCategory: boolean;
        months: number;
        monthOptions: number[];
    } | null;
    securityNotes: { linked: LinkedNote[]; suggestions: NoteRow[] } | null;
    history: Paginated<ActivityEntry> | null;
    options: FlashpointOptions | null;
    can: {
        update: boolean;
        assess: boolean;
        delete: boolean;
        viewIncidents: boolean;
        viewSecurityNotes: boolean;
    };
};

/** One flashpoint or blackspot: area, assessments, links and history. */
export default function FlashpointShow(props: Props) {
    const { flashpoint, tab, can } = props;
    const [deleting, setDeleting] = useState(false);
    const tabs = (Object.keys(TAB_LABELS) as Tab[]).filter(
        (name) =>
            (name !== 'incidents' || can.viewIncidents) &&
            (name !== 'security_notes' || can.viewSecurityNotes),
    );

    return (
        <>
            <Head title={flashpoint.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {flashpoint.name}
                            </h1>
                            <ClassificationBadge
                                level={flashpoint.classification.value}
                            />
                            <Badge variant="outline">
                                {flashpoint.kind.label}
                            </Badge>
                        </div>
                        <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                            <span>{flashpoint.threat_type.name}</span>·
                            <ConcernBadge level={flashpoint.concern_level} />
                            <span>concern</span>·
                            <span>{flashpoint.status.label}</span>·
                            <span>
                                {flashpoint.lga.name} LGA,{' '}
                                {flashpoint.state.name} State
                            </span>
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.assess && (
                            <Button asChild>
                                <Link
                                    href={FlashpointController.show(
                                        flashpoint.ulid,
                                        { query: { tab: 'assessments' } },
                                    )}
                                >
                                    <ClipboardCheck /> Reassess
                                </Link>
                            </Button>
                        )}
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={FlashpointController.edit(
                                        flashpoint.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="outline"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={FlashpointController.show(flashpoint.ulid, {
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

                {tab === 'overview' && <OverviewTab {...props} />}
                {tab === 'assessments' && props.assessments && (
                    <AssessmentsTab
                        flashpoint={flashpoint}
                        assessments={props.assessments}
                        options={props.options}
                        canAssess={can.assess}
                    />
                )}
                {tab === 'incidents' && props.incidents && (
                    <IncidentsTab
                        flashpoint={flashpoint}
                        data={props.incidents}
                        canLink={can.update}
                    />
                )}
                {tab === 'security_notes' && props.securityNotes && (
                    <NotesTab
                        flashpoint={flashpoint}
                        data={props.securityNotes}
                        canLink={can.update}
                    />
                )}
                {tab === 'history' && props.history && (
                    <div className="space-y-2">
                        <ActivityTable entries={props.history.data} />
                        <Pagination page={props.history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${flashpoint.name}"?`}
                    description="The flashpoint is removed from lists and maps. The record, its assessments and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={FlashpointController.destroy(flashpoint.ulid).url}
                />
            )}
        </>
    );
}

function OverviewTab({ flashpoint, map, latest, incidentMarkers }: Props) {
    const markers = useMemo<MapMarker[]>(
        () =>
            incidentMarkers.map((i) => ({
                id: i.ulid,
                position: [i.latitude, i.longitude],
                title: i.title,
                popup: i.reference_no ?? undefined,
                href: IncidentController.show(i.ulid).url,
                color: '#1e293b',
                shape: 'diamond',
            })),
        [incidentMarkers],
    );
    const circles = useMemo(
        () =>
            flashpointCircles(
                [flashpoint],
                (ulid) => FlashpointController.show(ulid).url,
            ),
        [flashpoint],
    );

    return (
        <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
            <div className="space-y-6">
                <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                    <Fact label="Kind" value={flashpoint.kind.label} />
                    <Fact
                        label="Threat type"
                        value={flashpoint.threat_type.name}
                    />
                    <Fact
                        label="Community"
                        value={flashpoint.community?.name ?? null}
                    />
                    <Fact
                        label="Radius"
                        value={formatRadius(flashpoint.radius_meters)}
                    />
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Next review
                        </dt>
                        <dd className="text-sm">
                            {formatDate(flashpoint.review_due_at)}
                            {flashpoint.review_overdue && (
                                <Badge variant="destructive" className="ml-2">
                                    Overdue
                                </Badge>
                            )}
                        </dd>
                    </div>
                    <Fact
                        label="Last assessed"
                        value={`${formatDateTime(flashpoint.last_assessed_at)}${flashpoint.last_assessed_by ? ` by ${flashpoint.last_assessed_by}` : ''}`}
                    />
                    <div className="sm:col-span-2">
                        <Fact
                            label="Description"
                            value={flashpoint.description}
                        />
                    </div>
                </dl>

                <section
                    className="space-y-3 rounded-lg border p-4"
                    aria-label="Current assessment"
                >
                    <h2 className="text-sm font-semibold">
                        Current assessment
                    </h2>
                    {latest ? (
                        <AssessmentBody assessment={latest} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No assessment recorded.
                        </p>
                    )}
                </section>
            </div>
            <BuffaloMap
                className="h-96 w-full rounded-lg border"
                center={[flashpoint.latitude, flashpoint.longitude]}
                zoom={13}
                outline={map.outline}
                circles={circles}
                fitCircles
                markers={markers}
            />
        </div>
    );
}

function AssessmentBody({ assessment }: { assessment: Assessment }) {
    return (
        <div className="space-y-2">
            <p className="flex flex-wrap items-center gap-2 text-sm">
                <ConcernBadge level={assessment.concern_level} />
                <span className="text-muted-foreground">
                    · {assessment.status.label} ·{' '}
                    {formatDateTime(assessment.assessed_at)}
                    {assessment.assessed_by
                        ? ` by ${assessment.assessed_by}`
                        : ''}
                </span>
            </p>
            <p className="text-sm whitespace-pre-line">
                {assessment.assessment}
            </p>
            {assessment.recommended_action && (
                <div>
                    <p className="text-xs text-muted-foreground">
                        Recommended action
                    </p>
                    <p className="text-sm whitespace-pre-line">
                        {assessment.recommended_action}
                    </p>
                </div>
            )}
        </div>
    );
}

function AssessmentsTab({
    flashpoint,
    assessments,
    options,
    canAssess,
}: {
    flashpoint: FlashpointDetail;
    assessments: Assessment[];
    options: FlashpointOptions | null;
    canAssess: boolean;
}) {
    const form = useForm({
        concern_level: flashpoint.concern_level.value as ConcernLevel,
        status: flashpoint.status.value as string,
        assessment: '',
        recommended_action: flashpoint.recommended_action ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(FlashpointAssessmentController.store(flashpoint.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('assessment'),
        });
    };

    return (
        <div className="grid gap-6 lg:grid-cols-[2fr_3fr]">
            {canAssess && options && (
                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-lg border p-4"
                    aria-label="Record assessment"
                >
                    <h2 className="text-sm font-semibold">Record assessment</h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="concern_level">Concern level</Label>
                            <NativeSelect
                                id="concern_level"
                                value={form.data.concern_level}
                                onChange={(e) =>
                                    form.setData(
                                        'concern_level',
                                        e.target.value as ConcernLevel,
                                    )
                                }
                            >
                                {options.concernLevels.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.concern_level} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="status">Status</Label>
                            <NativeSelect
                                id="status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData('status', e.target.value)
                                }
                            >
                                {options.statuses.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.status} />
                        </div>
                    </div>
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <span
                            aria-hidden
                            className="inline-block size-2.5 rounded-full"
                            style={{
                                background:
                                    CONCERN_COLOURS[form.data.concern_level],
                            }}
                        />
                        Next review in{' '}
                        {options.reviewDays[form.data.concern_level]} days.
                    </p>
                    <div className="grid gap-2">
                        <Label htmlFor="assessment">Assessment</Label>
                        <Textarea
                            id="assessment"
                            rows={5}
                            value={form.data.assessment}
                            onChange={(e) =>
                                form.setData('assessment', e.target.value)
                            }
                        />
                        <InputError message={form.errors.assessment} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="recommended_action">
                            Recommended action (optional)
                        </Label>
                        <Textarea
                            id="recommended_action"
                            rows={3}
                            value={form.data.recommended_action}
                            onChange={(e) =>
                                form.setData(
                                    'recommended_action',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.recommended_action} />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Record assessment
                    </Button>
                </form>
            )}
            <section className="space-y-2" aria-label="Assessment history">
                <h2 className="text-sm font-semibold">Assessments</h2>
                {assessments.length === 0 ? (
                    <EmptyState title="No assessments" />
                ) : (
                    <ol className="space-y-3">
                        {assessments.map((a) => (
                            <li key={a.ulid} className="rounded-lg border p-3">
                                <AssessmentBody assessment={a} />
                            </li>
                        ))}
                    </ol>
                )}
            </section>
        </div>
    );
}

function IncidentsTab({
    flashpoint,
    data,
    canLink,
}: {
    flashpoint: FlashpointDetail;
    data: NonNullable<Props['incidents']>;
    canLink: boolean;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const meta = (row: IncidentListRow) =>
        `${row.reference_no ?? 'Draft'} · ${row.type.name} · ${row.lga.name} · ${formatDateTime(row.occurred_at)} · ${row.status.label}`;

    const link = (ulid: string) =>
        router.post(
            FlashpointLinkController.storeIncident(flashpoint.ulid).url,
            { record: ulid },
            {
                preserveScroll: true,
                onStart: () => setBusy(ulid),
                onFinish: () => setBusy(null),
            },
        );
    const unlink = (linkUlid: string) =>
        router.delete(
            FlashpointLinkController.destroyIncident({
                flashpoint: flashpoint.ulid,
                link: linkUlid,
            }).url,
            {
                preserveScroll: true,
                onStart: () => setBusy(linkUlid),
                onFinish: () => setBusy(null),
            },
        );

    const linked: LinkRow[] = data.linked.map((row) => ({
        key: row.link.ulid,
        href: IncidentController.show(row.ulid).url,
        title: row.title,
        meta: meta(row),
        note: row.link.note,
        action: canLink ? (
            <Button
                variant="ghost"
                size="sm"
                disabled={busy === row.link.ulid}
                onClick={() => unlink(row.link.ulid)}
            >
                <Unlink /> Unlink
            </Button>
        ) : undefined,
    }));
    const suggestions: LinkRow[] = data.suggestions.map((row) => ({
        key: row.ulid,
        href: IncidentController.show(row.ulid).url,
        title: row.title,
        meta: meta(row),
        action: (
            <Button
                variant="outline"
                size="sm"
                disabled={busy === row.ulid}
                onClick={() => link(row.ulid)}
            >
                <Link2 /> Link
            </Button>
        ),
    }));

    return (
        <div className="space-y-6">
            <section className="space-y-2">
                <h2 className="text-sm font-semibold">
                    Linked incidents ({data.linked.length})
                </h2>
                <LinkRows
                    rows={linked}
                    empty="No linked incidents you can view."
                    label="Linked incidents"
                />
            </section>
            {canLink && (
                <section className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-sm font-semibold">
                                Suggestions
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Incidents inside the area in the last{' '}
                                {data.months} months that are not linked yet.
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Label htmlFor="months">Period</Label>
                            <NativeSelect
                                id="months"
                                value={String(data.months)}
                                onChange={(e) =>
                                    router.get(
                                        FlashpointController.show(
                                            flashpoint.ulid,
                                            {
                                                query: {
                                                    tab: 'incidents',
                                                    months: e.target.value,
                                                    ...(data.sameCategory
                                                        ? { same_category: 1 }
                                                        : {}),
                                                },
                                            },
                                        ).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
                                    )
                                }
                            >
                                {data.monthOptions.map((m) => (
                                    <option key={m} value={m}>
                                        Last {m} months
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="same_category"
                                checked={data.sameCategory}
                                onCheckedChange={(checked) =>
                                    router.get(
                                        FlashpointController.show(
                                            flashpoint.ulid,
                                            {
                                                query: {
                                                    tab: 'incidents',
                                                    months: data.months,
                                                    ...(checked === true
                                                        ? { same_category: 1 }
                                                        : {}),
                                                },
                                            },
                                        ).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
                                    )
                                }
                            />
                            <Label htmlFor="same_category">
                                Same category as the threat only
                            </Label>
                        </div>
                    </div>
                    <LinkRows
                        rows={suggestions}
                        empty="No suggestions."
                        label="Suggested incidents"
                    />
                </section>
            )}
        </div>
    );
}

function NotesTab({
    flashpoint,
    data,
    canLink,
}: {
    flashpoint: FlashpointDetail;
    data: NonNullable<Props['securityNotes']>;
    canLink: boolean;
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const meta = (row: NoteRow) =>
        `${row.category.label} · ${row.community.name} · observed ${formatDate(row.observed_at)} · ${row.status.label}`;

    const link = (ulid: string) =>
        router.post(
            FlashpointLinkController.storeSecurityNote(flashpoint.ulid).url,
            { record: ulid },
            {
                preserveScroll: true,
                onStart: () => setBusy(ulid),
                onFinish: () => setBusy(null),
            },
        );
    const unlink = (linkUlid: string) =>
        router.delete(
            FlashpointLinkController.destroySecurityNote({
                flashpoint: flashpoint.ulid,
                link: linkUlid,
            }).url,
            {
                preserveScroll: true,
                onStart: () => setBusy(linkUlid),
                onFinish: () => setBusy(null),
            },
        );

    return (
        <div className="space-y-6">
            <section className="space-y-2">
                <h2 className="text-sm font-semibold">
                    Linked security notes ({data.linked.length})
                </h2>
                <LinkRows
                    label="Linked security notes"
                    empty="No linked security notes you can view."
                    rows={data.linked.map((row) => ({
                        key: row.link.ulid,
                        href: SecurityNoteController.show(row.ulid).url,
                        title: row.title,
                        meta: meta(row),
                        note: row.link.note,
                        action: canLink ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={busy === row.link.ulid}
                                onClick={() => unlink(row.link.ulid)}
                            >
                                <Unlink /> Unlink
                            </Button>
                        ) : undefined,
                    }))}
                />
            </section>
            {canLink && (
                <section className="space-y-2">
                    <div>
                        <h2 className="text-sm font-semibold">Suggestions</h2>
                        <p className="text-xs text-muted-foreground">
                            Unresolved notes you can view about communities of
                            this LGA, those inside the area first.
                        </p>
                    </div>
                    <LinkRows
                        label="Suggested security notes"
                        empty="No suggestions."
                        rows={data.suggestions.map((row) => ({
                            key: row.ulid,
                            href: SecurityNoteController.show(row.ulid).url,
                            title: row.title,
                            meta: meta(row),
                            action: (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy === row.ulid}
                                    onClick={() => link(row.ulid)}
                                >
                                    <Link2 /> Link
                                </Button>
                            ),
                        }))}
                    />
                </section>
            )}
        </div>
    );
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm whitespace-pre-line">{value ?? '—'}</dd>
        </div>
    );
}

FlashpointShow.layout = {
    breadcrumbs: [{ title: 'Flashpoints', href: FlashpointController.index() }],
};
