import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import ExportReviewController from '@/actions/App/Http/Controllers/Audit/ExportReviewController';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/pagination';

type Filters = {
    user: string;
    state: string;
    kind: string;
    classification: string;
    from: string;
    to: string;
    sensitive: string;
};

type Entry = {
    at: string;
    officer: string;
    role: string | null;
    state: string | null;
    action: string;
    what: string;
    classification: string;
    rows: number | null;
    /** An export of a sensitive list (Phase 11E), with the stated reason. */
    sensitive: boolean;
    reason: string | null;
};

type Props = {
    entries: Paginated<Entry>;
    filters: Filters;
    states: { value: string; label: string }[];
};

/**
 * Exports review (Phase 8B.3): who exported, downloaded or printed what,
 * when, at which classification and how many rows. DG and DDGs (DDGs with
 * zones see officers in their zones only). Read-only.
 */
export default function ExportsReview({ entries, filters, states }: Props) {
    const { values, setValues, apply, reset } = useFilters<Filters>(
        ExportReviewController.index().url,
        filters,
    );
    const set = (key: keyof Filters, value: string) =>
        setValues({ ...values, [key]: value });

    return (
        <>
            <Head title="Exports review" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Exports review"
                    description="Every report download, evidence original, incident print and analytics export. Read-only."
                />

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-1">
                        <Label htmlFor="user">Officer</Label>
                        <Input
                            id="user"
                            value={values.user}
                            placeholder="Name"
                            onChange={(e) => set('user', e.target.value)}
                        />
                    </div>
                    {states.length > 1 && (
                        <div className="grid gap-1">
                            <Label htmlFor="state">State</Label>
                            <NativeSelect
                                id="state"
                                value={values.state}
                                onChange={(e) => set('state', e.target.value)}
                            >
                                <option value="">Any</option>
                                {states.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    )}
                    <div className="grid gap-1">
                        <Label htmlFor="kind">Type</Label>
                        <NativeSelect
                            id="kind"
                            value={values.kind}
                            onChange={(e) => set('kind', e.target.value)}
                        >
                            <option value="">Any</option>
                            <option value="report">Report downloads</option>
                            <option value="evidence">Evidence originals</option>
                            <option value="print">Incident prints</option>
                            <option value="analytics">Analytics exports</option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="classification">Classification</Label>
                        <NativeSelect
                            id="classification"
                            value={values.classification}
                            onChange={(e) =>
                                set('classification', e.target.value)
                            }
                        >
                            <option value="">Any</option>
                            <option value="restricted">Restricted</option>
                            <option value="confidential">Confidential</option>
                            <option value="secret">Secret</option>
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="from">From</Label>
                        <Input
                            id="from"
                            type="date"
                            value={values.from}
                            onChange={(e) => set('from', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">To</Label>
                        <Input
                            id="to"
                            type="date"
                            value={values.to}
                            onChange={(e) => set('to', e.target.value)}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="sensitive">Sensitive lists</Label>
                        <NativeSelect
                            id="sensitive"
                            value={values.sensitive}
                            onChange={(e) => set('sensitive', e.target.value)}
                        >
                            <option value="">All exports</option>
                            <option value="1">
                                Sensitive list exports only
                            </option>
                        </NativeSelect>
                    </div>
                </FilterBar>

                {entries.data.length === 0 ? (
                    <EmptyState title="No exports match these filters." />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table
                            className="w-full text-sm"
                            data-test="exports-review"
                        >
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2">When</th>
                                    <th className="px-3 py-2">Officer</th>
                                    <th className="px-3 py-2">Action</th>
                                    <th className="px-3 py-2">What</th>
                                    <th className="px-3 py-2">
                                        Classification
                                    </th>
                                    <th className="px-3 py-2 text-right">
                                        Rows
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((e, i) => (
                                    <tr
                                        key={i}
                                        className={cn(
                                            'border-t align-top',
                                            e.sensitive &&
                                                'bg-amber-50 dark:bg-amber-950/40',
                                        )}
                                        data-sensitive={
                                            e.sensitive ? 'true' : undefined
                                        }
                                    >
                                        <td className="px-3 py-2 whitespace-nowrap">
                                            {formatDateTime(e.at)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="font-medium">
                                                {e.officer}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {[e.role, e.state]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        </td>
                                        <td className="px-3 py-2">
                                            {e.action}
                                        </td>
                                        <td className="px-3 py-2">
                                            {e.sensitive && (
                                                <Badge
                                                    variant="destructive"
                                                    className="mb-1"
                                                >
                                                    Sensitive list
                                                </Badge>
                                            )}
                                            <div>{e.what}</div>
                                            {e.reason && (
                                                <div className="text-xs text-muted-foreground">
                                                    Reason: {e.reason}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            {e.classification}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {e.rows === null
                                                ? '—'
                                                : e.rows.toLocaleString(
                                                      'en-NG',
                                                  )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={entries} />
            </div>
        </>
    );
}

ExportsReview.layout = {
    breadcrumbs: [
        { title: 'Exports review', href: ExportReviewController.index() },
    ],
};
