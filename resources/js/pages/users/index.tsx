import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import UserStatusBadges, {
    statusOptions,
} from '@/components/users/user-status-badges';
import { formatDateTime } from '@/lib/time';
import type { Paginated } from '@/types/pagination';
import type { NamedOption, RoleOption, UserRow } from '@/types/users';

type Filters = {
    search: string;
    role: string;
    state: string;
    status: string;
    sort: string;
    direction: 'asc' | 'desc';
};

type Props = {
    users: Paginated<UserRow>;
    filters: Filters;
    roles: RoleOption[];
    states: NamedOption[];
    can: { create: boolean };
};

const columns: { key: string; label: string; sortable: boolean }[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', sortable: true },
    { key: 'service_number', label: 'Service no.', sortable: true },
    { key: 'rank', label: 'Rank', sortable: true },
    { key: 'role', label: 'Role', sortable: false },
    { key: 'jurisdiction', label: 'Jurisdiction', sortable: false },
    { key: 'status', label: 'Status', sortable: false },
    { key: 'last_login_at', label: 'Last login', sortable: true },
];

export default function UsersIndex({
    users,
    filters,
    roles,
    states,
    can,
}: Props) {
    const [values, setValues] = useState<Filters>(filters);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...values, ...next };
        setValues(merged);
        router.get(
            UserController.index().url,
            Object.fromEntries(
                Object.entries(merged).filter(([, value]) => value !== ''),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        apply({ search: values.search });
    };

    const sortBy = (key: string) =>
        apply({
            sort: key,
            direction:
                values.sort === key && values.direction === 'asc'
                    ? 'desc'
                    : 'asc',
        });

    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Users"
                        description={
                            can.create
                                ? 'Create and manage user accounts'
                                : 'Users within your jurisdiction (read-only)'
                        }
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={UserController.create()}>
                                <Plus /> New user
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="grid gap-3 md:grid-cols-4">
                    <form onSubmit={submitSearch} className="md:col-span-1">
                        <Input
                            type="search"
                            placeholder="Search name, email, service no."
                            value={values.search}
                            onChange={(event) =>
                                setValues({
                                    ...values,
                                    search: event.target.value,
                                })
                            }
                            aria-label="Search users"
                        />
                    </form>
                    <NativeSelect
                        value={values.role}
                        onChange={(event) =>
                            apply({ role: event.target.value })
                        }
                        aria-label="Filter by role"
                    >
                        <option value="">All roles</option>
                        {roles.map((role) => (
                            <option key={role.value} value={role.value}>
                                {role.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        value={values.state}
                        onChange={(event) =>
                            apply({ state: event.target.value })
                        }
                        aria-label="Filter by state"
                    >
                        <option value="">All states</option>
                        {states.map((state) => (
                            <option key={state.ulid} value={state.ulid}>
                                {state.name}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        value={values.status}
                        onChange={(event) =>
                            apply({ status: event.target.value })
                        }
                        aria-label="Filter by status"
                    >
                        <option value="">All statuses</option>
                        {statusOptions.map((status) => (
                            <option key={status.value} value={status.value}>
                                {status.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className="px-3 py-2 font-medium"
                                    >
                                        {column.sortable ? (
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1"
                                                onClick={() =>
                                                    sortBy(column.key)
                                                }
                                            >
                                                {column.label}
                                                {values.sort === column.key &&
                                                    (values.direction ===
                                                    'asc' ? (
                                                        <ArrowUp className="size-3" />
                                                    ) : (
                                                        <ArrowDown className="size-3" />
                                                    ))}
                                            </button>
                                        ) : (
                                            column.label
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={columns.length}
                                        className="px-3 py-6 text-center text-muted-foreground"
                                    >
                                        No users found
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr
                                    key={user.ulid}
                                    className="hover:bg-muted/30"
                                >
                                    <td className="px-3 py-2 font-medium">
                                        <Link
                                            href={UserController.show(
                                                user.ulid,
                                            )}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {user.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">{user.email}</td>
                                    <td className="px-3 py-2">
                                        {user.service_number ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {user.rank ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {user.role?.label ?? 'No role'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {user.jurisdiction}
                                    </td>
                                    <td className="px-3 py-2">
                                        <UserStatusBadges
                                            statuses={user.statuses}
                                        />
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {user.last_login_at
                                            ? formatDateTime(user.last_login_at)
                                            : 'Never'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination page={users} />
            </div>
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: UserController.index() }],
};
