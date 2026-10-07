import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import AccessRequestController from '@/actions/App/Http/Controllers/AccessRequests/AccessRequestController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type {
    AccessRequestRow,
    DelegationRow,
    GrantRow,
    MatchEventRow,
} from '@/types/access-requests';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Tab =
    | 'mine'
    | 'decide'
    | 'escalated'
    | 'grants'
    | 'delegations'
    | 'activity'
    | 'oversight';

type Oversight = {
    filters: {
        state: string;
        officer: string;
        status: string;
        from: string;
        to: string;
    };
    states: Option[];
    statuses: Option[];
    requests: Paginated<AccessRequestRow>;
    grants: Paginated<GrantRow>;
    events: Paginated<MatchEventRow>;
    officers: {
        ulid: string | null;
        name: string | null;
        place: string | null;
        hits: number;
        suppressed: number;
        today: number;
        over_soft_limit: boolean;
    }[];
    softLimit: number;
};

type Props = {
    tab: Tab;
    tabs: Tab[];
    mine: Paginated<AccessRequestRow> | null;
    decide: AccessRequestRow[] | null;
    escalated: AccessRequestRow[] | null;
    grants: { held: GrantRow[]; issued: GrantRow[] } | null;
    delegations: {
        current: DelegationRow[];
        past: DelegationRow[];
        deputies: Option[];
    } | null;
    activity: MatchEventRow[] | null;
    oversight: Oversight | null;
    isDelegatedDeputy: boolean;
};

const TAB_LABELS: Record<Tab, string> = {
    mine: 'My requests',
    decide: 'Awaiting my decision',
    escalated: 'Escalated',
    grants: 'Grants',
    delegations: 'Delegations',
    activity: 'Searches of our records',
    oversight: 'Oversight',
};

const dateTime = (value: string | null) =>
    value ? new Date(value).toLocaleString('en-NG') : '—';

function StatusBadge({ status }: { status: Option }) {
    const variant =
        status.value === 'approved'
            ? 'default'
            : status.value === 'rejected' || status.value === 'expired'
              ? 'destructive'
              : status.value === 'escalated'
                ? 'destructive'
                : 'secondary';

    return <Badge variant={variant}>{status.label}</Badge>;
}

