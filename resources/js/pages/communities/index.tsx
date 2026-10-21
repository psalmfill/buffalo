import { Head, Link } from '@inertiajs/react';
import ExportListButton from '@/components/exports/export-list-button';
import { Upload } from 'lucide-react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import CommunityImportController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityImportController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CommunityRow, NamedRef, Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Props = {
    results: Paginated<CommunityRow>;
    filters: { q: string; state: string; lga: string; type: string };
    states: NamedRef[];
    lgas: NamedRef[];
    types: Option[];
    can: { import: boolean };
};

/** Cross-LGA community search within the user's jurisdiction. */
export default function CommunitiesIndex({
    results,
    filters,
    states,
    lgas,
    types,
    can,
}: Props) {
    const { values, setValues, apply, reset } = useFilters(
        CommunityController.index().url,
        filters,
    );

    return (
        <>
            <Head title="Communities" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Communities"
                        description="Search communities across your jurisdiction. The search tolerates misspellings and matches other names."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportListButton
                            list="communities"
                            filters={{ ...filters }}
                        />
                        {can.import && (
                            <Button variant="outline" asChild>
                                <Link href={CommunityImportController.create()}>
                                    <Upload /> Import CSV
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="q">Name</Label>
                        <Input
                            id="q"
                            placeholder="e.g. Ajegunle"
                            value={values.q}
                            onChange={(event) =>
                                setValues({ ...values, q: event.target.value })
                            }
                        />
                    </div>
                    {states.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="state">State</Label>
                            <NativeSelect
                                id="state"
                                value={values.state}
                                onChange={(event) =>
                                    apply({
                                        state: event.target.value,
                                        lga: '',
                                    })
                                }
                            >
                                <option value="">All states</option>
                                {states.map((state) => (
                                    <option key={state.ulid} value={state.ulid}>
                                        {state.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    )}
                    {lgas.length > 0 && (
                        <div className="grid gap-2">
                            <Label htmlFor="lga">LGA</Label>
                            <NativeSelect
                                id="lga"
                                value={values.lga}
                                onChange={(event) =>
                                    apply({ lga: event.target.value })
                                }
                            >
                                <option value="">All LGAs</option>
                                {lgas.map((lga) => (
                                    <option key={lga.ulid} value={lga.ulid}>
                                        {lga.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="type">Type</Label>
                        <NativeSelect
                            id="type"
                            value={values.type}
                            onChange={(event) =>
                                apply({ type: event.target.value })
                            }
                        >
                            <option value="">All types</option>
                            {types.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Community
                                </th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 font-medium">Ward</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {results.data.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="p-0">
                                        <EmptyState
                                            title="No communities found"
                                            description="Try another spelling or fewer filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {results.data.map((community) => (
                                <tr key={community.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={CommunityController.show(
                                                community.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {community.name}
                                        </Link>
                                        {community.other_names.length > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                Also:{' '}
                                                {community.other_names.join(
                                                    ', ',
                                                )}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {community.type.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={LgaController.show(
                                                community.lga.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {community.lga.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        {community.state}
                                    </td>
                                    <td className="px-3 py-2">
                                        {community.ward ?? '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination page={results} />
            </div>
        </>
    );
}

CommunitiesIndex.layout = {
    breadcrumbs: [{ title: 'Communities', href: CommunityController.index() }],
};
