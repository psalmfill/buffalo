import { Head, router, useForm } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import ThemeController from '@/actions/App/Http/Controllers/System/ThemeController';
import AnalyticsChart from '@/components/charts/analytics-chart';
import BarChart from '@/components/charts/bar-chart';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { contrastRatio, isHex, suggestColour } from '@/lib/contrast';
import { cn } from '@/lib/utils';
import type { AnalyticsChartData } from '@/types/analytics';
import type { FixedColourGroup, ThemeColours, ThemeMode } from '@/types/theme';

type Props = {
    theme: ThemeColours;
    defaults: ThemeColours;
    customised: boolean;
    backgrounds: Record<ThemeMode, string>;
    minimums: { text: number; graphic: number };
    kpiTypes: string[];
    fixed: FixedColourGroup[];
};

const MODES: ThemeMode[] = ['light', 'dark'];

const KPI_LABELS: Record<string, string> = {
    incidents: 'Incidents',
    casualties: 'Casualties',
    threats: 'Threats',
    verification: 'Verification',
    coverage: 'Coverage',
};

const SAMPLE_LINE: AnalyticsChartData = {
    key: 'sample-line',
    title: 'Incidents per week',
    kind: 'line',
    labels: ['W1', 'W2', 'W3', 'W4', 'W5', 'W6'],
    series: [
        { label: 'Crime', data: [12, 15, 9, 14, 18, 16] },
        { label: 'Communal', data: [5, 7, 6, 4, 8, 9] },
        { label: 'Political', data: [2, 3, 5, 3, 2, 4] },
        {
            label: 'Previous period',
            data: [10, 11, 12, 10, 13, 12],
            comparison: true,
        },
    ],
    columns: [],
    rows: [],
    note: null,
    link: null,
};

const SAMPLE_BARS = [
    { label: 'Kidnapping', count: 14 },
    { label: 'Armed robbery', count: 9 },
    { label: 'Banditry', count: 7 },
    { label: 'Cult clash', count: 4 },
];

/**
 * One colour input with its live contrast check against the mode's card
 * background. The server re-checks on save.
 */
function ColourField({
    id,
    label,
    value,
    background,
    minimum,
    error,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    background: string;
    minimum: number;
    error?: string;
    onChange: (value: string) => void;
}) {
    const valid = isHex(value);
    const ratio = valid ? contrastRatio(value, background) : null;
    const passes = ratio !== null && ratio >= minimum;
    const suggestion =
        valid && !passes ? suggestColour(value, background, minimum) : null;

    return (
        <div className="space-y-1">
            <label htmlFor={id} className="text-xs font-medium">
                {label}
            </label>
            <div className="flex items-center gap-2">
                <input
                    type="color"
                    aria-label={`${label} picker`}
                    value={valid ? value : '#000000'}
                    onChange={(e) => onChange(e.target.value)}
                    className="h-8 w-10 cursor-pointer rounded border bg-background"
                />
                <input
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    className="h-8 w-24 rounded-md border bg-background px-2 font-mono text-xs"
                    maxLength={7}
                />
                {ratio !== null && (
                    <span
                        className={cn(
                            'text-xs tabular-nums',
                            passes
                                ? 'text-muted-foreground'
                                : 'font-medium text-destructive',
                        )}
                        data-test={`ratio-${id}`}
                    >
                        {ratio.toFixed(2)}:1{' '}
                        {passes ? '✓' : `(needs ${minimum}:1)`}
                    </span>
                )}
            </div>
            {suggestion && (
                <button
                    type="button"
                    className="text-xs underline"
                    onClick={() => onChange(suggestion)}
                >
                    Use the nearest passing colour {suggestion}
                </button>
            )}
            <InputError message={error} />
        </div>
    );
}