function RequestsTable({
    requests,
    showRequester = true,
}: {
    requests: AccessRequestRow[];
    showRequester?: boolean;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Request</th>
                        {showRequester && (
                            <th className="px-3 py-2 font-medium">Requester</th>
                        )}
                        <th className="px-3 py-2 font-medium">Owning state</th>
                        <th className="px-3 py-2 font-medium">Scope</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2 font-medium">Record</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {requests.length === 0 && (
                        <tr>
                            <td colSpan={6} className="p-0">
                                <EmptyState
                                    title="No requests"
                                    description="Nothing to show here."
                                />
                            </td>
                        </tr>
                    )}
                    {requests.map((r) => (
                        <tr key={r.ulid}>
                            <td className="px-3 py-2">
                                <Link
                                    href={AccessRequestController.show(r.ulid)}
                                    className="font-medium hover:underline"
                                >
                                    {dateTime(r.created_at)}
                                </Link>
                                {r.urgency.value === 'urgent' && (
                                    <Badge
                                        variant="destructive"
                                        className="ml-2"
                                    >
                                        Urgent
                                    </Badge>
                                )}
                            </td>
                            {showRequester && (
                                <td className="px-3 py-2">
                                    {r.requester.name}
                                    <div className="text-xs text-muted-foreground">
                                        {r.requester.place}
                                    </div>
                                </td>
                            )}
                            <td className="px-3 py-2">
                                {r.owning_state} State
                            </td>
                            <td className="px-3 py-2">
                                {r.scope.label}, {r.requested_days} days
                            </td>
                            <td className="px-3 py-2">
                                <StatusBadge status={r.status} />
                                {r.grant?.active && (
                                    <div className="text-xs text-muted-foreground">
                                        Until {dateTime(r.grant.expires_at)}
                                    </div>
                                )}
                            </td>
                            <td className="px-3 py-2">
                                {r.record ? (
                                    <Link
                                        href={PersonController.show(
                                            r.record.ulid,
                                        )}
                                        className="hover:underline"
                                    >
                                        {r.record.name}
                                    </Link>
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function GrantsTable({ grants, title }: { grants: GrantRow[]; title: string }) {
    const [revoking, setRevoking] = useState<GrantRow | null>(null);

    return (
        <section className="space-y-3">
            <h2 className="text-sm font-semibold">{title}</h2>
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Holder</th>
                            <th className="px-3 py-2 font-medium">
                                Owning state
                            </th>
                            <th className="px-3 py-2 font-medium">Scope</th>
                            <th className="px-3 py-2 font-medium">Expires</th>
                            <th className="px-3 py-2 font-medium">Record</th>
                            <th className="px-3 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {grants.length === 0 && (
                            <tr>
                                <td
                                    colSpan={6}
                                    className="p-4 text-muted-foreground"
                                >
                                    None.
                                </td>
                            </tr>
                        )}
                        {grants.map((g) => (
                            <tr key={g.ulid}>
                                <td className="px-3 py-2">
                                    {g.request.requester.name}
                                    <div className="text-xs text-muted-foreground">
                                        {g.request.requester.place}
                                    </div>
                                </td>
                                <td className="px-3 py-2">
                                    {g.request.owning_state} State
                                </td>
                                <td className="px-3 py-2">{g.scope.label}</td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {g.revoked_at ? (
                                        <Badge variant="destructive">
                                            Revoked
                                        </Badge>
                                    ) : (
                                        dateTime(g.expires_at)
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    {g.request.record ? (
                                        <Link
                                            href={PersonController.show(
                                                g.request.record.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {g.request.record.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {g.can_revoke && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRevoking(g)}
                                        >
                                            Revoke
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {revoking && (
                <RevokeDialog
                    grant={revoking}
                    onClose={() => setRevoking(null)}
                />
            )}
        </section>
    );
}

function RevokeDialog({
    grant,
    onClose,
}: {
    grant: GrantRow;
    onClose: () => void;
}) {
    return (
        <ConfirmActionDialog
            open
            onOpenChange={(open) => !open && onClose()}
            title={`Revoke ${grant.request.requester.name}'s access?`}
            description="Access ends at once. The holder is notified. The reason is recorded."
            confirmLabel="Revoke"
            destructive
            requireReason
            url={AccessRequestController.revokeGrant(grant.ulid).url}
        />
    );
}

function EventsTable({ events }: { events: MatchEventRow[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">When</th>
                        <th className="px-3 py-2 font-medium">Officer</th>
                        <th className="px-3 py-2 font-medium">Owning state</th>
                        <th className="px-3 py-2 font-medium">Match</th>
                        <th className="px-3 py-2 font-medium">Where</th>
                        <th className="px-3 py-2 font-medium">Record</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {events.length === 0 && (
                        <tr>
                            <td
                                colSpan={6}
                                className="p-4 text-muted-foreground"
                            >
                                None.
                            </td>
                        </tr>
                    )}
                    {events.map((e, index) => (
                        <tr
                            key={index}
                            className={cn(
                                e.suppressed &&
                                    'bg-red-50/60 dark:bg-red-950/40',
                            )}
                        >
                            <td className="px-3 py-2 whitespace-nowrap">
                                {dateTime(e.created_at)}
                            </td>
                            <td className="px-3 py-2">
                                {e.officer.name}
                                <div className="text-xs text-muted-foreground">
                                    {e.officer.place}
                                </div>
                            </td>
                            <td className="px-3 py-2">
                                {e.owning_state} State
                            </td>
                            <td className="px-3 py-2">
                                {e.match_type.label}
                                {e.suppressed && (
                                    <Badge
                                        variant="destructive"
                                        className="ml-2"
                                    >
                                        Suppressed (above clearance)
                                    </Badge>
                                )}
                            </td>
                            <td className="px-3 py-2">{e.context.label}</td>
                            <td className="px-3 py-2">
                                {e.record ? (
                                    <Link
                                        href={PersonController.show(
                                            e.record.ulid,
                                        )}
                                        className="hover:underline"
                                    >
                                        {e.record.name}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function DelegationsPanel({
    data,
}: {
    data: NonNullable<Props['delegations']>;
}) {
    const form = useForm({
        delegate: '',
        starts_at: localDateTime(new Date()),
        ends_at: '',
        reason: '',
    });
    const [revoking, setRevoking] = useState<DelegationRow | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            ends_at: d.ends_at || null,
            reason: d.reason || null,
        }));
        form.post(AccessRequestController.storeDelegation().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('delegate', 'ends_at', 'reason'),
        });
    };

    const row = (d: DelegationRow) => (
        <li
            key={d.ulid}
            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
        >
            <span>
                <span className="font-medium">{d.delegate}</span>{' '}
                <span className="text-muted-foreground">
                    from {dateTime(d.starts_at)}{' '}
                    {d.ends_at ? `to ${dateTime(d.ends_at)}` : '(no end date)'}
                    {d.revoked_at &&
                        ` · ${d.end_reason === 'posting_closed' ? 'ended: posting changed' : 'revoked'} ${dateTime(d.revoked_at)}`}
                </span>
                {d.active && (
                    <Badge className="ml-2" variant="default">
                        Active
                    </Badge>
                )}
            </span>
            {d.can_revoke && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setRevoking(d)}
                >
                    Revoke
                </Button>
            )}
        </li>
    );

    return (
        <div className="space-y-6">
            <form
                onSubmit={submit}
                className="grid gap-3 rounded-lg border p-4 md:grid-cols-2"
            >
                <h2 className="text-sm font-semibold md:col-span-2">
                    Delegate approval to a Deputy State Director
                </h2>
                <div className="grid gap-2">
                    <Label htmlFor="delegate">Deputy</Label>
                    <NativeSelect
                        id="delegate"
                        value={form.data.delegate}
                        onChange={(e) =>
                            form.setData('delegate', e.target.value)
                        }
                        required
                    >
                        <option value="">Select…</option>
                        {data.deputies.map((d) => (
                            <option key={d.value} value={d.value}>
                                {d.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={form.errors.delegate} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="reason">Reason (optional)</Label>
                    <Input
                        id="reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="starts_at">Starts</Label>
                    <Input
                        id="starts_at"
                        type="datetime-local"
                        value={form.data.starts_at}
                        onChange={(e) =>
                            form.setData('starts_at', e.target.value)
                        }
                        required
                    />
                    <InputError message={form.errors.starts_at} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="ends_at">Ends (optional)</Label>
                    <Input
                        id="ends_at"
                        type="datetime-local"
                        value={form.data.ends_at}
                        onChange={(e) =>
                            form.setData('ends_at', e.target.value)
                        }
                    />
                    <InputError message={form.errors.ends_at} />
                </div>
                <div className="md:col-span-2">
                    <Button type="submit" disabled={form.processing}>
                        <Plus /> Delegate
                    </Button>
                </div>
            </form>
            <section className="space-y-2">
                <h2 className="text-sm font-semibold">Current</h2>
                <ul className="divide-y rounded-lg border">
                    {data.current.length === 0 ? (
                        <li className="p-3 text-sm text-muted-foreground">
                            None.
                        </li>
                    ) : (
                        data.current.map(row)
                    )}
                </ul>
            </section>
            <section className="space-y-2">
                <h2 className="text-sm font-semibold">Past</h2>
                <ul className="divide-y rounded-lg border">
                    {data.past.length === 0 ? (
                        <li className="p-3 text-sm text-muted-foreground">
                            None.
                        </li>
                    ) : (
                        data.past.map(row)
                    )}
                </ul>
            </section>
            {revoking && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setRevoking(null)}
                    title={`Revoke the delegation to ${revoking.delegate}?`}
                    description="They can no longer decide access requests. Both of you are notified."
                    confirmLabel="Revoke"
                    destructive
                    url={
                        AccessRequestController.revokeDelegation(revoking.ulid)
                            .url
                    }
                />
            )}
        </div>
    );
}

function OversightPanel({ data }: { data: Oversight }) {
    const [filters, setFilters] = useState(data.filters);
    const apply = (next: Partial<Oversight['filters']> = {}) => {
        const merged = { ...filters, ...next };
        setFilters(merged);
        router.get(
            AccessRequestController.index().url,
            {
                tab: 'oversight',
                ...Object.fromEntries(
                    Object.entries(merged).filter(([, v]) => v !== ''),
                ),
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <div className="space-y-8">
            <div className="grid gap-3 rounded-lg border p-4 sm:grid-cols-2 lg:grid-cols-4">
                <div className="grid gap-2">
                    <Label htmlFor="o-state">Owning state</Label>
                    <NativeSelect
                        id="o-state"
                        value={filters.state}
                        onChange={(e) => apply({ state: e.target.value })}
                    >
                        <option value="">All</option>
                        {data.states.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="o-status">Request status</Label>
                    <NativeSelect
                        id="o-status"
                        value={filters.status}
                        onChange={(e) => apply({ status: e.target.value })}
                    >
                        <option value="">All</option>
                        {data.statuses.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="o-from">From</Label>
                    <Input
                        id="o-from"
                        type="date"
                        value={filters.from}
                        onChange={(e) => apply({ from: e.target.value })}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="o-to">To</Label>
                    <Input
                        id="o-to"
                        type="date"
                        value={filters.to}
                        onChange={(e) => apply({ to: e.target.value })}
                    />
                </div>
                {filters.officer && (
                    <div className="flex items-center gap-2 text-sm sm:col-span-2 lg:col-span-4">
                        Officer filter active.
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => apply({ officer: '' })}
                        >
                            Clear
                        </Button>
                    </div>
                )}
            </div>

            <section className="space-y-3">
                <h2 className="text-sm font-semibold">
                    Officers (hits in the period; soft limit {data.softLimit} a
                    day)
                </h2>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Officer
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Hits
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Today
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Suppressed
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {data.officers.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="p-4 text-muted-foreground"
                                    >
                                        No hits in the period.
                                    </td>
                                </tr>
                            )}
                            {data.officers.map((o) => (
                                <tr
                                    key={o.ulid ?? o.name}
                                    className={cn(
                                        o.over_soft_limit &&
                                            'bg-amber-50 dark:bg-amber-950/50',
                                    )}
                                >
                                    <td className="px-3 py-2">
                                        <button
                                            type="button"
                                            className="font-medium hover:underline"
                                            onClick={() =>
                                                o.ulid &&
                                                apply({ officer: o.ulid })
                                            }
                                        >
                                            {o.name}
                                        </button>
                                        {o.over_soft_limit && (
                                            <AlertTriangle
                                                className="ml-1 inline size-4 text-amber-600"
                                                aria-label="Over the soft limit today"
                                            />
                                        )}
                                        <div className="text-xs text-muted-foreground">
                                            {o.place}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {o.hits}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {o.today}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {o.suppressed}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="space-y-3">
                <h2 className="text-sm font-semibold">Requests</h2>
                <RequestsTable requests={data.requests.data} />
                <Pagination page={data.requests} />
            </section>
            <div className="space-y-3">
                <GrantsTable title="Grants" grants={data.grants.data} />
                <Pagination page={data.grants} />
            </div>
            <section className="space-y-3">
                <h2 className="text-sm font-semibold">
                    Match events (including suppressed)
                </h2>
                <EventsTable events={data.events.data} />
                <Pagination page={data.events} />
            </section>
        </div>
    );
}

/** Cross-jurisdiction access requests, grants, delegations and oversight. */
export default function AccessRequestsIndex(props: Props) {
    const { tab, tabs } = props;

    return (
        <>
            <Head title="Access requests" />
            <div className="space-y-6 p-4">
                <Heading
                    help={{
                        topic: 'guides/persons-and-cross-state-requests',
                        section:
                            'requesting-access-lga-security-chief-state-director-deputy',
                    }}
                    title="Access requests"
                    description="Temporary, read-only access to records held by other jurisdictions. Every step and every view is recorded."
                />

                <nav
                    className="flex gap-1 overflow-x-auto border-b"
                    aria-label="Access request sections"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={AccessRequestController.index({
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

                {tab === 'mine' && props.mine && (
                    <div className="space-y-3">
                        <RequestsTable
                            requests={props.mine.data}
                            showRequester={false}
                        />
                        <Pagination page={props.mine} />
                    </div>
                )}
                {tab === 'decide' && props.decide && (
                    <div className="space-y-3">
                        {props.decide.length === 0 &&
                            !props.isDelegatedDeputy && (
                                <p className="text-sm text-muted-foreground">
                                    Deputy State Directors decide only while
                                    holding a delegation from their State
                                    Director.
                                </p>
                            )}
                        <RequestsTable requests={props.decide} />
                    </div>
                )}
                {tab === 'escalated' && props.escalated && (
                    <RequestsTable requests={props.escalated} />
                )}
                {tab === 'grants' && props.grants && (
                    <div className="space-y-8">
                        <GrantsTable
                            title="Access I hold"
                            grants={props.grants.held}
                        />
                        <GrantsTable
                            title="Access granted on records I oversee"
                            grants={props.grants.issued}
                        />
                    </div>
                )}
                {tab === 'delegations' && props.delegations && (
                    <DelegationsPanel data={props.delegations} />
                )}
                {tab === 'activity' && props.activity && (
                    <section className="space-y-3">
                        <p className="text-sm text-muted-foreground">
                            Hits on your state's records shown to officers
                            elsewhere in the last 7 days.
                        </p>
                        <EventsTable events={props.activity} />
                    </section>
                )}
                {tab === 'oversight' && props.oversight && (
                    <OversightPanel data={props.oversight} />
                )}
            </div>
        </>
    );
}

AccessRequestsIndex.layout = {
    breadcrumbs: [
        { title: 'Access requests', href: AccessRequestController.index() },
    ],
};

/** A `datetime-local` value in the browser's own time (not UTC). */
function localDateTime(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 16);
}
