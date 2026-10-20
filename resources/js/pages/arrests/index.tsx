import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import ArrestController from '@/actions/App/Http/Controllers/Arrests/ArrestController';
import ArrestsTable from '@/components/arrests/arrests-table';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ArrestOptions, ArrestRow } from '@/types/arrests';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Filters = {
    from: string;
    to: string;
    agency: string;
    formation: string;
    offence: string;
    status: string;
    state: string;
    zone: string;
    lga: string;
    linked: string;
    poi: string;
};

/** Arrests within the user's jurisdiction, most recent first (Phase 11C). */
export default function ArrestsIndex({
    arrests,
    filters,
    options,
    formations,
    states,
    zones,
    lgas,
    can,
}: {
    arrests: Paginated<ArrestRow>;
    filters: Filters;
    options: ArrestOptions;
    formations: Option[];
    states: Option[];
    zones: Option[];
    lgas: Option[];
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        ArrestController.index().url,
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

    const date = (field: 'from' | 'to', label: string) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <Input
                id={`filter-${field}`}
                type="date"
                value={values[field]}
                onChange={(e) =>
                    setValues({ ...values, [field]: e.target.value })
                }
            />
        </div>
    );

    const yesNo: Option[] = [
        { value: 'yes', label: 'Yes' },
        { value: 'no', label: 'No' },
    ];

    return (
        <>
            <Head title="Arrests" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Arrests"
                        description="Who was arrested, by which agency, for what and when."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={ArrestController.create()}>
                                <Plus /> New arrest
                            </Link>
                        </Button>
                    )}
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    {date('from', 'From')}
                    {date('to', 'To')}
                    {select('agency', 'Agency', options.agencies, 'Any agency')}
                    {formations.length > 0 &&
                        select(
                            'formation',
                            'Formation',
                            formations,
                            'Any formation',
                        )}
                    {select(
                        'offence',
                        'Offence',
                        options.offences,
                        'Any offence',
                    )}
                    {select('status', 'Status', options.statuses, 'Any status')}
                    {zones.length > 0 &&
                        select('zone', 'Zone', zones, 'All zones')}
                    {states.length > 0 &&
                        select('state', 'State', states, 'All states')}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    {select('linked', 'Linked to an incident', yesNo, 'Either')}
                    {select('poi', 'Person of interest', yesNo, 'Either')}
                </FilterBar>

                <ArrestsTable
                    arrests={arrests}
                    empty="No arrests match these filters, or none you may see."
                />
            </div>
        </>
    );
}

ArrestsIndex.layout = {
    breadcrumbs: [{ title: 'Arrests', href: ArrestController.index() }],
};
