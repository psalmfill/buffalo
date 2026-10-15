import type { IncidentListRow } from '@/types/incidents';
import RichTextView from '@/components/rich-text/rich-text-view';
import IncidentsTab from '@/components/incidents/incidents-tab';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import FormationMap from '@/components/security/formation-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { FormationDetail, FormationRow } from '@/types/formations';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'incidents' | 'history';

/** One agency formation: details, parent and sub-formations, map, history. */
export default function FormationShow({
    formation,
    incidents,
    hasIncidents,
    children,
    tab,
    history,
    can,
}: {
    formation: FormationDetail;
    /** Phase 5D: incidents where the formation responded. */
    incidents: Paginated<IncidentListRow> | null;
    hasIncidents: boolean;
    children: FormationRow[];
    tab: Tab;
    history: Paginated<ActivityEntry> | null;
    can: { update: boolean; delete: boolean; create: boolean };
}) {
    const [deleting, setDeleting] = useState(false);
    const commander = [
        formation.commander_title,
        formation.commander_rank,
        formation.commander_name,
    ]
        .filter(Boolean)
        .join(' · ');

    return (
        <>
            <Head title={formation.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {formation.name}
                            </h1>
                            <ClassificationBadge
                                level={formation.classification.value}
                            />
                            {formation.is_temporary && (
                                <Badge variant="outline">Temporary</Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {formation.agency.name} ·{' '}
                            {formation.formation_type.label} ·{' '}
                            {formation.status.label} · {formation.lga.name} LGA,{' '}
                            {formation.state.name} State
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={FormationController.edit(
                                        formation.ulid,
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
                    {(
                        (hasIncidents
                            ? ['overview', 'incidents', 'history']
                            : ['overview', 'history']) as Tab[]
                    ).map((name) => (
                        <Link
                            key={name}
                            href={FormationController.show(formation.ulid, {
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
                            {name === 'overview'
                                ? 'Overview'
                                : name === 'incidents'
                                  ? 'Incidents'
                                  : 'History'}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                        <div className="space-y-6">
                            <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                                <Fact
                                    label="Agency"
                                    value={formation.agency.name}
                                />
                                <Fact
                                    label="Type"
                                    value={formation.formation_type.label}
                                />
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Parent formation
                                    </dt>
                                    <dd className="text-sm">
                                        {formation.parent ? (
                                            <Link
                                                href={FormationController.show(
                                                    formation.parent.ulid,
                                                )}
                                                className="hover:underline"
                                            >
                                                {formation.parent.name}
                                            </Link>
                                        ) : formation.parent_hidden ? (
                                            <span className="text-muted-foreground">
                                                A formation you cannot view
                                            </span>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <Fact
                                    label="Community"
                                    value={formation.community?.name ?? null}
                                />
                                <Fact
                                    label="Commander"
                                    value={commander || null}
                                />
                                <Fact
                                    label="Personnel strength"
                                    value={
                                        formation.personnel_strength?.toLocaleString(
                                            'en-NG',
                                        ) ?? null
                                    }
                                />
                                <Fact
                                    label="Official phone"
                                    value={formation.official_phone}
                                />
                                <Fact
                                    label="Active"
                                    value={
                                        formation.is_temporary
                                            ? `${formation.active_from ?? '?'} – ${formation.active_to ?? 'open-ended'}`
                                            : 'Permanent'
                                    }
                                />
                                <div className="sm:col-span-2">
                                    <Fact
                                        label="Address"
                                        value={formation.address}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Fact
                                        label="Notes"
                                        value={formation.notes}
                                        rich
                                    />
                                </div>
                            </dl>

                            <section className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-semibold">
                                        Sub-formations
                                    </h2>
                                    {can.create && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={FormationController.create(
                                                    {
                                                        query: {
                                                            lga: formation.lga
                                                                .ulid,
                                                        },
                                                    },
                                                )}
                                            >
                                                <Plus /> Add formation
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                                {children.length === 0 ? (
                                    <EmptyState
                                        title="No sub-formations"
                                        description="None recorded that you can view."
                                    />
                                ) : (
                                    <ul className="divide-y rounded-lg border">
                                        {children.map((child) => (
                                            <li
                                                key={child.ulid}
                                                className="flex items-center justify-between px-3 py-2 text-sm"
                                            >
                                                <Link
                                                    href={FormationController.show(
                                                        child.ulid,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {child.name}
                                                </Link>
                                                <span className="text-muted-foreground">
                                                    {child.formation_type.label}{' '}
                                                    · {child.lga.name}
                                                    {child.is_temporary &&
                                                        ' · temporary'}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </div>
                        <FormationMap
                            formations={[formation, ...children]}
                            className="h-80"
                        />
                    </div>
                )}

                {tab === 'incidents' && incidents && (
                    <IncidentsTab incidents={incidents} />
                )}

                {tab === 'history' && history && (
                    <div className="space-y-2">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${formation.name}"?`}
                    description="The formation is removed from lists and maps. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={FormationController.destroy(formation.ulid).url}
                />
            )}
        </>
    );
}

function Fact({
    label,
    value,
    rich = false,
}: {
    label: string;
    value: string | null;
    /** Rich text (Phase 11B): sanitised HTML. */
    rich?: boolean;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            {rich ? (
                <dd className="text-sm">
                    <RichTextView html={value} empty="—" />
                </dd>
            ) : (
                <dd className="text-sm whitespace-pre-line">{value ?? '—'}</dd>
            )}
        </div>
    );
}

FormationShow.layout = {
    breadcrumbs: [
        { title: 'Agency formations', href: FormationController.index() },
    ],
};
