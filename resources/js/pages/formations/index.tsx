import { Head, Link } from '@inertiajs/react';
import ExportListButton from '@/components/exports/export-list-button';
import { Plus } from 'lucide-react';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import FormationMap from '@/components/security/formation-map';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AgencyOption,
    FormationOptions,
    FormationRow,
} from '@/types/formations';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Filters = {
    q: string;
    agency: string;
    type: string;
    status: string;
    state: string;
    lga: string;
    temporary: string;
};

/** Agency formations within the user's jurisdiction, with a map. */
export default function FormationsIndex({
    formations,
    markers,
    filters,
    options,
    agencies,
    states,
    lgas,
    can,
}: {
    formations: Paginated<FormationRow>;
    markers: FormationRow[];
    filters: Filters;
    options: FormationOptions;
    agencies: AgencyOption[];
    states: Option[];
    lgas: Option[];
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        FormationController.index().url,
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
                onChange={(e) =>
                    apply(
                        field === 'state'
                            ? { state: e.target.value, lga: '' }
                            : { [field]: e.target.value },
                    )
                }
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
            <Head title="Agency formations" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Agency formations"
                        description="Commands, divisions, stations, bases and checkpoints of security agencies within your jurisdiction."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportListButton
                            list="formations"
                            filters={{ ...filters }}
                        />
                        {can.create && (
                            <Button asChild>
                                <Link href={FormationController.create()}>
                                    <Plus /> Add formation
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
                    {select('agency', 'Agency', agencies, 'All agencies')}
                    {select('type', 'Type', options.types, 'All types')}
                    {select('status', 'Status', options.statuses, 'Any')}
                    {states.length > 0 &&
                        select('state', 'State', states, 'All states')}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    {select(
                        'temporary',
                        'Temporary',
                        [
                            { value: 'yes', label: 'Temporary only' },
                            { value: 'no', label: 'Permanent only' },
                        ],
                        'Any',
                    )}
                </FilterBar>

                <FormationMap formations={markers} />

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Formation
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Agency
                                </th>
                                <th className="px-3 py-2 font-medium">Type</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Strength
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {formations.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-0">
                                        <EmptyState
                                            title="No formations"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {formations.data.map((f) => (
                                <tr key={f.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={FormationController.show(
                                                f.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {f.name}
                                        </Link>
                                        {f.is_temporary && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                Temporary
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.agency.short_name || f.agency.name}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.formation_type.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.status.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.lga.name}
                                        <div className="text-xs text-muted-foreground">
                                            {f.state.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {f.personnel_strength?.toLocaleString(
                                            'en-NG',
                                        ) ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={f.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={formations} />
            </div>
        </>
    );
}

FormationsIndex.layout = {
    breadcrumbs: [
        { title: 'Agency formations', href: FormationController.index() },
    ],
};
