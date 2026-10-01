import type { MapCircle } from '@/components/map/BuffaloMap';
import type { ConcernLevel, FlashpointRow } from '@/types/flashpoints';

/** One colour per concern level (map areas, badges and legend). */
export const CONCERN_COLOURS: Record<ConcernLevel, string> = {
    low: '#16a34a',
    moderate: '#ca8a04',
    high: '#ea580c',
    severe: '#dc2626',
};

/** Flashpoints as map circles, coloured by concern level. */
export function flashpointCircles(
    rows: FlashpointRow[],
    href: (ulid: string) => string,
): MapCircle[] {
    return rows.map((f) => ({
        id: f.ulid,
        center: [f.latitude, f.longitude],
        radius: f.radius_meters,
        color: CONCERN_COLOURS[f.concern_level.value],
        title: f.name,
        popup: `${f.kind.label} · ${f.threat_type.name} · ${f.concern_level.label} concern · ${f.status.label}`,
        href: href(f.ulid),
        dashed: f.status.value === 'dormant' || f.status.value === 'resolved',
    }));
}

/** "1.5 km" or "800 m". */
export function formatRadius(metres: number): string {
    return metres >= 1000
        ? `${Number((metres / 1000).toFixed(1)).toLocaleString('en-NG')} km`
        : `${metres} m`;
}
