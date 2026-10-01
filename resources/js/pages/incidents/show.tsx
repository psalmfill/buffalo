import { Head, Link } from '@inertiajs/react';
import { Lock, Pencil, Printer, Siren } from 'lucide-react';
import type { ReactNode } from 'react';
import type { IncidentFlashpoints } from '@/types/flashpoints';
import { useMemo, useState } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import { flashpointCircles } from '@/components/flashpoints/flashpoint-areas';
import IncidentFlashpointsSection, {
    NearFlashpointBadge,
} from '@/components/flashpoints/incident-flashpoints';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import DynamicFieldsView from '@/components/dynamic-fields/dynamic-fields-view';
import EvidenceSection from '@/components/evidence/evidence-section';
import IncidentLiveRefresh from '@/components/incidents/incident-live-refresh';
import IncidentTimeline from '@/components/incidents/incident-timeline';
import WorkflowActions, {
    DuplicateList,
    InvalidateDialog,
} from '@/components/incidents/workflow-actions';
import BuffaloMap from '@/components/map/BuffaloMap';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActivityTable from '@/components/users/activity-table';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { MapView } from '@/types/dashboard';
import type {
    DuplicateRow,
    IncidentDetail,
    IncidentRecord,
    TimelineEntry,
    WorkflowCan,
    WorkflowOptions,
    WorkflowStep,
} from '@/types/incidents';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab =
    | 'overview'
    | 'people'
    | 'response'
    | 'evidence'
    | 'timeline'
    | 'duplicates'
    | 'history';

const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    people: 'People',
    response: 'Response',
    evidence: 'Evidence',
    timeline: 'Timeline',
    duplicates: 'Possible duplicates',
    history: 'History',
};

type Props = IncidentRecord & {
    tab: Tab;
    history: Paginated<ActivityEntry> | null;
    timeline: TimelineEntry[] | null;
    possibleDuplicates: DuplicateRow[];
    workflowOptions: WorkflowOptions;
    channels: { state: string; national: boolean };
    openSubmit: boolean;
    map: MapView;
    /** Phase 6A: null without flashpoints.view. */
    flashpoints: IncidentFlashpoints | null;
    can: WorkflowCan;
};

/**
 * An incident, read-only (Phase 5A: basic; the full detail page is 5D).
 * Linked persons, groups, formations and sites the viewer cannot see are
 * shown without their names.
 */
