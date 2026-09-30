import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useMemo, useState } from 'react';
import CoverageReportController from '@/actions/App/Http/Controllers/Security/CoverageReportController';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { ClassificationLevel } from '@/types/auth';
import type {
    CoverageOptions,
    CoverageReportDetail,
    Deployment,
    SuggestedPeriod,
} from '@/types/coverage';
import type { AgencyOption } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

type Props = {
    report: CoverageReportDetail | null;
    deployments: Deployment[];
    lga: NamedRef | null;
    lgas: Option[];
    communities: Option[];
    agencies: AgencyOption[];
    formations: (Option & { agency: string })[];
    periods: Record<string, SuggestedPeriod[]>;
    options: CoverageOptions;
    can: { submit: boolean };
};

type Row = {
    key: string;
    ulid: string | null;
    agency: string;
    formation: string;
    /** A formation the user cannot see is kept unless replaced. */
    keep_formation: boolean;
    personnel_count: string;
    vehicles_count: string;
    patrol_frequency: string;
    communities: string[];
    areas_covered: string;
    remarks: string;
};

let nextKey = 0;
const newKey = () => `new-${++nextKey}`;

function emptyRow(): Row {
    return {
        key: newKey(),
        ulid: null,
        agency: '',
        formation: '',
        keep_formation: false,
        personnel_count: '0',
        vehicles_count: '',
        patrol_frequency: 'daily',
        communities: [],
        areas_covered: '',
        remarks: '',
    };
}

/** yyyy-mm-dd in local time. */
function iso(date: Date): string {
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);

    return local.toISOString().slice(0, 10);
}

/** The weekly, monthly or quarterly period containing a date. */
function periodContaining(type: string, day: string): [string, string] | null {
    if (day === '' || type === 'ad_hoc') {
        return null;
    }

    const [y, m, d] = day.split('-').map(Number);
    const date = new Date(y, m - 1, d);

    if (type === 'weekly') {
        const offset = (date.getDay() + 6) % 7; // Monday = 0
        const start = new Date(y, m - 1, d - offset);

        return [iso(start), iso(new Date(y, m - 1, d - offset + 6))];
    }

    if (type === 'monthly') {
        return [iso(new Date(y, m - 1, 1)), iso(new Date(y, m, 0))];
    }

    const q = Math.floor((m - 1) / 3) * 3;

    return [iso(new Date(y, q, 1)), iso(new Date(y, q + 3, 0))];
}

/**
 * Create or edit a coverage report: header, deployments and the narrative.
 * The uncovered communities panel updates as communities are ticked. The
 * LGA and period are chosen once.
 */