/** A sample dashboard in one mode, drawn with the colours being edited. */
function Preview({ theme, mode }: { theme: ThemeColours; mode: ThemeMode }) {
    const vars = {
        '--primary': theme.brand.primary[mode],
        '--brand-accent': theme.brand.accent[mode],
        ...Object.fromEntries(
            Object.entries(theme.kpi).map(([type, colours]) => [
                `--kpi-${type}`,
                colours[mode],
            ]),
        ),
    } as CSSProperties;

    return (
        <div
            className={cn(mode === 'dark' ? 'dark' : 'theme-preview-light')}
            data-test={`preview-${mode}`}
        >
            <div
                style={vars}
                className="space-y-3 rounded-xl border bg-background p-3 text-foreground"
            >
                <div className="flex items-center justify-between">
                    <p className="text-sm font-medium capitalize">
                        {mode} mode
                    </p>
                    <span className="rounded-md bg-primary px-2 py-1 text-xs text-primary-foreground">
                        Primary button
                    </span>
                </div>
                <div className="grid grid-cols-2 gap-2 lg:grid-cols-3">
                    {Object.keys(theme.kpi).map((type) => (
                        <div
                            key={type}
                            className="rounded-lg border border-l-4 bg-card p-2"
                            style={{ borderLeftColor: `var(--kpi-${type})` }}
                        >
                            <p className="text-xs text-muted-foreground">
                                {KPI_LABELS[type] ?? type}
                            </p>
                            <p
                                className="text-lg font-semibold"
                                style={{ color: `var(--kpi-${type})` }}
                            >
                                128
                            </p>
                        </div>
                    ))}
                </div>
                <p className="rounded-md border border-brand-accent px-2 py-1 text-xs">
                    Accent: highlights and the lens chip.
                </p>
                <div className="rounded-lg border bg-card p-2">
                    <BarChart
                        rows={SAMPLE_BARS}
                        label="Incidents by type"
                        palette={theme.chart[mode]}
                        height={150}
                    />
                </div>
                <div className="rounded-lg border bg-card p-2">
                    <AnalyticsChart
                        chart={SAMPLE_LINE}
                        palette={theme.chart[mode]}
                        height={170}
                    />
                </div>
            </div>
        </div>
    );
}

/**
 * Theme colours (Phase 11A.3): brand, chart palette and KPI tile colours
 * for light and dark mode, each checked for contrast (WCAG AA) against
 * the card background. Severity, classification and urgency colours keep
 * their fixed meanings and are shown read-only.
 */
