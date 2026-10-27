import { Head, Link, router } from '@inertiajs/react';
import StateLink from '@/components/local-government/state-link';
import PrintRecordButton from '@/components/reports/print-record-button';
import { useEcho } from '@laravel/echo-react';
import { CalendarClock, Pencil, RotateCcw, Trash2, Unlink } from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import ThreatLinkController from '@/actions/App/Http/Controllers/Threats/ThreatLinkController';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import RichTextView from '@/components/rich-text/rich-text-view';
import ClassificationBadge from '@/components/community-information/classification-badge';
import DisseminationPrompt from '@/components/disseminations/dissemination-prompt';
import SharedBanner from '@/components/disseminations/shared-banner';
import SharingPanel from '@/components/disseminations/sharing-panel';
import EmptyState from '@/components/empty-state';
import LinkRows from '@/components/flashpoints/link-rows';
import type { LinkRow } from '@/components/flashpoints/link-rows';
import { flashpointCircles } from '@/components/flashpoints/flashpoint-areas';
import BuffaloMap from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import ExpiryCountdown from '@/components/threats/expiry-countdown';
import {
    ExpiryDialog,
    StatusDialog,
} from '@/components/threats/status-dialogs';
import {
    UrgencyBadge,
    expectedWindow,
    threatMapLayers,
} from '@/components/threats/threat-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { MapView } from '@/types/dashboard';
import type { DisseminationProps } from '@/types/disseminations';
import type { Paginated } from '@/types/pagination';
import type {
    ThreatDetail,
    ThreatLinks,
    ThreatOptions,
    TimelineEntry,
} from '@/types/threats';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'links' | 'timeline' | 'history';

const TAB_LABELS: Record<Tab, string> = {
    overview: 'Overview',
    links: 'Links',
    timeline: 'Timeline',
    history: 'History',
};

type Props = DisseminationProps & {
    threat: ThreatDetail;
    tab: Tab;
    map: MapView;
    links: ThreatLinks | null;
    timeline: TimelineEntry[] | null;
    history: Paginated<ActivityEntry> | null;
    options: ThreatOptions;
    channel: string;
    can: {
        update: boolean;
        reactivate: boolean;
        delete: boolean;
        linkFlashpoints: boolean;
        linkGroups: boolean;
        linkIncidents: boolean;
    };
};

