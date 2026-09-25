import { Head } from '@inertiajs/react';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ActivityTable from '@/components/users/activity-table';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry, NamedOption } from '@/types/users';

type Filters = {
    causer: string;
    subject_type: string;
    event: string;
    from: string;
    to: string;
};

type Props = {
    entries: Paginated<ActivityEntry>;
    filters: Filters;
    subjectTypes: { value: string; label: string }[];
    events: string[];
    causers: NamedOption[];
};

export default function AuditIndex({
    entries,
    filters,
    subjectTypes,
    events,
    causers,
}: Props) {
    const { values, setValues, apply, reset } = useFilters<Filters>(
        AuditLogController.index().url,
        filters,
    );
    const set = (key: keyof Filters, value: string) =>
        setValues({ ...values, [key]: value });

    return (
        <>
            <Head title="Audit log" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Audit log"
                    description="Every change and administrative action. Read-only."
                />

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-1">
                        <Label htmlFor="causer">By</Label>
                        <NativeSelect
                            id="causer"
                            value={values.causer}
                            onChange={(e) => set('causer', e.target.value)}
                        >
                            <option value="">Anyone</option>
                            {causers.map((c) => (
                                <option key={c.ulid} value={c.ulid}>
                                    {c.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="subject_type">Record type</Label>
                        <NativeSelect
                            id="subject_type"
                            value={values.subject_type}
                            onChange={(e) =>
                                set('subject_type', e.target.value)
                            }
                        >
                            <option value="">Any</option>
                            {subjectTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="event">Event</Label>
                        <NativeSelect
                            id="event"
                            value={values.event}
                            onChange={(e) => set('event', e.target.value)}
                        >
                            <option value="">Any</option>
                            {events.map((event) => (
                                <option key={event} value={event}>
                                    {event}
                                </option>
                            ))}
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
                </FilterBar>

                <ActivityTable entries={entries.data} />
                <Pagination page={entries} />
            </div>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [{ title: 'Audit log', href: AuditLogController.index() }],
};
