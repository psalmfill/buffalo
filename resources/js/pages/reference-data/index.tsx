import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import ReferenceDataController from '@/actions/App/Http/Controllers/ReferenceData/ReferenceDataController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import TableTabs from '@/components/reference-data/table-tabs';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type {
    ReferenceColumn,
    ReferenceRow,
    ReferenceTableInfo,
} from '@/types/reference-data';

type Props = {
    tables: ReferenceTableInfo[];
    table: ReferenceTableInfo;
    columns: ReferenceColumn[];
    values: ReferenceRow[];
    filters: { status: string; search: string };
    can: { manage: boolean };
};

export default function ReferenceDataIndex({
    tables,
    table,
    columns,
    values,
    filters,
    can,
}: Props) {
    const {
        values: current,
        setValues,
        apply,
        reset,
    } = useFilters(ReferenceDataController.index(table.slug).url, filters);
    const [toggling, setToggling] = useState<ReferenceRow | null>(null);

    return (
        <>
            <Head title={`${table.label} — Reference data`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Reference data"
                        description={
                            can.manage
                                ? 'Lookup values used across BUFFALO. Values are deactivated, never deleted: records keep the values they were given.'
                                : 'Lookup values used across BUFFALO (read-only).'
                        }
                    />
                    {can.manage && (
                        <Button asChild>
                            <Link
                                href={ReferenceDataController.create(
                                    table.slug,
                                )}
                            >
                                <Plus /> New {table.singular.toLowerCase()}
                            </Link>
                        </Button>
                    )}
                </div>

                <TableTabs tables={tables} current={table.slug} />

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            placeholder="Name or code"
                            value={current.search}
                            onChange={(event) =>
                                setValues({
                                    ...current,
                                    search: event.target.value,
                                })
                            }
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="status">Status</Label>
                        <NativeSelect
                            id="status"
                            value={current.status}
                            onChange={(event) =>
                                apply({ status: event.target.value })
                            }
                        >
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </NativeSelect>
                    </div>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Code</th>
                                {columns.map((column) => (
                                    <th
                                        key={column.key}
                                        className="px-3 py-2 font-medium"
                                    >
                                        {column.label}
                                    </th>
                                ))}
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                {can.manage && (
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {values.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={columns.length + 4}
                                        className="p-0"
                                    >
                                        <EmptyState
                                            title={`No ${table.label.toLowerCase()} found`}
                                            description="No values match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {values.map((row) => (
                                <tr
                                    key={row.ulid}
                                    className={
                                        row.is_active
                                            ? undefined
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <td className="px-3 py-2">
                                        <Link
                                            href={ReferenceDataController.show([
                                                table.slug,
                                                row.ulid,
                                            ])}
                                            className="font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {row.code}
                                    </td>
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className="px-3 py-2"
                                        >
                                            {row.extras[column.key] ?? '—'}
                                        </td>
                                    ))}
                                    <td className="px-3 py-2">
                                        <Badge
                                            variant={
                                                row.is_active
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {row.is_active
                                                ? 'Active'
                                                : 'Inactive'}
                                        </Badge>
                                        {row.in_use && (
                                            <Badge
                                                variant="outline"
                                                className="ml-1"
                                            >
                                                In use
                                            </Badge>
                                        )}
                                    </td>
                                    {can.manage && (
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={ReferenceDataController.edit(
                                                        [table.slug, row.ulid],
                                                    )}
                                                >
                                                    Edit
                                                </Link>
                                            </Button>
                                            {(row.can_deactivate ||
                                                !row.is_active) && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setToggling(row)
                                                    }
                                                >
                                                    {row.is_active
                                                        ? 'Deactivate'
                                                        : 'Activate'}
                                                </Button>
                                            )}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {toggling && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setToggling(null)}
                    title={`${toggling.is_active ? 'Deactivate' : 'Activate'} "${toggling.name}"?`}
                    description={
                        toggling.is_active
                            ? 'It stays on existing records but can no longer be chosen for new ones.'
                            : 'It can be chosen for new records again.'
                    }
                    confirmLabel={
                        toggling.is_active ? 'Deactivate' : 'Activate'
                    }
                    destructive={toggling.is_active}
                    url={
                        (toggling.is_active
                            ? ReferenceDataController.deactivate
                            : ReferenceDataController.activate)([
                            table.slug,
                            toggling.ulid,
                        ]).url
                    }
                />
            )}
        </>
    );
}

ReferenceDataIndex.layout = {
    breadcrumbs: [
        { title: 'Reference data', href: ReferenceDataController.home() },
    ],
};
