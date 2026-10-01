import { Head, Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import DynamicFieldsView from '@/components/dynamic-fields/dynamic-fields-view';
import EvidenceSection from '@/components/evidence/evidence-section';
import BuffaloMap from '@/components/map/BuffaloMap';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActivityTable from '@/components/users/activity-table';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { MapView } from '@/types/dashboard';
import type { IncidentRecord } from '@/types/incidents';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'history';

type Props = IncidentRecord & {
    tab: Tab;
    history: Paginated<ActivityEntry> | null;
    map: MapView;
    can: { update: boolean };
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
    map,
    can,
}: Props) {
    return (
        <>
            <Head title={incident.title} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {incident.title}
                            </h1>
                            <ClassificationBadge
                                level={incident.classification.value}
                            />
                            <Badge variant="secondary">
                                {incident.status.label}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {incident.reference_no ?? 'No reference yet'} ·{' '}
                            {incident.type.name} · {incident.severity.label} ·{' '}
                            {incident.lga.name} LGA, {incident.state.name} State
                        </p>
                    </div>
                    {can.update && (
                        <Button variant="outline" asChild>
                            <Link href={IncidentController.edit(incident.ulid)}>
                                <Pencil /> Edit draft
                            </Link>
                        </Button>
                    )}
                </div>

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(['overview', 'history'] as Tab[]).map((name) => (
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
                            {name === 'overview' ? 'Overview' : 'History'}
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
                                                        {
                                                            p.unidentified_description
                                                        }
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
                                                        A group not visible to
                                                        you
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
                                                    <span>
                                                        {a.formation.name}
                                                    </span>
                                                )}
                                                {a.formation_hidden && (
                                                    <span className="text-muted-foreground italic">
                                                        a formation not visible
                                                        to you
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
                                                        ).toLocaleString(
                                                            'en-NG',
                                                        )}
                                                    </span>
                                                )}
                                                {p.site && (
                                                    <span className="text-muted-foreground">
                                                        at {p.site.name}
                                                    </span>
                                                )}
                                                {p.site_hidden && (
                                                    <span className="text-muted-foreground italic">
                                                        at a site not visible to
                                                        you
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </Section>
                            <EvidenceSection
                                evidence={evidence}
                                incidentUlid={incident.ulid}
                                incidentLevel={incident.classification.value}
                            />
                        </div>
                        <BuffaloMap
                            className="h-80"
                            center={map.center ?? undefined}
                            zoom={map.zoom}
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
    breadcrumbs: [{ title: 'Incidents', href: IncidentController.index() }],
};
