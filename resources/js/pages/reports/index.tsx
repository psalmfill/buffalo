import { Head, Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import { Download } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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
import { useCurrentUser } from '@/hooks/use-auth';
import { postJson } from '@/lib/http';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type {
    PlaceOptions,
    ReportFormatValue,
    ReportParameters,
    ReportPrefill,
    ReportRunRow,
    ReportTypeOption,
    ReportTypeValue,
} from '@/types/reports';

type Props = {
    types: ReportTypeOption[];
    prefill: ReportPrefill | null;
    runs: ReportRunRow[];
    options: PlaceOptions;
    limits: { confirm_rows: number; max_rows: number; expiry_days: number };
    highlight: string | null;
    canSchedule: boolean;
};

type Estimate = { rows: number; step_up: boolean; over_limit: boolean };

const DESCRIPTIONS: Record<ReportTypeValue, string> = {
    sitrep: 'The last 24 hours in your jurisdiction: new incidents, escalations, urgent threats, flashpoints, disseminations received and work awaiting action.',
    weekly: 'A week compared with the week before: key figures, trends, top places, notable incidents, threat outcomes, flashpoint changes and coverage.',
    monthly:
        'A month compared with the previous month and the same month last year.',
    incident_list:
        'A spreadsheet of incidents matching filters chosen on the incident list. No person names, descriptions or evidence.',
    incident: 'One incident in its print layout.',
    lga_profile:
        'One LGA: profile, communities, security presence, current public office holders, flashpoints and recent incidents.',
    coverage:
        'One coverage report with its deployments, uncovered communities and history.',
    analytics: 'An analytics view with its filters, charts and tables.',
};

function yesterday(): string {
    const d = new Date(Date.now() - 86_400_000);

    return d.toISOString().slice(0, 10);
}

function lastMonth(): string {
    const d = new Date();
    d.setDate(1);
    d.setMonth(d.getMonth() - 1);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function size(bytes: number | null): string {
    if (bytes === null) {
        return '';
    }

    return bytes < 1_048_576
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1_048_576).toFixed(1)} MB`;
}

/**
 * Reports and exports (Phase 7D). A report is generated on the server
 * through the requester's own scopes, appears in "My reports" with live
 * status (the requester's private channel carries only the run ULID and
 * status) and can be downloaded by the requester alone. Every download is
 * logged.
 */
export default function ReportsIndex({
    types,
    prefill,
    runs,
    options,
    limits,
    highlight,
    canSchedule,
}: Props) {
    const userUlid = useCurrentUser().ulid;
    const initial =
        types.find((t) => t.value === prefill?.type) ?? types[0] ?? null;
    const [type, setType] = useState<ReportTypeValue | null>(
        initial?.value ?? null,
    );
    const current = types.find((t) => t.value === type) ?? null;
    const [format, setFormat] = useState<ReportFormatValue>(
        initial?.formats[0] ?? 'pdf',
    );
    const [parameters, setParameters] = useState<ReportParameters>(
        prefill?.parameters ?? {},
    );
    const [fetchedEstimate, setEstimate] = useState<Estimate | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const record = prefill?.type === type ? (prefill.record ?? null) : null;
    const filterCount = Object.keys(
        (parameters.filters as Record<string, unknown> | undefined) ?? {},
    ).length;

    useEcho<{ run: string; status: string }>(
        `users.${userUlid}`,
        '.report.updated',
        () => router.reload({ only: ['runs'] }),
        [],
    );

    const choose = (value: ReportTypeValue) => {
        const next = types.find((t) => t.value === value);
        setType(value);
        setFormat(next?.formats[0] ?? 'pdf');
        setParameters(prefill?.type === value ? prefill.parameters : {});
        setErrors({});
    };

    const set = (values: ReportParameters) =>
        setParameters((p) => ({ ...p, ...values }));

    // Incident list exports: the row count before generating.
    const filtersKey = JSON.stringify(parameters.filters ?? {});
    useEffect(() => {
        if (type !== 'incident_list') {
            return;
        }

        let cancelled = false;
        postJson<Estimate>(ReportController.estimate.url(), {
            parameters: { filters: JSON.parse(filtersKey) },
        })
            .then((e) => !cancelled && setEstimate(e))
            .catch(() => !cancelled && setEstimate(null));

        return () => {
            cancelled = true;
        };
    }, [type, filtersKey]);

    const estimate = type === 'incident_list' ? fetchedEstimate : null;

    const needsRecord =
        (type === 'incident' || type === 'coverage') && record === null;
    const needsLga =
        type === 'lga_profile' && !(parameters.lga as string | undefined);
    const blocked =
        needsRecord ||
        needsLga ||
        (type === 'incident_list' && estimate?.over_limit === true);

    const generate = () => {
        if (!type) {
            return;
        }

        setBusy(true);
        router.post(
            ReportController.store.url(),
            { type, format, parameters } as never,
            {
                preserveScroll: true,
                onError: (e) => setErrors(e),
                onSuccess: () => setErrors({}),
                onFinish: () => setBusy(false),
            },
        );
    };

    const placeState = (parameters.state as string | undefined) ?? '';
    const placeLga = (parameters.lga as string | undefined) ?? '';
    const allErrors = useMemo(() => Object.values(errors), [errors]);

    return (
        <>
            <Head title="Reports" />
            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{ topic: 'guides/reports-and-exports' }}
                        title="Reports"
                        description={`Generated through your own access, marked with their classification and your name, and kept for ${limits.expiry_days} days. Only you can download your reports; every download is logged.`}
                    />
                    {canSchedule && (
                        <Button variant="outline" asChild>
                            <Link href={ReportScheduleController.index()}>
                                Scheduled reports
                            </Link>
                        </Button>
                    )}
                </div>

                {types.length === 0 ? (
                    <EmptyState title="No reports are available to you." />
                ) : (
                    <section
                        className="grid gap-6 rounded-lg border p-4 lg:grid-cols-[16rem_1fr]"
                        data-test="report-form"
                    >
                        <div
                            className="flex flex-col gap-1"
                            role="radiogroup"
                            aria-label="Report type"
                        >
                            {types.map((t) => (
                                <button
                                    key={t.value}
                                    type="button"
                                    role="radio"
                                    aria-checked={type === t.value}
                                    onClick={() => choose(t.value)}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-left text-sm hover:bg-muted',
                                        type === t.value &&
                                            'bg-muted font-medium',
                                    )}
                                >
                                    {t.label}
                                </button>
                            ))}
                        </div>

                        {current && (
                            <div className="flex flex-col gap-4">
                                <div>
                                    <h3 className="font-medium">
                                        {current.label}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {DESCRIPTIONS[current.value]}
                                    </p>
                                </div>

                                {(type === 'sitrep' ||
                                    type === 'weekly' ||
                                    type === 'monthly') && (
                                    <PlaceFields
                                        options={options}
                                        state={placeState}
                                        lga={placeLga}
                                        onChange={(p) => set(p)}
                                    />
                                )}

                                {type === 'weekly' && (
                                    <div className="grid max-w-xs gap-2">
                                        <Label htmlFor="report-end">
                                            Week ending
                                        </Label>
                                        <Input
                                            id="report-end"
                                            type="date"
                                            max={yesterday()}
                                            value={
                                                (parameters.end as
                                                    string | undefined) ??
                                                yesterday()
                                            }
                                            onChange={(e) =>
                                                set({ end: e.target.value })
                                            }
                                        />
                                    </div>
                                )}

                                {type === 'monthly' && (
                                    <div className="grid max-w-xs gap-2">
                                        <Label htmlFor="report-month">
                                            Month
                                        </Label>
                                        <Input
                                            id="report-month"
                                            type="month"
                                            value={
                                                (parameters.month as
                                                    string | undefined) ??
                                                lastMonth()
                                            }
                                            onChange={(e) =>
                                                set({ month: e.target.value })
                                            }
                                        />
                                    </div>
                                )}

                                {type === 'lga_profile' && (
                                    <PlaceFields
                                        options={options}
                                        state={placeState}
                                        lga={placeLga}
                                        onChange={(p) => set(p)}
                                        lgaOnly
                                    />
                                )}

                                {(type === 'incident' || type === 'coverage') &&
                                    (record ? (
                                        <p className="text-sm">
                                            <span className="text-muted-foreground">
                                                Record:
                                            </span>{' '}
                                            {record.label}
                                        </p>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            Open the{' '}
                                            {type === 'incident'
                                                ? 'incident'
                                                : 'coverage report'}{' '}
                                            and choose <em>Export PDF</em>.
                                        </p>
                                    ))}

                                {type === 'analytics' && (
                                    <div className="grid gap-2 sm:max-w-sm">
                                        <Label htmlFor="report-tab">
                                            Analytics area
                                        </Label>
                                        <NativeSelect
                                            id="report-tab"
                                            value={
                                                (parameters.tab as
                                                    string | undefined) ?? ''
                                            }
                                            onChange={(e) =>
                                                set({ tab: e.target.value })
                                            }
                                        >
                                            {(prefill?.tabs ?? []).map((t) => (
                                                <option
                                                    key={t.value}
                                                    value={t.value}
                                                >
                                                    {t.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <p className="text-sm text-muted-foreground">
                                            {record
                                                ? `Filters from analytics: ${record.label.split(' · ').slice(1).join(' · ')}.`
                                                : 'Last 30 days. Use “Export PDF” on the analytics page to keep its filters.'}
                                        </p>
                                    </div>
                                )}

                                {type === 'incident_list' && (
                                    <div
                                        className="flex flex-col gap-2 text-sm"
                                        data-test="incident-list-estimate"
                                    >
                                        <p>
                                            {filterCount === 0
                                                ? 'All incidents in your jurisdiction (drafts and invalid reports excluded).'
                                                : `${filterCount} filter(s) from the incident list.`}{' '}
                                            <span className="text-muted-foreground">
                                                To change them, filter the
                                                incident list and choose{' '}
                                                <em>Export</em>.
                                            </span>
                                        </p>
                                        {estimate && (
                                            <p
                                                className={cn(
                                                    estimate.over_limit &&
                                                        'text-destructive',
                                                )}
                                            >
                                                {estimate.rows.toLocaleString(
                                                    'en-NG',
                                                )}{' '}
                                                rows
                                                {estimate.over_limit &&
                                                    ` — over the limit of ${limits.max_rows.toLocaleString('en-NG')}. Narrow the filters.`}
                                            </p>
                                        )}
                                        {estimate?.step_up &&
                                            !estimate.over_limit && (
                                                <p
                                                    className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3"
                                                    data-test="step-up-notice"
                                                >
                                                    This export has{' '}
                                                    {estimate.rows.toLocaleString(
                                                        'en-NG',
                                                    )}{' '}
                                                    rows. You will be asked for
                                                    the code from your
                                                    authenticator app, and the
                                                    export is logged in your
                                                    name.
                                                </p>
                                            )}
                                    </div>
                                )}

                                {current.formats.length > 1 && (
                                    <div className="grid max-w-xs gap-2">
                                        <Label htmlFor="report-format">
                                            Format
                                        </Label>
                                        <NativeSelect
                                            id="report-format"
                                            value={format}
                                            onChange={(e) =>
                                                setFormat(
                                                    e.target
                                                        .value as ReportFormatValue,
                                                )
                                            }
                                        >
                                            {current.formats.map((f) => (
                                                <option key={f} value={f}>
                                                    {f.toUpperCase()}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </div>
                                )}

                                {allErrors.map((message) => (
                                    <InputError
                                        key={message}
                                        message={message}
                                    />
                                ))}

                                <div>
                                    <Button
                                        onClick={generate}
                                        disabled={busy || blocked}
                                        data-test="generate-report"
                                    >
                                        {busy ? 'Requesting…' : 'Generate'}
                                    </Button>
                                </div>
                            </div>
                        )}
                    </section>
                )}

                <section className="flex flex-col gap-3">
                    <h3 className="font-medium">My reports</h3>
                    {runs.length === 0 ? (
                        <EmptyState title="You have not generated any reports yet." />
                    ) : (
                        <div className="overflow-x-auto rounded-lg border">
                            <table
                                className="w-full text-sm"
                                data-test="my-reports"
                            >
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-3 py-2">Report</th>
                                        <th className="px-3 py-2">Requested</th>
                                        <th className="px-3 py-2">Status</th>
                                        <th className="px-3 py-2">
                                            Classification
                                        </th>
                                        <th className="px-3 py-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => (
                                        <tr
                                            key={run.ulid}
                                            className={cn(
                                                'border-t align-top',
                                                highlight === run.ulid &&
                                                    'bg-primary/5',
                                            )}
                                            data-run={run.ulid}
                                        >
                                            <td className="px-3 py-2">
                                                <div className="font-medium">
                                                    {run.type.label}{' '}
                                                    <span className="text-xs text-muted-foreground uppercase">
                                                        {run.format}
                                                    </span>
                                                    {run.scheduled && (
                                                        <Badge
                                                            variant="outline"
                                                            className="ml-2"
                                                        >
                                                            Scheduled
                                                        </Badge>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {run.summary}
                                                </div>
                                                <div className="font-mono text-xs text-muted-foreground">
                                                    {run.reference}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {formatDateTime(run.created_at)}
                                            </td>
                                            <td className="px-3 py-2">
                                                <Badge
                                                    variant={
                                                        run.status.value ===
                                                        'failed'
                                                            ? 'destructive'
                                                            : run.status
                                                                    .value ===
                                                                'completed'
                                                              ? 'default'
                                                              : 'secondary'
                                                    }
                                                    data-test="run-status"
                                                >
                                                    {run.status.label}
                                                </Badge>
                                                {run.error && (
                                                    <p className="mt-1 max-w-xs text-xs text-destructive">
                                                        {run.error}
                                                    </p>
                                                )}
                                                {run.status.value ===
                                                    'completed' && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {[
                                                            run.page_count !==
                                                                null &&
                                                                `${run.page_count} pages`,
                                                            run.row_count !==
                                                                null &&
                                                                `${run.row_count.toLocaleString('en-NG')} rows`,
                                                            size(
                                                                run.size_bytes,
                                                            ),
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                        <br />
                                                        Expires{' '}
                                                        {formatDateTime(
                                                            run.expires_at,
                                                        )}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="px-3 py-2">
                                                {run.classification ?? '—'}
                                            </td>
                                            <td className="px-3 py-2 text-right">
                                                {run.downloadable && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <a
                                                            href={ReportController.download.url(
                                                                run.ulid,
                                                            )}
                                                            data-test="download-report"
                                                        >
                                                            <Download className="size-4" />
                                                            Download
                                                        </a>
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: ReportController.index() }],
};
