import { Head, Link } from '@inertiajs/react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import PersonAvatar from '@/components/persons/person-avatar';
import RiskBadge from '@/components/political/risk-badge';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { PoiOptions, PoiRow } from '@/types/political';

type Filters = {
    risk: string;
    status: string;
    offence: string;
    lga: string;
    overdue: string;
};

/**
 * Persons of interest within the user's jurisdiction (each visible only
 * when its person is), highest risk first, then most recently sighted.
 */
export default function PersonsOfInterestIndex({
    results,
    filters,
    options,
    lgas,
}: {
    results: Paginated<PoiRow>;
    filters: Filters;
    options: PoiOptions;
    lgas: Option[];
}) {
    const { values, apply, reset } = useFilters(
        PersonOfInterestController.index().url,
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
            <Head title="Persons of interest" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Persons of interest"
                    description="Highest risk first, then most recently sighted. Designate a person from their person page."
                />

                <FilterBar onApply={() => apply()} onReset={reset}>
                    {select('risk', 'Risk level', options.riskLevels, 'Any')}
                    {select('status', 'Status', options.statuses, 'Any')}
                    {select(
                        'offence',
                        'Offence category',
                        options.offenceCategories,
                        'Any',
                    )}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    <div className="flex items-center gap-2 self-end pb-2">
                        <Checkbox
                            id="filter-overdue"
                            checked={values.overdue === '1'}
                            onCheckedChange={(checked) =>
                                apply({ overdue: checked === true ? '1' : '' })
                            }
                        />
                        <Label htmlFor="filter-overdue">Review overdue</Label>
                    </div>
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Person
                                </th>
                                <th className="px-3 py-2 font-medium">Risk</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Offences
                                </th>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">
                                    Last sighted
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Review due
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {results.data.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="p-0">
                                        <EmptyState
                                            title="No persons of interest"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {results.data.map((poi) => (
                                <tr key={poi.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={PersonController.show(
                                                poi.person.ulid,
                                                { query: { tab: 'poi' } },
                                            )}
                                            className="flex items-center gap-3 font-medium hover:underline"
                                        >
                                            <PersonAvatar
                                                src={poi.person.thumbnail}
                                                name={poi.person.name}
                                                className="size-8"
                                            />
                                            <span>
                                                {poi.person.name}{' '}
                                                <ClassificationBadge
                                                    level={
                                                        poi.person
                                                            .classification
                                                            .value
                                                    }
                                                />
                                            </span>
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        <RiskBadge risk={poi.risk_level} />
                                    </td>
                                    <td className="px-3 py-2">
                                        {poi.status.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {poi.offence_categories
                                            .map((o) => o.label)
                                            .join(', ') || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {poi.lga.name}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {poi.last_sighted_at
                                            ? new Date(
                                                  poi.last_sighted_at,
                                              ).toLocaleDateString('en-NG')
                                            : '—'}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {poi.review_due_at}{' '}
                                        {poi.review_overdue && (
                                            <Badge variant="destructive">
                                                Overdue
                                            </Badge>
                                        )}
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

PersonsOfInterestIndex.layout = {
    breadcrumbs: [
        {
            title: 'Persons of interest',
            href: PersonOfInterestController.index(),
        },
    ],
};
