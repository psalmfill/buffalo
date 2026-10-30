import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import HierarchyController from '@/actions/App/Http/Controllers/System/HierarchyController';
import Heading from '@/components/heading';
import EntryDialog from '@/components/hierarchy/entry-dialog';
import TotalsWarning from '@/components/hierarchy/totals-warning';
import { Button } from '@/components/ui/button';
import type {
    HierarchyCount,
    HierarchyStateRow,
    HierarchyTotals,
} from '@/types/hierarchy';

type Props = {
    states: HierarchyStateRow[];
    totals: HierarchyTotals;
    warnings: string[];
};

function Count({ count }: { count: HierarchyCount }) {
    return (
        <>
            {count.active}
            {count.inactive > 0 && (
                <span className="text-muted-foreground">
                    {' '}
                    (+{count.inactive} deactivated)
                </span>
            )}
        </>
    );
}

/**
 * Administration › Hierarchy: the 37 states with their districts and LGAs.
 * States are fixed (capital only); districts, LGAs and wards are managed on
 * each state's page.
 */
export default function HierarchyIndex({ states, totals, warnings }: Props) {
    const [editing, setEditing] = useState<HierarchyStateRow | null>(null);

    return (
        <>
            <Head title="Hierarchy" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Hierarchy"
                    description={`States, senatorial districts, LGAs and wards. ${totals.districts.active} active districts and ${totals.lgas.active} active LGAs nationally. Entries are deactivated, never deleted: records keep the entries they were given. The official CSV import remains the source of truth.`}
                />

                <TotalsWarning warnings={warnings} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 font-medium">Code</th>
                                <th className="px-3 py-2 font-medium">Zone</th>
                                <th className="px-3 py-2 font-medium">
                                    Capital
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Districts
                                </th>
                                <th className="px-3 py-2 font-medium">LGAs</th>
                                <th className="px-3 py-2 font-medium">
                                    Created on screen
                                </th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {states.map((state) => (
                                <tr key={state.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={HierarchyController.state(
                                                state.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {state.name}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {state.code}
                                    </td>
                                    <td className="px-3 py-2">{state.zone}</td>
                                    <td className="px-3 py-2">
                                        {state.capital ?? '—'}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        <Count count={state.districts} />
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        <Count count={state.lgas} />
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {state.created_on_screen || '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            data-test={`edit-capital-${state.code}`}
                                            onClick={() => setEditing(state)}
                                        >
                                            Edit capital
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={HierarchyController.state(
                                                    state.ulid,
                                                )}
                                            >
                                                Districts and LGAs
                                            </Link>
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {editing && (
                <EntryDialog
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    title={`Capital of ${editing.name}`}
                    description="States themselves are fixed (exactly 37): only the capital can be edited here."
                    submitLabel="Save capital"
                    method="put"
                    url={HierarchyController.updateCapital(editing.ulid).url}
                    fields={[{ name: 'capital', label: 'Capital' }]}
                    initial={{ capital: editing.capital ?? '' }}
                />
            )}
        </>
    );
}

HierarchyIndex.layout = {
    breadcrumbs: [{ title: 'Hierarchy', href: HierarchyController.index() }],
};
