import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/Reports/ReportController';
import ReportScheduleController from '@/actions/App/Http/Controllers/Reports/ReportScheduleController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import PlaceFields from '@/components/reports/place-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTime } from '@/lib/time';
import type {
    PlaceOptions,
    ReportScheduleRow,
    ReportTypeValue,
} from '@/types/reports';

type Props = {
    schedules: ReportScheduleRow[];
    types: { value: ReportTypeValue; label: string }[];
    defaults: { key: string; label: string; exists: boolean }[];
    options: PlaceOptions;
};

const WEEKDAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

/**
 * The user's scheduled reports (Phase 7D). Each run is generated as the
 * user, with their access at that moment; they are notified when it is
 * ready (files are never attached to emails). Times are Africa/Lagos.
 */
export default function ReportSchedules({
    schedules,
    types,
    defaults,
    options,
}: Props) {
    const [form, setForm] = useState({
        type: types[0]?.value ?? 'sitrep',
        cadence: 'daily',
        time_of_day: '07:00',
        weekday: '1',
        day_of_month: '1',
        state: '',
        lga: '',
    });
    const [errors, setErrors] = useState<Record<string, string>>({});

    const add = () =>
        router.post(
            ReportScheduleController.store.url(),
            {
                type: form.type,
                cadence: form.cadence,
                time_of_day: form.time_of_day,
                weekday:
                    form.cadence === 'weekly' ? Number(form.weekday) : null,
                day_of_month:
                    form.cadence === 'monthly'
                        ? Number(form.day_of_month)
                        : null,
                parameters: { state: form.state, lga: form.lga },
            },
            {
                preserveScroll: true,
                onError: setErrors,
                onSuccess: () => setErrors({}),
            },
        );

    return (
        <>
            <Head title="Scheduled reports" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Scheduled reports"
                    description="Generated automatically as you, with your access at the time. You are notified when each is ready; files are never attached to emails. Times are West Africa Time (Africa/Lagos)."
                />

                {defaults.some((d) => !d.exists) && (
                    <section className="flex flex-wrap gap-2">
                        {defaults
                            .filter((d) => !d.exists)
                            .map((d) => (
                                <Button
                                    key={d.key}
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            ReportScheduleController.storeDefault.url(
                                                d.key,
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Add: {d.label}
                                </Button>
                            ))}
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    {schedules.length === 0 ? (
                        <EmptyState title="You have no scheduled reports." />
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table
                                className="w-full text-sm"
                                data-test="schedules"
                            >
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2">Report</th>
                                        <th className="px-3 py-2">When</th>
                                        <th className="px-3 py-2">Next run</th>
                                        <th className="px-3 py-2">Last run</th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {schedules.map((s) => (
                                        <tr
                                            key={s.ulid}
                                            className="border-t align-top"
                                        >
                                            <td className="px-3 py-2">
                                                <div className="font-medium">
                                                    {s.type.label}{' '}
                                                    {!s.is_active && (
                                                        <Badge variant="secondary">
                                                            Paused
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {s.summary}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2">
                                                {s.describe}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {s.is_active
                                                    ? formatDateTime(
                                                          s.next_run_at,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {s.last_run_at
                                                    ? formatDateTime(
                                                          s.last_run_at,
                                                      )
                                                    : 'Not yet'}
                                            </td>
                                            <td className="flex justify-end gap-2 px-3 py-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        router.patch(
                                                            ReportScheduleController.update.url(
                                                                s.ulid,
                                                            ),
                                                            {
                                                                is_active:
                                                                    !s.is_active,
                                                            },
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {s.is_active
                                                        ? 'Pause'
                                                        : 'Resume'}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.delete(
                                                            ReportScheduleController.destroy.url(
                                                                s.ulid,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Remove
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                <section className="flex max-w-3xl flex-col gap-4 rounded-lg border p-4">
                    <h3 className="font-medium">Add a schedule</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="schedule-type">Report</Label>
                            <NativeSelect
                                id="schedule-type"
                                value={form.type}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        type: e.target.value as ReportTypeValue,
                                    })
                                }
                            >
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="schedule-cadence">Repeat</Label>
                            <NativeSelect
                                id="schedule-cadence"
                                value={form.cadence}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        cadence: e.target.value,
                                    })
                                }
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </NativeSelect>
                        </div>
                        {form.cadence === 'weekly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="schedule-weekday">Day</Label>
                                <NativeSelect
                                    id="schedule-weekday"
                                    value={form.weekday}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            weekday: e.target.value,
                                        })
                                    }
                                >
                                    {WEEKDAYS.map((d, i) => (
                                        <option key={d} value={i + 1}>
                                            {d}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </div>
                        )}
                        {form.cadence === 'monthly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="schedule-day">
                                    Day of the month (1–28)
                                </Label>
                                <Input
                                    id="schedule-day"
                                    type="number"
                                    min={1}
                                    max={28}
                                    value={form.day_of_month}
                                    onChange={(e) =>
                                        setForm({
                                            ...form,
                                            day_of_month: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="schedule-time">Time</Label>
                            <Input
                                id="schedule-time"
                                type="time"
                                value={form.time_of_day}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        time_of_day: e.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>
                    <PlaceFields
                        options={options}
                        state={form.state}
                        lga={form.lga}
                        onChange={(p) => setForm({ ...form, ...p })}
                    />
                    {Object.values(errors).map((m) => (
                        <InputError key={m} message={m} />
                    ))}
                    <div>
                        <Button onClick={add}>Add schedule</Button>
                    </div>
                </section>
            </div>
        </>
    );
}

ReportSchedules.layout = {
    breadcrumbs: [
        { title: 'Reports', href: ReportController.index() },
        {
            title: 'Scheduled reports',
            href: ReportScheduleController.index(),
        },
    ],
};
