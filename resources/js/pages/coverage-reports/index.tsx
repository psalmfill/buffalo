import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import CoverageReportController from '@/actions/App/Http/Controllers/Security/CoverageReportController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import {
    CoverageStatusBadge,
    RatingBadge,
} from '@/components/security/coverage-badges';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CoverageOptions, CoverageReportRow } from '@/types/coverage';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Filters = {
    state: string;
    lga: string;
    period_type: string;
    period: string;
    status: string;
    rating: string;
};

/** Security coverage reports within the user's jurisdiction. */
export default function CoverageReportsIndex({
    reports,
    filters,
    options,
    states,
    lgas,
    can,
}: {
    reports: Paginated<CoverageReportRow & { can_review: boolean }>;
    filters: Filters;
    options: CoverageOptions;
    states: Option[];
    lgas: Option[];
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        CoverageReportController.index().url,
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
            <Head title="Coverage reports" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{ topic: 'guides/coverage-reports' }}
                        title="Coverage reports"
                        description="Each LGA's statement, per period, of which agencies are deployed where, what they cover and where the gaps are."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={CoverageReportController.create()}>
                                <Plus /> New report
                            </Link>
                        </Button>
                    )}
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    {states.length > 0 &&
                        select('state', 'State', states, 'All states')}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    {select(
                        'period_type',
                        'Period type',
                        options.periodTypes,
                        'Any',
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="filter-period">Period including</Label>
                        <Input
                            id="filter-period"
                            type="date"
                            value={values.period}
                            onChange={(e) =>
                                setValues({ ...values, period: e.target.value })
                            }
                        />
                    </div>
                    {select('status', 'Status', options.statuses, 'Any')}
                    {select('rating', 'Rating', options.ratings, 'Any')}
                </FilterBar>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">
                                    Period
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Rating
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Submitted
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {reports.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="p-0">
                                        <EmptyState
                                            title="No coverage reports"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {reports.data.map((r) => (
                                <tr key={r.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={CoverageReportController.show(
                                                r.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {r.lga.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {r.state.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        {r.period}
                                        <div className="text-xs text-muted-foreground">
                                            {r.period_type.label}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <CoverageStatusBadge
                                            status={r.status}
                                        />
                                        {r.can_review && (
                                            <span className="ml-2 text-xs font-medium text-blue-700 dark:text-blue-300">
                                                Awaiting your review
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <RatingBadge
                                            rating={r.overall_rating}
                                        />
                                    </td>
                                    <td className="px-3 py-2">
                                        {r.submitted_at
                                            ? new Date(
                                                  r.submitted_at,
                                              ).toLocaleDateString('en-NG')
                                            : '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={r.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={reports} />
            </div>
        </>
    );
}

CoverageReportsIndex.layout = {
    breadcrumbs: [
        { title: 'Coverage reports', href: CoverageReportController.index() },
    ],
};
