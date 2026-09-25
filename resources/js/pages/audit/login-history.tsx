import { Head } from '@inertiajs/react';
import LoginHistoryController from '@/actions/App/Http/Controllers/Audit/LoginHistoryController';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import LoginHistoryTable from '@/components/users/login-history-table';
import type { Paginated } from '@/types/pagination';
import type { LoginEntry, NamedOption } from '@/types/users';

type Filters = {
    user: string;
    email: string;
    event: string;
    ip: string;
    from: string;
    to: string;
};

type Props = {
    entries: Paginated<LoginEntry>;
    filters: Filters;
    events: string[];
    users: NamedOption[];
    suspiciousIps: string[];
};

export default function LoginHistoryIndex({
    entries,
    filters,
    events,
    users,
    suspiciousIps,
}: Props) {
    const { values, setValues, apply, reset } = useFilters<Filters>(
        LoginHistoryController.index().url,
        filters,
    );
    const set = (key: keyof Filters, value: string) =>
        setValues({ ...values, [key]: value });

    return (
        <>
            <Head title="Login history" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Login history"
                    description="Every sign-in event. Read-only."
                />

                {suspiciousIps.length > 0 && (
                    <div
                        className="rounded-md border border-red-500/40 bg-red-500/10 p-3 text-sm"
                        role="alert"
                    >
                        Repeated failures in the last 24 hours from:{' '}
                        {suspiciousIps.map((ip, index) => (
                            <button
                                key={ip}
                                type="button"
                                className="font-mono underline"
                                onClick={() => apply({ ip })}
                            >
                                {ip}
                                {index < suspiciousIps.length - 1 ? ', ' : ''}
                            </button>
                        ))}
                    </div>
                )}

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-1">
                        <Label htmlFor="user">User</Label>
                        <NativeSelect
                            id="user"
                            value={values.user}
                            onChange={(e) => set('user', e.target.value)}
                        >
                            <option value="">Anyone</option>
                            {users.map((u) => (
                                <option key={u.ulid} value={u.ulid}>
                                    {u.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="email">Email entered</Label>
                        <Input
                            id="email"
                            value={values.email}
                            onChange={(e) => set('email', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="event">Event</Label>
                        <NativeSelect
                            id="event"
                            value={values.event}
                            onChange={(e) => set('event', e.target.value)}
                        >
                            <option value="">Any</option>
                            {events.map((event) => (
                                <option key={event} value={event}>
                                    {event}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="ip">IP address</Label>
                        <Input
                            id="ip"
                            value={values.ip}
                            onChange={(e) => set('ip', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={values.from}
                            onChange={(e) => set('from', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={values.to}
                            onChange={(e) => set('to', e.target.value)}
                        />
                    </div>
                </FilterBar>

                <LoginHistoryTable entries={entries.data} showUser />
                <Pagination page={entries} />
            </div>
        </>
    );
}

LoginHistoryIndex.layout = {
    breadcrumbs: [
        { title: 'Login history', href: LoginHistoryController.index() },
    ],
};
