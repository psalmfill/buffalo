import { Head, Link } from '@inertiajs/react';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import CompletenessBar from '@/components/local-government/completeness-bar';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/time';
import type { LgaRow, NamedRef } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Props = {
    lgas: Paginated<LgaRow>;
    filters: { state: string; district: string; search: string };
    states: NamedRef[];
    districts: NamedRef[];
};

export default function LgasIndex({ lgas, filters, states, districts }: Props) {
    const { values, setValues, apply, reset } = useFilters(
        LgaController.index().url,
        filters,
    );

    return (
        <>
            <Head title="LGAs" />
            <div className="space-y-6 p-4">
                <Heading
                    title="LGAs"
                    description="Local Government Areas in your jurisdiction, with their profile completeness."
                />

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            placeholder="LGA name"
                            value={values.search}
                            onChange={(event) =>
                                setValues({
                                    ...values,
                                    search: event.target.value,
                                })
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
                                        district: '',
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
                    <div className="grid gap-2">
                        <Label htmlFor="district">Senatorial district</Label>
                        <NativeSelect
                            id="district"
                            value={values.district}
                            onChange={(event) =>
                                apply({ district: event.target.value })
                            }
                        >
                            <option value="">All districts</option>
                            {districts.map((district) => (
                                <option
                                    key={district.ulid}
                                    value={district.ulid}
                                >
                                    {district.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 font-medium">
                                    Senatorial district
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Headquarters
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Communities
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Profile
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Last reviewed
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {lgas.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-0">
                                        <EmptyState
                                            title="No LGAs found"
                                            description="No LGAs match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {lgas.data.map((lga) => (
                                <tr key={lga.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={LgaController.show(lga.ulid)}
                                            className="font-medium hover:underline"
                                        >
                                            {lga.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">{lga.state}</td>
                                    <td className="px-3 py-2">
                                        {lga.district ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {lga.headquarters ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {lga.communities}
                                    </td>
                                    <td className="px-3 py-2">
                                        <CompletenessBar
                                            percent={lga.completeness}
                                        />
                                    </td>
                                    <td className="px-3 py-2 text-muted-foreground">
                                        {lga.last_reviewed_at
                                            ? formatDateTime(
                                                  lga.last_reviewed_at,
                                              )
                                            : 'Never'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <Pagination page={lgas} />
            </div>
        </>
    );
}

LgasIndex.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
