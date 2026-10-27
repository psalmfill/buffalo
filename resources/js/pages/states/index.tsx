import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import StateController from '@/actions/App/Http/Controllers/LocalGovernment/StateController';
import EmptyState from '@/components/empty-state';
import ExportListButton from '@/components/exports/export-list-button';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import CompletenessBar from '@/components/local-government/completeness-bar';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/time';
import type { NamedRef } from '@/types/local-government';
import type { StateRow } from '@/types/states';

type Props = {
    states: StateRow[];
    filters: { zone: string; state: string; search: string };
    zones: NamedRef[];
    stateOptions: NamedRef[];
    can: { viewIncidents: boolean; viewThreats: boolean };
};

type Grouping = 'zone' | 'name';

export default function StatesIndex({
    states,
    filters,
    zones,
    stateOptions,
    can,
}: Props) {
    const { values, setValues, apply, reset } = useFilters(
        StateController.index().url,
        filters,
    );
    const [grouping, setGrouping] = useState<Grouping>('zone');

    const groups: { key: string; title: string | null; rows: StateRow[] }[] =
        grouping === 'name'
            ? [{ key: 'all', title: null, rows: states }]
            : Object.values(
                  states.reduce<
                      Record<
                          string,
                          { key: string; title: string; rows: StateRow[] }
                      >
                  >((acc, row) => {
                      acc[row.zone.ulid] ??= {
                          key: row.zone.ulid,
                          title: row.zone.name,
                          rows: [],
                      };
                      acc[row.zone.ulid].rows.push(row);

                      return acc;
                  }, {}),
              ).sort((a, b) => a.title.localeCompare(b.title));

    return (
        <>
            <Head title="States" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="States"
                        description="The states within your reach, with their profiles and current figures. Open a state for its profile, LGAs, leadership and security presence."
                    />
                    <ExportListButton list="states" filters={{ ...filters }} />
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2 md:col-span-2">
                        <Label htmlFor="search">Search</Label>
                        <Input
                            id="search"
                            placeholder="State name or code"
                            value={values.search}
                            onChange={(event) =>
                                setValues({
                                    ...values,
                                    search: event.target.value,
                                })
                            }
                        />
                    </div>
                    {zones.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="zone">Geopolitical zone</Label>
                            <NativeSelect
                                id="zone"
                                value={values.zone}
                                onChange={(event) =>
                                    apply({
                                        zone: event.target.value,
                                        state: '',
                                    })
                                }
                            >
                                <option value="">All zones</option>
                                {zones.map((zone) => (
                                    <option key={zone.ulid} value={zone.ulid}>
                                        {zone.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    )}
                    {stateOptions.length > 1 && (
                        <div className="grid gap-2">
                            <Label htmlFor="state">State</Label>
                            <NativeSelect
                                id="state"
                                value={values.state}
                                onChange={(event) =>
                                    apply({ state: event.target.value })
                                }
                            >
                                <option value="">All states</option>
                                {stateOptions.map((state) => (
                                    <option key={state.ulid} value={state.ulid}>
                                        {state.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    )}
                </FilterBar>

                <div
                    className="flex items-center gap-2 text-sm"
                    role="group"
                    aria-label="Group states"
                >
                    <span className="text-muted-foreground">Group:</span>
                    <Button
                        size="sm"
                        variant={grouping === 'zone' ? 'secondary' : 'ghost'}
                        aria-pressed={grouping === 'zone'}
                        onClick={() => setGrouping('zone')}
                    >
                        By zone
                    </Button>
                    <Button
                        size="sm"
                        variant={grouping === 'name' ? 'secondary' : 'ghost'}
                        aria-pressed={grouping === 'name'}
                        onClick={() => setGrouping('name')}
                    >
                        Alphabetical
                    </Button>
                    <span className="ml-auto text-muted-foreground tabular-nums">
                        {states.length}{' '}
                        {states.length === 1 ? 'state' : 'states'}
                    </span>
                </div>

                {states.length === 0 ? (
                    <div className="rounded-lg border">
                        <EmptyState
                            title="No states found"
                            description="No states match these filters."
                        />
                    </div>
                ) : (
                    groups.map((group) => (
                        <section
                            key={group.key}
                            className="space-y-2"
                            aria-label={group.title ?? 'States'}
                        >
                            {group.title && (
                                <h2 className="text-sm font-semibold">
                                    {group.title}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        ({group.rows.length})
                                    </span>
                                </h2>
                            )}
                            <StatesTable
                                rows={group.rows}
                                showZone={grouping === 'name'}
                                can={can}
                            />
                        </section>
                    ))
                )}
            </div>
        </>
    );
}

function StatesTable({
    rows,
    showZone,
    can,
}: {
    rows: StateRow[];
    showZone: boolean;
    can: Props['can'];
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">State</th>
                        {showZone && (
                            <th className="px-3 py-2 font-medium">Zone</th>
                        )}
                        <th className="px-3 py-2 font-medium">Capital</th>
                        <th className="px-3 py-2 text-right font-medium">
                            LGAs
                        </th>
                        <th className="px-3 py-2 font-medium">
                            State Director
                        </th>
                        <th className="px-3 py-2 font-medium">Profile</th>
                        <th className="px-3 py-2 font-medium">Last reviewed</th>
                        {can.viewIncidents && (
                            <th className="px-3 py-2 text-right font-medium">
                                Incidents (30 days)
                            </th>
                        )}
                        {can.viewThreats && (
                            <th className="px-3 py-2 text-right font-medium">
                                Immediate threats
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((state) => (
                        <tr key={state.ulid}>
                            <td className="px-3 py-2">
                                <Link
                                    href={StateController.show(state.ulid)}
                                    className="font-medium hover:underline"
                                >
                                    {state.name}
                                </Link>
                            </td>
                            {showZone && (
                                <td className="px-3 py-2">{state.zone.name}</td>
                            )}
                            <td className="px-3 py-2">
                                {state.capital ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {state.lgas}
                            </td>
                            <td className="px-3 py-2">
                                {state.director ?? (
                                    <span className="text-muted-foreground">
                                        Vacant
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2">
                                <CompletenessBar percent={state.completeness} />
                            </td>
                            <td className="px-3 py-2 text-muted-foreground">
                                {state.last_reviewed_at
                                    ? formatDateTime(state.last_reviewed_at)
                                    : 'Never'}
                            </td>
                            {can.viewIncidents && (
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {state.incidents30 ?? '—'}
                                </td>
                            )}
                            {can.viewThreats && (
                                <td
                                    className={
                                        'px-3 py-2 text-right tabular-nums' +
                                        ((state.immediate_threats ?? 0) > 0
                                            ? ' font-semibold text-red-700 dark:text-red-400'
                                            : '')
                                    }
                                >
                                    {state.immediate_threats ?? '—'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

StatesIndex.layout = {
    breadcrumbs: [{ title: 'States', href: StateController.index() }],
};
