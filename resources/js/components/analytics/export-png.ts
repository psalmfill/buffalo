import AnalyticsController from '@/actions/App/Http/Controllers/Analytics/AnalyticsController';
import type { AnalyticsFilterValues } from '@/types/analytics';

/** The CSRF token Laravel sets as the XSRF-TOKEN cookie. */
function xsrf(): string {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.split('=')[1]) : '';
}

/**
 * "Download PNG" (Phase 7C): the server logs the export and returns the
 * markings; the chart canvas is drawn onto a larger canvas with the
 * classification above and below, the title and period, and "Generated
 * for [name] at [time]". Nothing leaves the browser except the log call.
 */
export async function downloadChartPng(options: {
    canvas: HTMLCanvasElement;
    area: string;
    chart: string;
    title: string;
    filters: AnalyticsFilterValues;
}): Promise<void> {
    const response = await fetch(AnalyticsController.png.url(), {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify({
            area: options.area,
            chart: options.chart,
            filters: options.filters,
        }),
    });

    if (!response.ok) {
        throw new Error(
            'The export could not be logged, so it was not downloaded.',
        );
    }

    const marks = (await response.json()) as {
        classification: string;
        generated: string;
        period: string;
    };
    const source = options.canvas;
    const scale = window.devicePixelRatio || 1;
    const pad = 24 * scale;
    const header = 72 * scale;
    const footer = 56 * scale;
    const out = document.createElement('canvas');
    out.width = source.width + pad * 2;
    out.height = source.height + header + footer;
    const ctx = out.getContext('2d');

    if (!ctx) {
        return;
    }

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, out.width, out.height);
    ctx.textAlign = 'center';
    ctx.fillStyle = '#b91c1c';
    ctx.font = `bold ${14 * scale}px sans-serif`;
    ctx.fillText(marks.classification, out.width / 2, 20 * scale);
    ctx.fillStyle = '#0f172a';
    ctx.font = `bold ${15 * scale}px sans-serif`;
    ctx.fillText(options.title, out.width / 2, 42 * scale);
    ctx.fillStyle = '#475569';
    ctx.font = `${12 * scale}px sans-serif`;
    ctx.fillText(marks.period, out.width / 2, 60 * scale);
    ctx.drawImage(source, pad, header);
    ctx.fillText(
        marks.generated,
        out.width / 2,
        header + source.height + 22 * scale,
    );
    ctx.fillStyle = '#b91c1c';
    ctx.font = `bold ${14 * scale}px sans-serif`;
    ctx.fillText(
        marks.classification,
        out.width / 2,
        header + source.height + 44 * scale,
    );

    const link = document.createElement('a');
    link.href = out.toDataURL('image/png');
    link.download = `buffalo-analytics-${options.area}-${options.chart}.png`;
    link.click();
}
