import { SEVERITY_COLOURS } from '@/components/incidents/incident-table';
import type { MapMarker, MarkerShape } from '@/components/map/BuffaloMap';
import { formatDateTime } from '@/lib/time';
import type { IncidentMapPoint } from '@/types/incidents';

export const CATEGORY_SHAPES: Record<string, MarkerShape> = {
    crime: 'circle',
    violence_conflict: 'triangle',
    accident: 'square',
    disaster_environmental: 'diamond',
    security_agency: 'ring',
    other: 'circle',
};

/** Map points to markers: popups show the reference, type, time and status only. */
export function incidentMarkers(points: IncidentMapPoint[]): MapMarker[] {
    return points.map((p) => ({
        id: p.ulid,
        position: [p.lat, p.lng],
        title: p.reference_no ?? 'Incident',
        popup: `${p.type} · ${formatDateTime(p.occurred_at)} · ${p.status}`,
        href: `/incidents/${p.ulid}`,
        color: SEVERITY_COLOURS[p.severity] ?? '#64748b',
        shape: CATEGORY_SHAPES[p.category] ?? 'circle',
    }));
}