export default function CoverageReportForm({
    report,
    deployments,
    lga,
    lgas,
    communities,
    agencies,
    formations,
    periods,
    options,
    can,
}: Props) {
    const editing = report !== null;
    const monthly = periods.monthly ?? [];
    const form = useForm({
        lga: lga?.ulid ?? '',
        period_type: report?.period_type.value ?? 'monthly',
        period_start: report?.period_start ?? monthly[0]?.start ?? '',
        period_end: report?.period_end ?? monthly[0]?.end ?? '',
        overall_rating: report?.overall_rating?.value ?? '',
        summary: report?.summary ?? '',
        gaps_identified: report?.gaps_identified ?? '',
        recommendations: report?.recommendations ?? '',
        classification: report?.classification.value ?? 'restricted',
    });
    const [rows, setRows] = useState<Row[]>(() =>
        deployments.map((d) => ({
            key: d.ulid,
            ulid: d.ulid,
            agency: d.agency.ulid,
            formation: d.formation?.ulid ?? '',
            keep_formation: d.formation_hidden,
            personnel_count: d.personnel_count.toString(),
            vehicles_count: d.vehicles_count?.toString() ?? '',
            patrol_frequency: d.patrol_frequency.value,
            communities: d.communities.map((c) => c.ulid),
            areas_covered: d.areas_covered ?? '',
            remarks: d.remarks ?? '',
        })),
    );
    const [confirmSubmit, setConfirmSubmit] = useState(false);

    const covered = useMemo(
        () => new Set(rows.flatMap((row) => row.communities)),
        [rows],
    );
    const uncovered = communities.filter((c) => !covered.has(c.value));

    const updateRow = (key: string, changes: Partial<Row>) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...changes } : row,
            ),
        );

    const chooseLga = (value: string) => {
        form.setData('lga', value);
        setRows([]);
        router.get(
            CoverageReportController.create({ query: { lga: value } }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['communities', 'formations', 'lga'],
            },
        );
    };

    const choosePeriodDay = (day: string) => {
        const period = periodContaining(form.data.period_type, day);

        if (period) {
            form.setData((data) => ({
                ...data,
                period_start: period[0],
                period_end: period[1],
            }));
        }
    };

    const send = (submit: boolean) => {
        form.transform((data) => {
            const values: Record<string, unknown> = {
                ...data,
                overall_rating:
                    data.overall_rating === '' ? null : data.overall_rating,
                submit,
                deployments: rows.map((row) => ({
                    ulid: row.ulid,
                    agency: row.agency,
                    formation: row.keep_formation
                        ? undefined
                        : row.formation === ''
                          ? null
                          : row.formation,
                    personnel_count: row.personnel_count,
                    vehicles_count:
                        row.vehicles_count === '' ? null : row.vehicles_count,
                    patrol_frequency: row.patrol_frequency,
                    communities: row.communities,
                    areas_covered: row.areas_covered,
                    remarks: row.remarks,
                })),
            };

            if (editing) {
                delete values.lga;
                delete values.period_type;
                delete values.period_start;
                delete values.period_end;
            }

            return values;
        });

        if (editing) {
            form.put(CoverageReportController.update(report.ulid).url, {
                preserveScroll: true,
            });
        } else {
            form.post(CoverageReportController.store().url, {
                preserveScroll: true,
            });
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(false);
    };

    const errors = form.errors as Record<string, string | undefined>;
    const rowError = (index: number, field: string) =>
        errors[`deployments.${index}.${field}`];

    return (
        <>
            <Head
                title={
                    editing
                        ? `Coverage report: ${report.lga.name}, ${report.period}`
                        : 'New coverage report'
                }
            />
            <form onSubmit={submit} className="space-y-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-xl font-semibold">
                        {editing
                            ? `Coverage report: ${report.lga.name} LGA, ${report.period}`
                            : 'New coverage report'}
                    </h1>
                    {editing && (
                        <p className="text-sm text-muted-foreground">
                            {report.status.label} · {report.period_type.label} ·{' '}
                            {report.state.name} State
                        </p>
                    )}
                </div>

                {report?.status.value === 'returned' &&
                    report.return_reason && (
                        <div
                            role="status"
                            className="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                        >
                            <strong>Returned</strong>
                            {report.returned_by
                                ? ` by ${report.returned_by.name}`
                                : ''}
                            : {report.return_reason}
                        </div>
                    )}

                {!editing && (
                    <section className="grid gap-4 rounded-lg border p-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="lga">LGA</Label>
                            <NativeSelect
                                id="lga"
                                value={form.data.lga}
                                onChange={(e) => chooseLga(e.target.value)}
                            >
                                <option value="">Choose an LGA</option>
                                {lgas.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.lga} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="period_type">Period type</Label>
                            <NativeSelect
                                id="period_type"
                                value={form.data.period_type}
                                onChange={(e) => {
                                    const type = e.target.value;
                                    const first = periods[type]?.[0];
                                    form.setData((data) => ({
                                        ...data,
                                        period_type: type,
                                        period_start: first?.start ?? '',
                                        period_end: first?.end ?? '',
                                    }));
                                }}
                            >
                                {options.periodTypes.map((item) => (
                                    <option key={item.value} value={item.value}>
                                        {item.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        {form.data.period_type === 'ad_hoc' ? (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="period_start">From</Label>
                                    <Input
                                        id="period_start"
                                        type="date"
                                        value={form.data.period_start}
                                        onChange={(e) =>
                                            form.setData(
                                                'period_start',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.period_start} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="period_end">To</Label>
                                    <Input
                                        id="period_end"
                                        type="date"
                                        value={form.data.period_end}
                                        onChange={(e) =>
                                            form.setData(
                                                'period_end',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={errors.period_end} />
                                </div>
                            </>
                        ) : (
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="period_day">Period</Label>
                                <div className="flex flex-wrap items-center gap-2">
                                    {(periods[form.data.period_type] ?? []).map(
                                        (p) => (
                                            <Button
                                                key={p.start}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    form.data.period_start ===
                                                    p.start
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                onClick={() =>
                                                    form.setData((data) => ({
                                                        ...data,
                                                        period_start: p.start,
                                                        period_end: p.end,
                                                    }))
                                                }
                                            >
                                                {p.label}
                                            </Button>
                                        ),
                                    )}
                                    <Input
                                        id="period_day"
                                        type="date"
                                        aria-label="Any day in another period"
                                        className="w-auto"
                                        onChange={(e) =>
                                            choosePeriodDay(e.target.value)
                                        }
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {form.data.period_start
                                        ? `${form.data.period_start} to ${form.data.period_end}`
                                        : 'Choose a period.'}
                                </p>
                                <InputError message={errors.period_start} />
                            </div>
                        )}
                    </section>
                )}

                <div className="grid gap-6 xl:grid-cols-[3fr_1fr]">
                    <section className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold">
                                Deployments
                            </h2>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={communities.length === 0 && !lga}
                                onClick={() =>
                                    setRows((current) => [
                                        ...current,
                                        emptyRow(),
                                    ])
                                }
                            >
                                <Plus /> Add deployment
                            </Button>
                        </div>
                        <InputError message={errors.deployments} />
                        {rows.length === 0 && (
                            <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                                No deployments yet. Add one row per agency
                                deployment in the LGA.
                            </p>
                        )}
                        {rows.map((row, index) => (
                            <fieldset
                                key={row.key}
                                className="space-y-3 rounded-lg border p-4"
                                aria-label={`Deployment ${index + 1}`}
                            >
                                <div className="grid gap-3 md:grid-cols-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`agency-${row.key}`}>
                                            Agency
                                        </Label>
                                        <NativeSelect
                                            id={`agency-${row.key}`}
                                            value={row.agency}
                                            onChange={(e) =>
                                                updateRow(row.key, {
                                                    agency: e.target.value,
                                                    formation: '',
                                                    keep_formation: false,
                                                })
                                            }
                                        >
                                            <option value="">
                                                Choose an agency
                                            </option>
                                            {agencies.map((a) => (
                                                <option
                                                    key={a.value}
                                                    value={a.value}
                                                >
                                                    {a.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <InputError
                                            message={rowError(index, 'agency')}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`formation-${row.key}`}>
                                            Formation (optional)
                                        </Label>
                                        {row.keep_formation ? (
                                            <p className="text-sm text-muted-foreground">
                                                A formation you cannot view.{' '}
                                                <button
                                                    type="button"
                                                    className="underline"
                                                    onClick={() =>
                                                        updateRow(row.key, {
                                                            keep_formation: false,
                                                        })
                                                    }
                                                >
                                                    Replace
                                                </button>
                                            </p>
                                        ) : (
                                            <NativeSelect
                                                id={`formation-${row.key}`}
                                                value={row.formation}
                                                disabled={row.agency === ''}
                                                onChange={(e) =>
                                                    updateRow(row.key, {
                                                        formation:
                                                            e.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">None</option>
                                                {formations
                                                    .filter(
                                                        (f) =>
                                                            f.agency ===
                                                            row.agency,
                                                    )
                                                    .map((f) => (
                                                        <option
                                                            key={f.value}
                                                            value={f.value}
                                                        >
                                                            {f.label}
                                                        </option>
                                                    ))}
                                            </NativeSelect>
                                        )}
                                        <InputError
                                            message={rowError(
                                                index,
                                                'formation',
                                            )}
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`personnel-${row.key}`}
                                            >
                                                Personnel
                                            </Label>
                                            <Input
                                                id={`personnel-${row.key}`}
                                                type="number"
                                                min={0}
                                                value={row.personnel_count}
                                                onChange={(e) =>
                                                    updateRow(row.key, {
                                                        personnel_count:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label
                                                htmlFor={`vehicles-${row.key}`}
                                            >
                                                Vehicles
                                            </Label>
                                            <Input
                                                id={`vehicles-${row.key}`}
                                                type="number"
                                                min={0}
                                                value={row.vehicles_count}
                                                onChange={(e) =>
                                                    updateRow(row.key, {
                                                        vehicles_count:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <InputError
                                            className="col-span-2"
                                            message={
                                                rowError(
                                                    index,
                                                    'personnel_count',
                                                ) ??
                                                rowError(
                                                    index,
                                                    'vehicles_count',
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`patrol-${row.key}`}>
                                            Patrol frequency
                                        </Label>
                                        <NativeSelect
                                            id={`patrol-${row.key}`}
                                            value={row.patrol_frequency}
                                            onChange={(e) =>
                                                updateRow(row.key, {
                                                    patrol_frequency:
                                                        e.target.value,
                                                })
                                            }
                                        >
                                            {options.patrolFrequencies.map(
                                                (p) => (
                                                    <option
                                                        key={p.value}
                                                        value={p.value}
                                                    >
                                                        {p.label}
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <span className="text-sm font-medium">
                                        Communities covered (
                                        {row.communities.length})
                                    </span>
                                    {communities.length === 0 ? (
                                        <p className="text-xs text-muted-foreground">
                                            {lga
                                                ? 'No communities recorded for this LGA.'
                                                : 'Choose an LGA first.'}
                                        </p>
                                    ) : (
                                        <div className="grid max-h-40 gap-1 overflow-y-auto rounded border p-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {communities.map((c) => {
                                                const id = `c-${row.key}-${c.value}`;
                                                const checked =
                                                    row.communities.includes(
                                                        c.value,
                                                    );

                                                return (
                                                    <label
                                                        key={c.value}
                                                        htmlFor={id}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            id={id}
                                                            checked={checked}
                                                            onCheckedChange={(
                                                                value,
                                                            ) =>
                                                                updateRow(
                                                                    row.key,
                                                                    {
                                                                        communities:
                                                                            value ===
                                                                            true
                                                                                ? [
                                                                                      ...row.communities,
                                                                                      c.value,
                                                                                  ]
                                                                                : row.communities.filter(
                                                                                      (
                                                                                          v,
                                                                                      ) =>
                                                                                          v !==
                                                                                          c.value,
                                                                                  ),
                                                                    },
                                                                )
                                                            }
                                                        />
                                                        {c.label}
                                                    </label>
                                                );
                                            })}
                                        </div>
                                    )}
                                    <InputError
                                        message={rowError(index, 'communities')}
                                    />
                                </div>

                                <div className="grid gap-3 md:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor={`areas-${row.key}`}>
                                            Areas covered
                                        </Label>
                                        <Input
                                            id={`areas-${row.key}`}
                                            value={row.areas_covered}
                                            onChange={(e) =>
                                                updateRow(row.key, {
                                                    areas_covered:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor={`remarks-${row.key}`}>
                                            Remarks
                                        </Label>
                                        <Input
                                            id={`remarks-${row.key}`}
                                            value={row.remarks}
                                            onChange={(e) =>
                                                updateRow(row.key, {
                                                    remarks: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        setRows((current) =>
                                            current.filter(
                                                (r) => r.key !== row.key,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 /> Remove deployment
                                </Button>
                            </fieldset>
                        ))}
                    </section>

                    <aside
                        className="h-fit space-y-2 rounded-lg border p-4 xl:sticky xl:top-4"
                        aria-live="polite"
                        data-test="uncovered-panel"
                    >
                        <h2 className="text-sm font-semibold">
                            Uncovered communities: {uncovered.length} of{' '}
                            {communities.length}
                        </h2>
                        {communities.length > 0 && uncovered.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Every community is covered by a deployment.
                            </p>
                        ) : (
                            <ul className="max-h-96 space-y-1 overflow-y-auto text-sm">
                                {uncovered.map((c) => (
                                    <li key={c.value}>{c.label}</li>
                                ))}
                            </ul>
                        )}
                    </aside>
                </div>

                <section className="grid gap-4 rounded-lg border p-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="overall_rating">Overall rating</Label>
                        <NativeSelect
                            id="overall_rating"
                            value={form.data.overall_rating}
                            onChange={(e) =>
                                form.setData('overall_rating', e.target.value)
                            }
                        >
                            <option value="">Not rated yet</option>
                            {options.ratings.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.overall_rating} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="classification">Classification</Label>
                        <NativeSelect
                            id="classification"
                            value={form.data.classification}
                            onChange={(e) =>
                                form.setData(
                                    'classification',
                                    e.target.value as ClassificationLevel,
                                )
                            }
                        >
                            {options.classifications.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.classification} />
                    </div>
                    {(
                        [
                            ['summary', 'Summary'],
                            [
                                'gaps_identified',
                                'Gaps identified (required unless coverage is adequate)',
                            ],
                            ['recommendations', 'Recommendations'],
                        ] as const
                    ).map(([field, label]) => (
                        <div key={field} className="grid gap-2 md:col-span-2">
                            <Label htmlFor={field}>{label}</Label>
                            <Textarea
                                id={field}
                                rows={4}
                                value={form.data[field]}
                                onChange={(e) =>
                                    form.setData(field, e.target.value)
                                }
                            />
                            <InputError message={errors[field]} />
                        </div>
                    ))}
                </section>

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        Save draft
                    </Button>
                    {can.submit &&
                        (confirmSubmit ? (
                            <>
                                <span className="text-sm">
                                    Submit for review? The State Director is
                                    notified.
                                </span>
                                <Button
                                    type="button"
                                    disabled={form.processing}
                                    onClick={() => send(true)}
                                >
                                    Confirm submit
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setConfirmSubmit(false)}
                                >
                                    Cancel
                                </Button>
                            </>
                        ) : (
                            <Button
                                type="button"
                                disabled={form.processing}
                                onClick={() => setConfirmSubmit(true)}
                            >
                                Submit for review
                            </Button>
                        ))}
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? CoverageReportController.show(report.ulid)
                                    : CoverageReportController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CoverageReportForm.layout = {
    breadcrumbs: [
        { title: 'Coverage reports', href: CoverageReportController.index() },
    ],
};
