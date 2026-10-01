import type { IncidentListRow } from '@/types/incidents';
import IncidentsTab from '@/components/incidents/incidents-tab';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Crosshair, Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import GroupAreaController from '@/actions/App/Http/Controllers/CriminalGroups/GroupAreaController';
import GroupMemberController from '@/actions/App/Http/Controllers/CriminalGroups/GroupMemberController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import PersonAvatar from '@/components/persons/person-avatar';
import PersonPicker from '@/components/persons/person-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { NamedRef, Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type {
    GroupArea,
    GroupDetail,
    GroupMember,
    GroupOptions,
} from '@/types/political';
import type { ActivityEntry } from '@/types/users';

type GroupTab = 'overview' | 'areas' | 'members' | 'incidents' | 'history';

type Props = {
    /** Phase 5D: linked incidents (the tab is hidden when there are none). */
    incidents: Paginated<IncidentListRow> | null;
    group: GroupDetail;
    tab: GroupTab;
    tabs: GroupTab[];
    areas: GroupArea[];
    members: GroupMember[] | null;
    history: Paginated<ActivityEntry> | null;
    options: GroupOptions;
    states: Option[];
    can: { update: boolean; delete: boolean; designatePoi: boolean };
};

const TAB_LABELS: Record<GroupTab, string> = {
    overview: 'Overview',
    areas: 'Areas of operation',
    members: 'Members',
    incidents: 'Incidents',
    history: 'History',
};

function AreasMap({ areas }: { areas: GroupArea[] }) {
    const markers: MapMarker[] = areas
        .filter((a) => a.latitude !== null && a.longitude !== null)
        .map((a) => ({
            id: a.ulid,
            position: [a.latitude as number, a.longitude as number],
            title: `${a.lga.name} LGA`,
            popup: `${a.lga.name} LGA, ${a.state.name}${a.notes ? ` — ${a.notes}` : ''}`,
        }));

    if (markers.length === 0) {
        return (
            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                No LGA centroids are recorded for these areas yet; see the list.
            </p>
        );
    }

    return (
        <BuffaloMap
            markers={markers}
            fitMarkers
            className="h-80 w-full rounded-lg border"
        />
    );
}

