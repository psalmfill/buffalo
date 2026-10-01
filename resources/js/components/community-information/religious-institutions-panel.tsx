import { Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ReligiousInstitutionController from '@/actions/App/Http/Controllers/CommunityInformation/ReligiousInstitutionController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import BuffaloMap from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type {
    InformationCan,
    InformationOptions,
    InstitutionRow,
    InstitutionsTab,
} from '@/types/community-information';

function SummaryList({
    title,
    items,
}: {
    title: string;
    items: { label: string; count: number }[];
}) {
    return (
        <section className="rounded-lg border p-4">
            <h3 className="mb-2 text-sm font-medium">{title}</h3>
            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">None recorded.</p>
            ) : (
                <ul className="space-y-1 text-sm">
                    {items.map((item) => (
                        <li key={item.label} className="flex justify-between">
                            <span>{item.label}</span>
                            <span className="font-medium tabular-nums">
                                {item.count}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/**
 * Religious institutions of an LGA or a community: counts by religion and
 * type (computed, never stored), filters, list and map.
 */
export default function ReligiousInstitutionsPanel({
    lgaUlid,
    communityUlid,
    baseUrl,
    data,
    options,
    can,
}: {
    lgaUlid: string;
    /** Set on a community page. */
    communityUlid?: string;
    /** The current page's tab URL, for filters. */
    baseUrl: string;
    data: InstitutionsTab;
    options: InformationOptions;
    can: InformationCan;
}) {
    const { values, apply, reset } = useFilters(baseUrl, data.filters);
    const [deleting, setDeleting] = useState<InstitutionRow | null>(null);

    return (
        <div className="space-y-4">
            <div className="grid gap-4 md:grid-cols-3">
                <section className="rounded-lg border p-4">
                    <h3 className="text-sm font-medium">
                        Religious institutions
                    </h3>
                    <p className="mt-1 text-3xl font-semibold tabular-nums">
                        {data.summary.total}
                    </p>
                </section>
                <SummaryList
                    title="By religion"
                    items={data.summary.byReligion}
                />
                <SummaryList title="By type" items={data.summary.byType} />
            </div>

            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="flex-1">
                    <FilterBar onApply={() => apply()} onReset={reset}>
                        <div className="grid gap-2">
                            <Label htmlFor="religion">Religion</Label>
                            <NativeSelect
                                id="religion"
                                value={values.religion}
                                onChange={(event) =>
                                    apply({ religion: event.target.value })
                                }
                            >
                                <option value="">All religions</option>
                                {data.religions.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="institution-type">Type</Label>
                            <NativeSelect
                                id="institution-type"
                                value={values.type}
                                onChange={(event) =>
                                    apply({ type: event.target.value })
                                }
                            >
                                <option value="">All types</option>
                                {options.institutionTypes.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {!communityUlid && (
                            <div className="grid gap-2">
                                <Label htmlFor="institution-community">
                                    Community
                                </Label>
                                <NativeSelect
                                    id="institution-community"
                                    value={values.community}
                                    onChange={(event) =>
                                        apply({ community: event.target.value })
                                    }
                                >
                                    <option value="">All communities</option>
                                    {data.communities.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        )}
                    </FilterBar>
                </div>
                {can.addReligiousInstitution && (
                    <Button asChild>
                        <Link
                            href={ReligiousInstitutionController.create(
                                lgaUlid,
                                {
                                    query: communityUlid
                                        ? { community: communityUlid }
                                        : {},
                                },
                            )}
                        >
                            <Plus /> Add institution
                        </Link>
                    </Button>
                )}
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <div className="space-y-3">
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Name
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Religion
                                    </th>
                                    {!communityUlid && (
                                        <th className="px-3 py-2 font-medium">
                                            Community
                                        </th>
                                    )}
                                    <th className="px-3 py-2 text-right font-medium">
                                        Congregation
                                    </th>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {data.list.data.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="p-0">
                                            <EmptyState
                                                title="No religious institutions"
                                                description="None recorded yet, or none match these filters."
                                            />
                                        </td>
                                    </tr>
                                )}
                                {data.list.data.map((institution) => (
                                    <tr key={institution.ulid}>
                                        <td className="px-3 py-2 font-medium">
                                            {institution.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {institution.type.label}
                                        </td>
                                        <td className="px-3 py-2">
                                            {institution.religion.name}
                                            {institution.denomination && (
                                                <div className="text-xs text-muted-foreground">
                                                    {
                                                        institution.denomination
                                                            .name
                                                    }
                                                </div>
                                            )}
                                        </td>
                                        {!communityUlid && (
                                            <td className="px-3 py-2">
                                                {institution.community.name}
                                            </td>
                                        )}
                                        <td className="px-3 py-2 text-right tabular-nums">
                                            {institution.estimated_congregation?.toLocaleString(
                                                'en-NG',
                                            ) ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            {can.addReligiousInstitution && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    asChild
                                                >
                                                    <Link
                                                        href={ReligiousInstitutionController.edit(
                                                            institution.ulid,
                                                        )}
                                                        aria-label={`Edit ${institution.name}`}
                                                    >
                                                        <Pencil />
                                                    </Link>
                                                </Button>
                                            )}
                                            {can.deleteReligiousInstitutions && (
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Delete ${institution.name}`}
                                                    onClick={() =>
                                                        setDeleting(institution)
                                                    }
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Pagination page={data.list} />
                </div>
                <BuffaloMap
                    center={data.map.center ?? undefined}
                    zoom={data.map.zoom}
                    outline={data.map.outline}
                    markers={data.markers}
                    fitMarkers
                    className="h-[420px] w-full rounded-lg border"
                />
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Delete "${deleting.name}"?`}
                    description="It is removed from lists and counts. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={
                        ReligiousInstitutionController.destroy(deleting.ulid)
                            .url
                    }
                />
            )}
        </div>
    );
}
