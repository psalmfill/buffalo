import { Head, Link } from '@inertiajs/react';
import ExportListButton from '@/components/exports/export-list-button';
import { Plus } from 'lucide-react';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { GroupOptions, GroupRow } from '@/types/political';

type Filters = {
    q: string;
    type: string;
    status: string;
    classification: string;
    lga: string;
};

/** Criminal groups based within the user's jurisdiction. */
export default function CriminalGroupsIndex({
    groups,
    filters,
    options,
    lgas,
    can,
}: {
    groups: Paginated<GroupRow>;
    filters: Filters;
    options: GroupOptions;
    lgas: Option[];
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        CriminalGroupController.index().url,
        filters,
    );

    const select = (
        field: keyof Filters,
        label: string,
        items: Option[],
        all: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <NativeSelect
                id={`filter-${field}`}
                value={values[field]}
                onChange={(e) => apply({ [field]: e.target.value })}
            >
                <option value="">{all}</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    return (
        <>
            <Head title="Criminal groups" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Criminal groups"
                        description="Groups based within your jurisdiction. Groups based elsewhere are not listed, even if they operate here."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportListButton
                            list="criminal_groups"
                            filters={{ ...filters }}
                        />
                        {can.create && (
                            <Button asChild>
                                <Link href={CriminalGroupController.create()}>
                                    <Plus /> Add group
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2">
                        <Label htmlFor="filter-q">Name</Label>
                        <Input
                            id="filter-q"
                            value={values.q}
                            onChange={(e) =>
                                setValues({ ...values, q: e.target.value })
                            }
                        />
                    </div>
                    {select('type', 'Type', options.types, 'All types')}
                    {select('status', 'Status', options.statuses, 'Any')}
                    {select(
                        'classification',
                        'Classification',
                        options.classifications,
                        'Any',
                    )}
                    {lgas.length > 0 &&
                        select('lga', 'Home LGA', lgas, 'All LGAs')}
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Group</th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Est. size
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Members
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Home LGA
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {groups.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-0">
                                        <EmptyState
                                            title="No criminal groups"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {groups.data.map((group) => (
                                <tr key={group.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={CriminalGroupController.show(
                                                group.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {group.name}
                                        </Link>
                                        {group.other_names.length > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                {group.other_names.join(', ')}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {group.type.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {group.status.label}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {group.estimated_size?.toLocaleString(
                                            'en-NG',
                                        ) ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {group.member_count}
                                    </td>
                                    <td className="px-3 py-2">
                                        {group.lga.name}
                                        <div className="text-xs text-muted-foreground">
                                            {group.state.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={group.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={groups} />
            </div>
        </>
    );
}

CriminalGroupsIndex.layout = {
    breadcrumbs: [
        { title: 'Criminal groups', href: CriminalGroupController.index() },
    ],
};