export default function ThemePage({
    theme,
    defaults,
    customised,
    backgrounds,
    minimums,
    kpiTypes,
    fixed,
}: Props) {
    const form = useForm<ThemeColours>(theme);
    const errors = form.errors as Record<string, string | undefined>;
    const data = form.data;

    const setBrand = (
        key: 'primary' | 'accent',
        mode: ThemeMode,
        value: string,
    ) =>
        form.setData('brand', {
            ...data.brand,
            [key]: { ...data.brand[key], [mode]: value },
        });
    const setChart = (mode: ThemeMode, index: number, value: string) =>
        form.setData('chart', {
            ...data.chart,
            [mode]: data.chart[mode].map((c, i) => (i === index ? value : c)),
        });
    const setKpi = (type: string, mode: ThemeMode, value: string) =>
        form.setData('kpi', {
            ...data.kpi,
            [type]: { ...data.kpi[type], [mode]: value },
        });

    const save = () =>
        form.put(ThemeController.update.url(), { preserveScroll: true });
    const reset = () =>
        router.delete(ThemeController.destroy.url(), {
            preserveScroll: true,
            onSuccess: () => form.setData(defaults),
        });

    return (
        <>
            <Head title="Theme" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Theme"
                    description="Brand, chart and key-figure colours for light and dark mode. Every colour must stay readable: 4.5:1 for text and 3:1 for chart elements and borders against the card background (WCAG AA)."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        save();
                    }}
                    className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                >
                    <div className="space-y-6">
                        {MODES.map((mode) => (
                            <section
                                key={mode}
                                className="space-y-4 rounded-xl border p-4"
                                aria-labelledby={`mode-${mode}`}
                            >
                                <h2
                                    id={`mode-${mode}`}
                                    className="text-sm font-medium capitalize"
                                >
                                    {mode} mode (card background{' '}
                                    {backgrounds[mode]})
                                </h2>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <ColourField
                                        id={`brand.primary.${mode}`}
                                        label="Primary (text and buttons)"
                                        value={data.brand.primary[mode]}
                                        background={backgrounds[mode]}
                                        minimum={minimums.text}
                                        error={errors[`brand.primary.${mode}`]}
                                        onChange={(v) =>
                                            setBrand('primary', mode, v)
                                        }
                                    />
                                    <ColourField
                                        id={`brand.accent.${mode}`}
                                        label="Accent (highlights and borders)"
                                        value={data.brand.accent[mode]}
                                        background={backgrounds[mode]}
                                        minimum={minimums.graphic}
                                        error={errors[`brand.accent.${mode}`]}
                                        onChange={(v) =>
                                            setBrand('accent', mode, v)
                                        }
                                    />
                                </div>
                                <h3 className="text-xs font-medium text-muted-foreground">
                                    Chart palette (used in this order)
                                </h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {data.chart[mode].map((colour, i) => (
                                        <ColourField
                                            key={i}
                                            id={`chart.${mode}.${i}`}
                                            label={`Chart colour ${i + 1}`}
                                            value={colour}
                                            background={backgrounds[mode]}
                                            minimum={minimums.graphic}
                                            error={errors[`chart.${mode}.${i}`]}
                                            onChange={(v) =>
                                                setChart(mode, i, v)
                                            }
                                        />
                                    ))}
                                </div>
                                <h3 className="text-xs font-medium text-muted-foreground">
                                    Key-figure tiles
                                </h3>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {kpiTypes.map((type) => (
                                        <ColourField
                                            key={type}
                                            id={`kpi.${type}.${mode}`}
                                            label={KPI_LABELS[type] ?? type}
                                            value={data.kpi[type][mode]}
                                            background={backgrounds[mode]}
                                            minimum={minimums.text}
                                            error={
                                                errors[`kpi.${type}.${mode}`]
                                            }
                                            onChange={(v) =>
                                                setKpi(type, mode, v)
                                            }
                                        />
                                    ))}
                                </div>
                            </section>
                        ))}

                        <div className="flex flex-wrap gap-2">
                            <Button type="submit" disabled={form.processing}>
                                Save colours
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => form.setData(theme)}
                                disabled={!form.isDirty}
                            >
                                Discard changes
                            </Button>
                            {customised && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={reset}
                                >
                                    Reset to defaults
                                </Button>
                            )}
                        </div>

                        <section className="space-y-3 rounded-xl border p-4">
                            <h2 className="text-sm font-medium">
                                Fixed colours (not configurable)
                            </h2>
                            <p className="text-xs text-muted-foreground">
                                Severity, classification and urgency colours
                                carry the same meaning everywhere in BUFFALO and
                                in printed reports, so they cannot be changed.
                            </p>
                            {fixed.map((group) => (
                                <div key={group.group}>
                                    <p className="text-xs font-medium">
                                        {group.group}
                                    </p>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        {group.colours.map((c) => (
                                            <span
                                                key={c.name}
                                                className="inline-flex items-center gap-1 text-xs"
                                            >
                                                <span
                                                    aria-hidden
                                                    className="inline-block size-3 rounded-sm border"
                                                    style={{
                                                        backgroundColor:
                                                            c.colour,
                                                    }}
                                                />
                                                {c.name}{' '}
                                                <span className="font-mono text-muted-foreground">
                                                    {c.colour}
                                                </span>
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </section>
                    </div>

                    <div className="space-y-4" aria-label="Live preview">
                        <h2 className="text-sm font-medium">Live preview</h2>
                        {MODES.map((mode) => (
                            <Preview key={mode} theme={data} mode={mode} />
                        ))}
                    </div>
                </form>
            </div>
        </>
    );
}