function AreasPanel({
    group,
    areas,
    states,
    canUpdate,
}: {
    group: GroupDetail;
    areas: GroupArea[];
    states: Option[];
    canUpdate: boolean;
}) {
    const form = useForm({ state: '', lga: '', notes: '' });
    const [lgas, setLgas] = useState<Option[]>([]);
    const [removing, setRemoving] = useState<GroupArea | null>(null);

    const chooseState = (state: string) => {
        form.setData((d) => ({ ...d, state, lga: '' }));
        setLgas([]);

        if (state !== '') {
            getJson<{ lgas: Option[] }>(
                GroupAreaController.lgas({ group: group.ulid, state }).url,
            ).then((data) => setLgas(data.lgas));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({ lga: d.lga, notes: d.notes || null }));
        form.post(GroupAreaController.store(group.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Areas describe where the group operates. They never make the
                group visible to officers of those LGAs.
            </p>
            <AreasMap areas={areas} />
            {canUpdate && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-[1fr_1fr_2fr_auto] md:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="area-state">State</Label>
                        <NativeSelect
                            id="area-state"
                            value={form.data.state}
                            onChange={(e) => chooseState(e.target.value)}
                        >
                            <option value="">Select…</option>
                            {states.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="area-lga">LGA</Label>
                        <NativeSelect
                            id="area-lga"
                            value={form.data.lga}
                            onChange={(e) =>
                                form.setData('lga', e.target.value)
                            }
                            required
                        >
                            <option value="">Select…</option>
                            {lgas.map((l) => (
                                <option key={l.value} value={l.value}>
                                    {l.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.lga} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="area-notes">Notes</Label>
                        <Input
                            id="area-notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || form.data.lga === ''}
                    >
                        <Plus /> Add area
                    </Button>
                </form>
            )}
            {areas.length === 0 ? (
                <div className="rounded-lg border">
                    <EmptyState
                        title="No areas of operation"
                        description="Add the LGAs where the group operates."
                    />
                </div>
            ) : (
                <ul className="divide-y rounded-lg border text-sm">
                    {areas.map((area) => (
                        <li
                            key={area.ulid}
                            className="flex items-center justify-between px-3 py-2"
                        >
                            <span>
                                <span className="font-medium">
                                    {area.lga.name} LGA
                                </span>
                                , {area.state.name}
                                {area.notes && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        — {area.notes}
                                    </span>
                                )}
                            </span>
                            {canUpdate && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${area.lga.name}`}
                                    onClick={() => setRemoving(area)}
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {removing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setRemoving(null)}
                    title={`Remove ${removing.lga.name} LGA?`}
                    description="It is no longer listed as an area of operation. The change is recorded in the history."
                    confirmLabel="Remove"
                    destructive
                    method="delete"
                    url={
                        GroupAreaController.destroy({
                            group: group.ulid,
                            area: removing.ulid,
                        }).url
                    }
                />
            )}
        </div>
    );
}

function MembersPanel({
    group,
    members,
    options,
    canUpdate,
    canDesignatePoi,
}: {
    group: GroupDetail;
    members: GroupMember[];
    options: GroupOptions;
    canUpdate: boolean;
    canDesignatePoi: boolean;
}) {
    const [person, setPerson] = useState<NamedRef | null>(null);
    const form = useForm({
        person: '',
        role_in_group: '',
        membership_status: 'suspected',
        since: '',
        notes: '',
    });
    const [removing, setRemoving] = useState<GroupMember | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            person: person?.ulid ?? '',
            role_in_group: d.role_in_group || null,
            since: d.since || null,
            notes: d.notes || null,
        }));
        form.post(GroupMemberController.store(group.ulid).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setPerson(null);
            },
        });
    };

    const changeStatus = (member: GroupMember, status: string) =>
        router.put(
            GroupMemberController.update({
                group: group.ulid,
                member: member.ulid,
            }).url,
            {
                role_in_group: member.role_in_group,
                membership_status: status,
                since: member.since,
                notes: member.notes,
            },
            { preserveScroll: true },
        );

    return (
        <div className="space-y-4">
            {canUpdate && (
                <form
                    onSubmit={submit}
                    className="grid gap-3 rounded-lg border p-4 md:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_auto] lg:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="member-person">Person</Label>
                        <PersonPicker
                            id="member-person"
                            value={person}
                            onChange={setPerson}
                        />
                        <InputError message={form.errors.person} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="member-role">Role in group</Label>
                        <Input
                            id="member-role"
                            placeholder="e.g. leader"
                            value={form.data.role_in_group}
                            onChange={(e) =>
                                form.setData('role_in_group', e.target.value)
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="member-status">Membership</Label>
                        <NativeSelect
                            id="member-status"
                            value={form.data.membership_status}
                            onChange={(e) =>
                                form.setData(
                                    'membership_status',
                                    e.target.value,
                                )
                            }
                        >
                            {options.membershipStatuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="member-since">Since</Label>
                        <Input
                            id="member-since"
                            type="date"
                            value={form.data.since}
                            onChange={(e) =>
                                form.setData('since', e.target.value)
                            }
                        />
                        <InputError message={form.errors.since} />
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || person === null}
                    >
                        <Plus /> Add member
                    </Button>
                </form>
            )}
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Person</th>
                            <th className="px-3 py-2 font-medium">Role</th>
                            <th className="px-3 py-2 font-medium">
                                Membership
                            </th>
                            <th className="px-3 py-2 font-medium">Since</th>
                            <th className="px-3 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {members.length === 0 && (
                            <tr>
                                <td colSpan={5} className="p-0">
                                    <EmptyState
                                        title="No members"
                                        description="Members you can view appear here."
                                    />
                                </td>
                            </tr>
                        )}
                        {members.map((member) =>
                            member.person ? (
                                <tr key={member.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={PersonController.show(
                                                member.person.ulid,
                                            )}
                                            className="flex items-center gap-3 font-medium hover:underline"
                                        >
                                            <PersonAvatar
                                                src={member.person.thumbnail}
                                                name={member.person.name}
                                                className="size-8"
                                            />
                                            {member.person.name}
                                        </Link>
                                        {member.is_poi ? (
                                            <Badge
                                                variant="outline"
                                                className="mt-1"
                                            >
                                                Person of interest
                                            </Badge>
                                        ) : (
                                            canDesignatePoi && (
                                                <Link
                                                    href={PersonOfInterestController.create(
                                                        member.person.ulid,
                                                    )}
                                                    className="mt-1 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    <Crosshair className="size-3" />{' '}
                                                    Not a person of interest —
                                                    designate?
                                                </Link>
                                            )
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {member.role_in_group ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {canUpdate ? (
                                            <NativeSelect
                                                aria-label={`Membership of ${member.person.name}`}
                                                value={
                                                    member.membership_status
                                                        .value
                                                }
                                                onChange={(e) =>
                                                    changeStatus(
                                                        member,
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {options.membershipStatuses.map(
                                                    (s) => (
                                                        <option
                                                            key={s.value}
                                                            value={s.value}
                                                        >
                                                            {s.label}
                                                        </option>
                                                    ),
                                                )}
                                            </NativeSelect>
                                        ) : (
                                            member.membership_status.label
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {member.since ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right">
                                        {canUpdate && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove ${member.person.name}`}
                                                onClick={() =>
                                                    setRemoving(member)
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ) : null,
                        )}
                    </tbody>
                </table>
            </div>
            {removing && removing.person && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setRemoving(null)}
                    title={`Remove ${removing.person.name} from the group?`}
                    description="The membership is removed; the change is recorded in both histories."
                    confirmLabel="Remove"
                    destructive
                    method="delete"
                    url={
                        GroupMemberController.destroy({
                            group: group.ulid,
                            member: removing.ulid,
                        }).url
                    }
                />
            )}
        </div>
    );
}

/** A criminal group. Every visit is recorded in the access log. */
export default function CriminalGroupShow({
    group,
    incidents,
    tab,
    tabs,
    areas,
    members,
    history,
    options,
    states,
    can,
}: Props) {
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <Head title={group.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {group.name}
                            </h1>
                            <ClassificationBadge
                                level={group.classification.value}
                            />
                            <Badge variant="outline">
                                {group.status.label}
                            </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {group.type.label} · based in {group.lga.name} LGA,{' '}
                            {group.state.name} State
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={CriminalGroupController.edit(
                                        group.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="destructive"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav
                    className="flex gap-1 overflow-x-auto border-b"
                    aria-label="Group sections"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={CriminalGroupController.show(group.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {TAB_LABELS[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <dl className="grid content-start gap-4 rounded-lg border p-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <dt className="text-xs text-muted-foreground">
                                    Description
                                </dt>
                                <dd className="text-sm whitespace-pre-line">
                                    {group.description}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Other names
                                </dt>
                                <dd className="text-sm">
                                    {group.other_names.join(', ') || '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Estimated size
                                </dt>
                                <dd className="text-sm">
                                    {group.estimated_size?.toLocaleString(
                                        'en-NG',
                                    ) ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Members (you can view)
                                </dt>
                                <dd className="text-sm">
                                    {group.member_count}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Areas of operation
                                </dt>
                                <dd className="text-sm">
                                    {areas
                                        .map(
                                            (a) =>
                                                `${a.lga.name} (${a.state.name})`,
                                        )
                                        .join(', ') || '—'}
                                </dd>
                            </div>
                        </dl>
                        <AreasMap areas={areas} />
                    </div>
                )}
                {tab === 'areas' && (
                    <AreasPanel
                        group={group}
                        areas={areas}
                        states={states}
                        canUpdate={can.update}
                    />
                )}
                {tab === 'members' && members && (
                    <MembersPanel
                        group={group}
                        members={members}
                        options={options}
                        canUpdate={can.update}
                        canDesignatePoi={can.designatePoi}
                    />
                )}
                {tab === 'incidents' && incidents && (
                    <IncidentsTab incidents={incidents} />
                )}

                {tab === 'history' && history && (
                    <div className="space-y-3">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${group.name}"?`}
                    description="The group is removed from lists. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={CriminalGroupController.destroy(group.ulid).url}
                />
            )}
        </>
    );
}

CriminalGroupShow.layout = {
    breadcrumbs: [
        { title: 'Criminal groups', href: CriminalGroupController.index() },
    ],
};
