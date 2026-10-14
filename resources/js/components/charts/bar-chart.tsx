import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    LinearScale,
    Tooltip,
} from 'chart.js';
import { useEffect, useRef } from 'react';
import { useChartPalette } from '@/hooks/use-chart-palette';

// Only what bar charts need (Chart.js is tree-shakeable; MIT licence).
Chart.register(BarController, BarElement, CategoryScale, LinearScale, Tooltip);

/**
 * A simple bar chart (Chart.js, drawn on canvas; nothing is loaded from
 * the network). Rows are { label, count }. An accessible table of the same
 * figures is rendered for screen readers.
 */
export default function BarChart({
    rows,
    label,
    horizontal = false,
    height = 220,
    paletteIndex = 0,
    palette: preview,
}: {
    rows: { label: string; count: number }[];
    label: string;
    horizontal?: boolean;
    height?: number;
    /** Which colour of the configured palette (Phase 11A.3); default the first. */
    paletteIndex?: number;
    /** A palette to preview instead of the configured one (Theme page). */
    palette?: string[];
}) {
    const canvas = useRef<HTMLCanvasElement>(null);
    const configured = useChartPalette();
    const palette = preview ?? configured;
    const fill =
        palette[paletteIndex % Math.max(palette.length, 1)] ?? '#2563eb';

    useEffect(() => {
        if (!canvas.current) {
            return;
        }

        const text =
            getComputedStyle(document.documentElement).getPropertyValue(
                'color',
            ) || '#475569';
        const chart = new Chart(canvas.current, {
            type: 'bar',
            data: {
                labels: rows.map((r) => r.label),
                datasets: [
                    {
                        label,
                        data: rows.map((r) => r.count),
                        backgroundColor: fill,
                        borderRadius: 3,
                        maxBarThickness: 32,
                    },
                ],
            },
            options: {
                indexAxis: horizontal ? 'y' : 'x',
                maintainAspectRatio: false,
                animation: false,
                plugins: { tooltip: { enabled: true } },
                scales: {
                    x: {
                        ticks: { color: text, precision: 0 },
                        grid: { display: horizontal },
                    },
                    y: {
                        ticks: { color: text, precision: 0 },
                        grid: { display: !horizontal },
                    },
                },
            },
        });

        return () => chart.destroy();
    }, [rows, label, horizontal, fill]);

    if (rows.length === 0) {
        return (
            <p className="px-4 py-6 text-sm text-muted-foreground">
                No incidents in this period.
            </p>
        );
    }

    return (
        <figure className="px-4 py-3">
            <div style={{ height }}>
                <canvas ref={canvas} role="img" aria-label={label} />
            </div>
            <table className="sr-only">
                <caption>{label}</caption>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.label}>
                            <th scope="row">{r.label}</th>
                            <td>{r.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </figure>
    );
}