export default function IncidentShow({
    incident,
    casualties,
    persons,
    groups,
    agencies,
    properties,
    evidence,
    tab,
    history,
    timeline,
    possibleDuplicates,
    workflowOptions,
    channels,
    openSubmit,
    map,
    flashpoints,
    can,
}: Props) {
    const record: IncidentRecord = {
        incident,
        casualties,
        persons,
        groups,
        agencies,
        properties,
        evidence,
    };
    const workflow = incident.workflow;
    const [invalidating, setInvalidating] = useState<DuplicateRow | null>(null);
    const flashpointAreas = useMemo(
        () =>
            flashpoints
                ? flashpointCircles(
                      [...flashpoints.linked, ...flashpoints.near],
                      (ulid) => FlashpointController.show(ulid).url,
                  )
                : [],
        [flashpoints],
    );

    return (
        <>
            <Head title={incident.reference_no ?? incident.title} />
            <IncidentLiveRefresh
                incidentUlid={incident.ulid}
                channel={
                    channels.national
                        ? 'national.incidents'
                        : `states.${channels.state}.incidents`
                }
            />
            <div className="space-y-6 p-4">
                <LockBanner incident={incident} />
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <p
                            className="font-mono text-sm font-semibold"
                            data-test="reference"
                        >
                            {incident.reference_no ??
                                'Draft — no reference yet'}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {incident.title}
                            </h1>
                            <NearFlashpointBadge data={flashpoints} />
                            <Badge variant="secondary">
                                {incident.status.label}
                            </Badge>
                            <Badge
                                variant={
                                    incident.severity.value === 'critical'
                                        ? 'destructive'
                                        : 'outline'
                                }
                            >
                                {incident.severity.label}
                            </Badge>
                            {workflow.escalation && (
                                <Badge
                                    variant="destructive"
                                    className="gap-1"
                                    title={
                                        workflow.escalation.reason ?? undefined
                                    }
                                >
                                    <Siren className="size-3" /> Escalated
                                    {workflow.escalation.acknowledged
                                        ? ` · acknowledged by ${workflow.escalation.acknowledged.by ?? 'national level'}`
                                        : ' · awaiting acknowledgement'}
                                </Badge>
                            )}
                            <ClassificationBadge
                                level={incident.classification.value}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {incident.type.name} · {incident.lga.name} LGA,{' '}
                            {incident.state.name} State
                            {workflow.submitted &&
                                ` · submitted ${formatDateTime(workflow.submitted.at)}${workflow.submitted.by ? ` by ${workflow.submitted.by}` : ''}`}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-start gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={IncidentController.edit(
                                        incident.ulid,
                                    )}
                                >
                                    <Pencil />{' '}
                                    {incident.status.value === 'draft'
                                        ? 'Edit draft'
                                        : 'Edit'}
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <a
                                href={
                                    IncidentController.print(incident.ulid).url
                                }
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer /> Print
                            </a>
                        </Button>
                        <WorkflowActions
                            record={record}
                            can={can}
                            options={workflowOptions}
                            possibleDuplicates={possibleDuplicates}
                            openSubmit={openSubmit}
                        />
                    </div>
                </div>

                <WorkflowFacts incident={incident} />

                {possibleDuplicates.length > 0 && tab !== 'duplicates' && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm dark:bg-amber-950"
                    >
                        {possibleDuplicates.length} possible duplicate
                        {possibleDuplicates.length === 1 ? '' : 's'} among the
                        incidents you can see.{' '}
                        <Link
                            href={IncidentController.show(incident.ulid, {
                                query: { tab: 'duplicates' },
                            })}
                            className="underline"
                        >
                            Review
                        </Link>
                    </p>
                )}
                {invalidating && (
                    <InvalidateDialog
                        incidentUlid={incident.ulid}
                        reasons={workflowOptions.invalidReasons}
                        possibleDuplicates={possibleDuplicates}
                        preset={invalidating}
                        onClose={() => setInvalidating(null)}
                    />
                )}

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(
                        [
                            'overview',
                            'people',
                            'response',
                            'evidence',
                            'timeline',
                            ...(can.verify || can.invalidate
                                ? ['duplicates']
                                : []),
                            'history',
                        ] as Tab[]
                    ).map((name) => (
                        <Link
                            key={name}
                            href={IncidentController.show(incident.ulid, {
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
                        <div className="space-y-6">
                            <Section title="What happened">
                                <p className="text-sm whitespace-pre-line">
                                    {incident.description}
                                </p>
                                {incident.type.fields.length > 0 && (
                                    <DynamicFieldsView
                                        fields={incident.type.fields}
                                        values={incident.details}
                                    />
                                )}
                            </Section>

                            <Section title="When and where">
                                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                    <Fact label="Occurred">
                                        {formatDateTime(incident.occurred_at)} (
                                        {incident.occurred_at_precision.label.toLowerCase()}
                                        )
                                    </Fact>
                                    <Fact label="Community">
                                        {incident.community?.name ?? '—'}
                                    </Fact>
                                    <Fact label="Location">
                                        {incident.location_description ?? '—'}
                                    </Fact>
                                    <Fact label="Coordinates">
                                        {incident.latitude !== null &&
                                        incident.longitude !== null
                                            ? `${incident.latitude}, ${incident.longitude}`
                                            : '—'}
                                    </Fact>
                                </dl>
                            </Section>
                            <WorkflowSummary incident={incident} />
                            {flashpoints && (
                                <IncidentFlashpointsSection
                                    data={flashpoints}
                                    incidentUlid={incident.ulid}
                                />
                            )}
                        </div>
                        <BuffaloMap
                            className="h-80"
                            center={map.center ?? undefined}
                            zoom={map.zoom}
                            outline={map.outline}
                            circles={flashpointAreas}
                            fitMarkers
                            markers={
                                incident.latitude !== null &&
                                incident.longitude !== null
                                    ? [
                                          {
                                              id: incident.ulid,
                                              position: [
                                                  incident.latitude,
                                                  incident.longitude,
                                              ],
                                              title: incident.title,
                                          },
                                      ]
                                    : []
                            }
                        />
                    </div>
                )}

                {tab === 'people' && (
                    <div className="max-w-5xl space-y-6">
                        <Section title="Casualties">
                            {casualties.length === 0 ? (
                                <Empty>No casualties recorded.</Empty>
                            ) : (
                                <table className="w-full text-sm">
                                    <thead className="text-left text-muted-foreground">
                                        <tr>
                                            <th className="py-1 font-medium">
                                                Outcome
                                            </th>
                                            <th className="py-1 font-medium">
                                                Who
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Men
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Women
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Boys
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Girls
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Unknown
                                            </th>
                                            <th className="py-1 text-right font-medium">
                                                Total
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {casualties.map((c) => (
                                            <tr key={c.ulid}>
                                                <td className="py-1">
                                                    {c.outcome.label}
                                                </td>
                                                <td className="py-1">
                                                    {c.group.label}
                                                    {c.agency &&
                                                        ` (${c.agency.short_name})`}
                                                </td>
                                                <td className="py-1 text-right">
                                                    {c.adult_male}
                                                </td>
                                                <td className="py-1 text-right">
                                                    {c.adult_female}
                                                </td>
                                                <td className="py-1 text-right">
                                                    {c.child_male}
                                                </td>
                                                <td className="py-1 text-right">
                                                    {c.child_female}
                                                </td>
                                                <td className="py-1 text-right">
                                                    {c.unknown}
                                                </td>
                                                <td className="py-1 text-right font-medium">
                                                    {c.total}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot className="border-t font-medium">
                                        <tr>
                                            <td className="py-1" colSpan={2}>
                                                Total
                                            </td>
                                            {(
                                                [
                                                    'adult_male',
                                                    'adult_female',
                                                    'child_male',
                                                    'child_female',
                                                    'unknown',
                                                    'total',
                                                ] as const
                                            ).map((key) => (
                                                <td
                                                    key={key}
                                                    className="py-1 text-right tabular-nums"
                                                >
                                                    {casualties.reduce(
                                                        (sum, c) =>
                                                            sum + c[key],
                                                        0,
                                                    )}
                                                </td>
                                            ))}
                                        </tr>
                                    </tfoot>
                                </table>
                            )}
                        </Section>
                        <Section title="Persons involved">
                            {persons.length === 0 ? (
                                <Empty>No persons recorded.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {persons.map((p) => (
                                        <li
                                            key={p.ulid}
                                            className="flex flex-wrap items-center gap-2 py-2"
                                        >
                                            <Badge variant="outline">
                                                {p.involvement.label}
                                            </Badge>
                                            {p.person ? (
                                                <Link
                                                    href={PersonController.show(
                                                        p.person.ulid,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {p.person.name}
                                                </Link>
                                            ) : p.person_hidden ? (
                                                <span className="text-muted-foreground italic">
                                                    Person on record (not
                                                    visible to you)
                                                </span>
                                            ) : (
                                                <span>
                                                    Unidentified:{' '}
                                                    {p.unidentified_description}
                                                </span>
                                            )}
                                            {p.notes && (
                                                <span className="text-muted-foreground">
                                                    — {p.notes}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                        <Section title="Criminal groups">
                            {groups.length === 0 ? (
                                <Empty>No groups linked.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {groups.map((g) => (
                                        <li
                                            key={g.ulid}
                                            className="flex flex-wrap items-center gap-2 py-2"
                                        >
                                            <Badge variant="outline">
                                                {g.role.label}
                                            </Badge>
                                            {g.group ? (
                                                g.group.name
                                            ) : (
                                                <span className="text-muted-foreground italic">
                                                    A group not visible to you
                                                </span>
                                            )}
                                            {g.notes && (
                                                <span className="text-muted-foreground">
                                                    — {g.notes}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>
                )}

                {tab === 'response' && (
                    <div className="max-w-5xl space-y-6">
                        <Section title="Response">
                            {agencies.length === 0 ? (
                                <Empty>No agencies recorded.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {agencies.map((a) => (
                                        <li
                                            key={a.ulid}
                                            className="flex flex-wrap items-center gap-2 py-2"
                                        >
                                            <Badge variant="outline">
                                                {a.role.label}
                                            </Badge>
                                            <span className="font-medium">
                                                {a.agency.short_name}
                                            </span>
                                            {a.formation && (
                                                <span>{a.formation.name}</span>
                                            )}
                                            {a.formation_hidden && (
                                                <span className="text-muted-foreground italic">
                                                    a formation not visible to
                                                    you
                                                </span>
                                            )}
                                            {a.responded_at && (
                                                <span className="text-muted-foreground">
                                                    responded{' '}
                                                    {formatDateTime(
                                                        a.responded_at,
                                                    )}
                                                </span>
                                            )}
                                            {a.notes && (
                                                <span className="text-muted-foreground">
                                                    — {a.notes}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {incident.action_taken && (
                                <dl className="text-sm">
                                    <Fact label="Action taken">
                                        {incident.action_taken}
                                    </Fact>
                                </dl>
                            )}
                        </Section>
                        <Section title="Property affected">
                            {properties.length === 0 ? (
                                <Empty>No property recorded.</Empty>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {properties.map((p) => (
                                        <li
                                            key={p.ulid}
                                            className="flex flex-wrap items-center gap-2 py-2"
                                        >
                                            <span className="font-medium">
                                                {p.property_type.name}
                                            </span>
                                            {p.quantity !== null && (
                                                <span>× {p.quantity}</span>
                                            )}
                                            {p.description && (
                                                <span>{p.description}</span>
                                            )}
                                            {p.estimated_value_naira !==
                                                null && (
                                                <span className="text-muted-foreground">
                                                    ₦
                                                    {Number(
                                                        p.estimated_value_naira,
                                                    ).toLocaleString('en-NG')}
                                                </span>
                                            )}
                                            {p.site && (
                                                <span className="text-muted-foreground">
                                                    at {p.site.name}
                                                </span>
                                            )}
                                            {p.site_hidden && (
                                                <span className="text-muted-foreground italic">
                                                    at a site not visible to you
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>
                )}

                {tab === 'evidence' && (
                    <div className="max-w-5xl">
                        <EvidenceSection
                            evidence={evidence}
                            incidentUlid={incident.ulid}
                            incidentLevel={incident.classification.value}
                        />
                    </div>
                )}

                {tab === 'duplicates' &&
                    (possibleDuplicates.length > 0 ? (
                        <DuplicateList
                            title="Possible duplicates"
                            rows={possibleDuplicates}
                            onPick={
                                can.invalidate
                                    ? (row) => setInvalidating(row)
                                    : undefined
                            }
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No possible duplicates among the incidents you can
                            see.
                        </p>
                    ))}

                {tab === 'timeline' && timeline && (
                    <IncidentTimeline
                        incidentUlid={incident.ulid}
                        incidentLevel={incident.classification.value}
                        entries={timeline}
                        options={workflowOptions}
                        canAdd={can.addUpdate}
                    />
                )}

                {tab === 'history' && history && (
                    <div className="space-y-2">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>
        </>
    );
}

/** A bold banner on closed and invalid incidents (locked). */
function LockBanner({ incident }: { incident: IncidentDetail }) {
    const w = incident.workflow;

    if (!w.locked) {
        return null;
    }

    const invalid = incident.status.value === 'invalid';

    return (
        <div
            role="status"
            className="flex flex-wrap items-center gap-2 rounded-lg border-2 border-foreground/80 bg-muted p-4 text-sm font-semibold"
        >
            <Lock className="size-4" />
            {invalid ? (
                <span>
                    This incident was marked invalid (
                    {w.invalid_reason?.label.toLowerCase()}) and is locked.
                    {w.duplicate_of && (
                        <>
                            {' '}
                            It duplicates{' '}
                            <Link
                                href={IncidentController.show(
                                    w.duplicate_of.ulid,
                                )}
                                className="underline"
                            >
                                {w.duplicate_of.reference_no ??
                                    w.duplicate_of.title}
                            </Link>
                            .
                        </>
                    )}
                    {w.invalid_note && (
                        <span className="block font-normal">
                            {w.invalid_note}
                        </span>
                    )}
                </span>
            ) : (
                <span>
                    This incident is closed and locked
                    {w.closed
                        ? ` (closed ${formatDateTime(w.closed.at)}${w.closed.by ? ` by ${w.closed.by}` : ''})`
                        : ''}
                    .
                </span>
            )}
        </div>
    );
}

/** Who moved the incident through the workflow, and action taken (Overview). */
function WorkflowSummary({ incident }: { incident: IncidentDetail }) {
    const w = incident.workflow;
    const steps: [string, WorkflowStep][] = [
        ['Submitted', w.submitted],
        ['Returned', w.returned],
        ['Verified', w.verified],
        ['Investigation started', w.investigation_started],
        ['Resolved', w.resolved],
        ['Closed', w.closed],
        ['Marked invalid', w.invalidated],
    ];
    const done = steps.filter(([, step]) => step !== null);

    return (
        <Section title="Workflow and action taken">
            {done.length === 0 ? (
                <Empty>Not submitted yet.</Empty>
            ) : (
                <dl className="grid gap-2 text-sm sm:grid-cols-2">
                    {done.map(([label, step]) => (
                        <Fact key={label} label={label}>
                            {formatDateTime(step?.at)}
                            {step?.by ? ` by ${step.by}` : ''}
                        </Fact>
                    ))}
                </dl>
            )}
            {incident.action_taken && (
                <dl className="text-sm">
                    <Fact label="Action taken at the time of reporting">
                        {incident.action_taken}
                    </Fact>
                </dl>
            )}
        </Section>
    );
}

/** Return reasons and resolution summaries, when there are any. */
function WorkflowFacts({ incident }: { incident: IncidentDetail }) {
    const w = incident.workflow;
    const facts: [string, string, string | null][] = [];

    if (incident.status.value === 'returned' && w.return_reason) {
        facts.push(['Returned', w.return_reason, w.returned?.by ?? null]);
    }
    if (
        w.resolution_summary &&
        ['resolved', 'closed'].includes(incident.status.value)
    ) {
        facts.push([
            'Resolution',
            w.resolution_summary,
            w.resolved?.by ?? null,
        ]);
    }

    if (facts.length === 0) {
        return null;
    }

    return (
        <dl className="grid gap-3 rounded-lg border p-4 text-sm">
            {facts.map(([label, text, by]) => (
                <div key={label}>
                    <dt className="text-xs text-muted-foreground">
                        {label}
                        {by && ` by ${by}`}
                    </dt>
                    <dd className="whitespace-pre-line">{text}</dd>
                </div>
            ))}
        </dl>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="space-y-2 rounded-lg border p-4" aria-label={title}>
            <h2 className="text-sm font-semibold">{title}</h2>
            {children}
        </section>
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

function Empty({ children }: { children: ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

IncidentShow.layout = {
    breadcrumbs: [{ title: 'Incidents', href: IncidentListController.index() }],
};