/** One threat report: assessment, window and expiry, links, changes. */
export default function ThreatShow(props: Props) {
    const { threat, tab, can, options } = props;
    const [status, setStatus] = useState<string | null>(null);
    const [expiry, setExpiry] = useState<'extend' | 'reactivate' | null>(null);
    const [deleting, setDeleting] = useState(false);
    const active = threat.status.value === 'active';
    const reactivatable = ['expired', 'mitigated', 'withdrawn'].includes(
        threat.status.value,
    );

    // Live: reload when this report changes (payload: ULID and event only).
    useEcho<{ threat: string; event: string }>(
        props.channel,
        ['.threat.changed'],
        (payload) => {
            if (payload.threat === threat.ulid) {
                router.reload();
            }
        },
        [threat.ulid],
    );

    return (
        <>
            <Head title={threat.reference_no} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <p className="font-mono text-sm font-semibold">
                            {threat.reference_no}
                        </p>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {threat.title}
                            </h1>
                            <Badge variant="secondary">
                                {threat.status.label}
                            </Badge>
                            <ClassificationBadge
                                level={threat.classification.value}
                            />
                        </div>
                        <p className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground">
                            <span>{threat.threat_type.name}</span>·
                            <UrgencyBadge urgency={threat.urgency} />
                            <span>
                                · {threat.credibility.label} · {threat.lga.name}{' '}
                                LGA,{' '}
                                <StateLink
                                    state={threat.state}
                                    suffix=" State"
                                />
                            </span>
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <PrintRecordButton
                            type="threat_report"
                            parameter="threat"
                            ulid={threat.ulid}
                        />
                        {can.update && active && (
                            <>
                                {options.settableStatuses.map((s) => (
                                    <Button
                                        key={s.value}
                                        variant={
                                            s.value === 'materialised'
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setStatus(s.value)}
                                    >
                                        {s.value === 'withdrawn'
                                            ? 'Withdraw'
                                            : `Mark ${s.label.toLowerCase()}`}
                                    </Button>
                                ))}
                                <Button
                                    variant="outline"
                                    onClick={() => setExpiry('extend')}
                                >
                                    <CalendarClock /> Extend
                                </Button>
                            </>
                        )}
                        {can.reactivate && reactivatable && (
                            <Button
                                variant="outline"
                                onClick={() => setExpiry('reactivate')}
                            >
                                <RotateCcw /> Reactivate
                            </Button>
                        )}
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={ThreatReportController.edit(
                                        threat.ulid,
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

                {props.dissemination && (
                    <SharedBanner banner={props.dissemination} />
                )}
                {props.sharing && tab === 'overview' && (
                    <SharingPanel
                        sharing={props.sharing}
                        disseminateUrl={DisseminationController.storeThreat.url(
                            threat.ulid,
                        )}
                        requestUrl={DisseminationController.requestThreat.url(
                            threat.ulid,
                        )}
                    />
                )}
                {props.sharing && props.disseminationPrompt && (
                    <DisseminationPrompt
                        prompt={props.disseminationPrompt}
                        sharing={props.sharing}
                        disseminateUrl={DisseminationController.storeThreat.url(
                            threat.ulid,
                        )}
                        requestUrl={DisseminationController.requestThreat.url(
                            threat.ulid,
                        )}
                    />
                )}

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(Object.keys(TAB_LABELS) as Tab[])
                        .filter(
                            (name) =>
                                name !== 'history' || !props.dissemination,
                        )
                        .map((name) => (
                            <Link
                                key={name}
                                href={ThreatReportController.show(threat.ulid, {
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
                {tab === 'links' && props.links && (
                    <LinksTab
                        threatUlid={threat.ulid}
                        links={props.links}
                        options={options}
                        can={can}
                    />
                )}
                {tab === 'timeline' && props.timeline && (
                    <Timeline entries={props.timeline} />
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
                    threatUlid={threat.ulid}
                    status={status}
                    onClose={() => setStatus(null)}
                />
            )}
            {expiry && (
                <ExpiryDialog
                    threatUlid={threat.ulid}
                    mode={expiry}
                    maxDays={options.maxExpiryDays}
                    onClose={() => setExpiry(null)}
                />
            )}
            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete ${threat.reference_no}?`}
                    description="The report is removed from lists and maps. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={ThreatReportController.destroy(threat.ulid).url}
                />
            )}
        </>
    );
}

function OverviewTab({ threat, map, links }: Props) {
    const layers = useMemo(() => threatMapLayers([threat]), [threat]);
    const flashpointAreas = useMemo(
        () =>
            flashpointCircles(
                links?.flashpoints ?? [],
                (ulid) => FlashpointController.show(ulid).url,
            ),
        [links],
    );
    const expected = expectedWindow(threat);

    return (
        <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
            <div className="space-y-6">
                <section
                    className="grid gap-4 rounded-lg border p-4 sm:grid-cols-3"
                    aria-label="Timing"
                >
                    <div>
                        <p className="text-xs text-muted-foreground">
                            {threat.status.value === 'active'
                                ? 'Expires in'
                                : 'Expiry'}
                        </p>
                        <p className="text-sm">
                            {threat.status.value === 'active' ? (
                                <ExpiryCountdown at={threat.expires_at} />
                            ) : (
                                formatDateTime(threat.expires_at)
                            )}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {formatDateTime(threat.expires_at)}
                        </p>
                    </div>
                    <div className="sm:col-span-2">
                        <p className="text-xs text-muted-foreground">
                            Expected window
                        </p>
                        <p className="text-sm">{expected ?? 'Not given'}</p>
                    </div>
                </section>

                <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                    <Fact
                        label="Credibility"
                        value={threat.credibility.label}
                    />
                    <Fact label="Urgency" value={threat.urgency.label} />
                    <Fact label="Source" value={threat.source_type.label} />
                    <Fact
                        label="Community"
                        value={threat.community?.name ?? null}
                    />
                    <div className="sm:col-span-2">
                        <Fact
                            label="Description"
                            value={threat.description}
                            rich
                        />
                    </div>
                    <Fact
                        label="Target"
                        value={threat.target_description}
                        rich
                    />
                    <Fact label="Source notes" value={threat.source_notes} />
                    <Fact
                        label="Recommended action"
                        value={threat.recommended_action}
                        rich
                    />
                    <Fact label="Status note" value={threat.status_note} />
                    <Fact
                        label="Recorded"
                        value={`${formatDateTime(threat.created_at)}${threat.created_by ? ` by ${threat.created_by}` : ''}`}
                    />
                </dl>
            </div>
            <div className="space-y-2">
                <BuffaloMap
                    className="h-80 w-full rounded-lg border"
                    center={
                        threat.latitude !== null && threat.longitude !== null
                            ? [threat.latitude, threat.longitude]
                            : (map.center ?? undefined)
                    }
                    zoom={threat.latitude !== null ? 13 : map.zoom}
                    outline={map.outline}
                    circles={[...layers.circles, ...flashpointAreas]}
                    fitCircles={layers.circles.length > 0}
                    markers={layers.markers}
                />
                {threat.latitude === null && (
                    <p className="text-xs text-muted-foreground">
                        No point given: the area is{' '}
                        {threat.community
                            ? `the community of ${threat.community.name}`
                            : `the whole of ${threat.lga.name} LGA`}
                        .
                    </p>
                )}
            </div>
        </div>
    );
}

function LinksTab({
    threatUlid,
    links,
    options,
    can,
}: {
    threatUlid: string;
    links: ThreatLinks;
    options: ThreatOptions;
    can: Props['can'];
}) {
    const [busy, setBusy] = useState<string | null>(null);
    const [groupRole, setGroupRole] = useState('suspected');
    const [relation, setRelation] = useState('related');

    const link = (kind: string, record: PickedEntity | null, extra = {}) => {
        if (!record) {
            return;
        }

        router.post(
            ThreatLinkController.store({ threat: threatUlid, kind }).url,
            { record: record.value, ...extra },
            {
                preserveScroll: true,
                onStart: () => setBusy(kind),
                onFinish: () => setBusy(null),
            },
        );
    };
    const unlink = (kind: string, linkUlid: string) =>
        router.delete(
            ThreatLinkController.destroy({
                threat: threatUlid,
                kind,
                link: linkUlid,
            }).url,
            {
                preserveScroll: true,
                onStart: () => setBusy(linkUlid),
                onFinish: () => setBusy(null),
            },
        );
    const unlinkButton = (kind: string, linkUlid: string) =>
        can.update ? (
            <Button
                variant="ghost"
                size="sm"
                disabled={busy === linkUlid}
                onClick={() => unlink(kind, linkUlid)}
            >
                <Unlink /> Unlink
            </Button>
        ) : undefined;

    const flashpointRows: LinkRow[] = links.flashpoints.map((f) => ({
        key: f.link,
        href: FlashpointController.show(f.ulid).url,
        title: f.name,
        meta: `${f.kind.label} · ${f.threat_type.name} · ${f.concern_level.label} concern · ${f.lga.name}`,
        action: unlinkButton('flashpoints', f.link),
    }));
    const groupRows: LinkRow[] = links.groups.map((g) => ({
        key: g.link,
        href: CriminalGroupController.show(g.ulid).url,
        title: g.name,
        meta: `${g.role.label} · ${g.type.label} · ${g.lga.name}, ${g.state.name}`,
        action: unlinkButton('groups', g.link),
    }));
    const incidentRows: LinkRow[] = links.incidents.map((i) => ({
        key: i.link,
        href: IncidentController.show(i.ulid).url,
        title: i.title,
        meta: `${i.relation.label} · ${i.reference_no ?? 'Draft'} · ${i.type.name} · ${i.lga.name} · ${formatDateTime(i.occurred_at)} · ${i.status.label}`,
        action: unlinkButton('incidents', i.link),
    }));

    const section = (
        title: string,
        rows: LinkRow[],
        visible: boolean,
        adder: ReactNode,
    ) =>
        visible && (
            <section className="space-y-2">
                <h2 className="text-sm font-semibold">
                    {title} ({rows.length})
                </h2>
                <LinkRows
                    rows={rows}
                    empty={`No linked ${title.toLowerCase()} you can view.`}
                    label={title}
                />
                {can.update && adder}
            </section>
        );

    return (
        <div className="space-y-8">
            {section(
                'Flashpoints',
                flashpointRows,
                can.linkFlashpoints,
                <div className="grid max-w-md gap-2">
                    <Label htmlFor="link-flashpoint">Link a flashpoint</Label>
                    <EntityPicker
                        id="link-flashpoint"
                        endpoint={PickerController.flashpoints().url}
                        placeholder="Search flashpoints"
                        disabled={busy === 'flashpoints'}
                        value={null}
                        onChange={(v) => link('flashpoints', v)}
                    />
                </div>,
            )}
            {section(
                'Criminal groups',
                groupRows,
                can.linkGroups,
                <div className="flex max-w-xl flex-wrap items-end gap-2">
                    <div className="grid gap-2">
                        <Label htmlFor="group-role">Role</Label>
                        <NativeSelect
                            id="group-role"
                            value={groupRole}
                            onChange={(e) => setGroupRole(e.target.value)}
                        >
                            {options.groupRoles.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="link-group">
                            Link a criminal group
                        </Label>
                        <EntityPicker
                            id="link-group"
                            endpoint={PickerController.groups().url}
                            placeholder="Search criminal groups"
                            disabled={busy === 'groups'}
                            value={null}
                            onChange={(v) =>
                                link('groups', v, { role: groupRole })
                            }
                        />
                    </div>
                </div>,
            )}
            {section(
                'Incidents',
                incidentRows,
                can.linkIncidents,
                <div className="flex max-w-xl flex-wrap items-end gap-2">
                    <div className="grid gap-2">
                        <Label htmlFor="incident-relation">Relation</Label>
                        <NativeSelect
                            id="incident-relation"
                            value={relation}
                            onChange={(e) => setRelation(e.target.value)}
                        >
                            {options.incidentRelations.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="link-incident">Link an incident</Label>
                        <EntityPicker
                            id="link-incident"
                            endpoint={PickerController.incidents().url}
                            placeholder="Reference or title"
                            disabled={busy === 'incidents'}
                            value={null}
                            onChange={(v) => link('incidents', v, { relation })}
                        />
                    </div>
                </div>,
            )}
        </div>
    );
}

function Timeline({ entries }: { entries: TimelineEntry[] }) {
    if (entries.length === 0) {
        return <EmptyState title="No changes recorded" />;
    }

    return (
        <ol className="space-y-3" aria-label="Timeline of changes">
            {entries.map((e, i) => (
                <li key={i} className="rounded-lg border p-3">
                    <p className="text-sm font-medium">{e.description}</p>
                    <p className="text-xs text-muted-foreground">
                        {formatDateTime(e.at)} · {e.by ?? 'System'}
                    </p>
                    {e.comment && (
                        <p className="mt-1 text-sm whitespace-pre-line">
                            {e.comment}
                        </p>
                    )}
                </li>
            ))}
        </ol>
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

ThreatShow.layout = {
    breadcrumbs: [
        { title: 'Threat reports', href: ThreatReportController.index() },
    ],
};
