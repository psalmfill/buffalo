import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import type { ChartDataset } from 'chart.js';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';
import { useChartPalette } from '@/hooks/use-chart-palette';
import type { AnalyticsChartData } from '@/types/analytics';

// Only what the analytics charts need (Chart.js is tree-shakeable; MIT).
Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend,
);

/** The comparison series is grey and dashed (not part of the palette). */
const COMPARISON = '#94a3b8';

export type ChartHandle = { canvas: () => HTMLCanvasElement | null };

/**
 * Line, bar or stacked bar chart for analytics (Phase 7C), drawn on canvas
 * (nothing loaded from the network). A comparison series is drawn grey and
 * dashed. The canvas is exposed for "Download PNG".
 */
const AnalyticsChart = forwardRef<
    ChartHandle,
    {
        chart: AnalyticsChartData;
        height?: number;
        /** A palette to preview instead of the configured one (Theme page). */
        palette?: string[];
    }
>(function AnalyticsChart({ chart, height = 260, palette: preview }, ref) {
    const canvas = useRef<HTMLCanvasElement>(null);
    // The configured palette (Phase 11A.3), from the theme's CSS variables.
    const configured = useChartPalette();
    const palette = preview ?? configured;
    useImperativeHandle(ref, () => ({ canvas: () => canvas.current }));

    useEffect(() => {
        if (!canvas.current) {
            return;
        }

        const text =
            getComputedStyle(document.documentElement).getPropertyValue(
                'color',
            ) || '#475569';
        const line = chart.kind === 'line';
        const stacked = chart.kind === 'stacked';
        let colour = 0;
        const datasets = chart.series.map(
            (s): ChartDataset<'bar' | 'line', (number | null)[]> => {
                const c = s.comparison
                    ? COMPARISON
                    : palette[colour++ % palette.length];

                return line
                    ? {
                          type: 'line',
                          label: s.label,
                          data: s.data,
                          borderColor: c,
                          backgroundColor: c,
                          borderDash: s.comparison ? [6, 4] : undefined,
                          pointRadius: chart.labels.length > 60 ? 0 : 2,
                          tension: 0.2,
                      }
                    : {
                          type: 'bar',
                          label: s.label,
                          data: s.data,
                          backgroundColor: s.comparison ? COMPARISON : c,
                          borderRadius: 2,
                          maxBarThickness: 36,
                      };
            },
        );

        const instance = new Chart(canvas.current, {
            type: line ? 'line' : 'bar',
            data: { labels: chart.labels, datasets },
            options: {
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: {
                        display: chart.series.length > 1,
                        labels: { color: text, boxWidth: 12 },
                    },
                    tooltip: { enabled: true },
                },
                scales: {
                    x: {
                        stacked,
                        ticks: { color: text, maxRotation: 45, autoSkip: true },
                        grid: { display: false },
                    },
                    y: {
                        stacked,
                        beginAtZero: true,
                        ticks: { color: text, precision: 0 },
                    },
                },
            },
        });

        return () => instance.destroy();
    }, [chart, palette]);

    return (
        <div style={{ height }}>
            <canvas ref={canvas} role="img" aria-label={chart.title} />
        </div>
    );
});

export default AnalyticsChart;
