import type { FeatureCollection } from 'geojson';
import { CONCERN_COLOURS } from '@/components/flashpoints/flashpoint-areas';
import { popupImage, SHARED_COLOUR } from '@/components/map/BuffaloMap';
import type { GeoLayer } from '@/components/map/BuffaloMap';
import { URGENCY_COLOURS } from '@/components/threats/threat-display';

/** Layers of the operational map (Phase 6C), in drawing order. */
export const LAYER_KEYS = [
    'state_choropleth',
    'lga_choropleth',
    'density',
    'suggestions',
    'flashpoints',
    'threats',
    'formations',
    'sites',
    'communities',
    'incidents',
] as const;

export type LayerKey = (typeof LAYER_KEYS)[number];

export const LAYER_LABELS: Record<LayerKey, string> = {
    density: 'Incident density',
    lga_choropleth: 'Incidents by LGA',
    state_choropleth: 'Incidents by state',
    incidents: 'Incidents (points)',
    flashpoints: 'Flashpoints and blackspots',
    threats: 'Threat reports',
    suggestions: 'Suggested blackspots',
    formations: 'Agency formations',
    sites: 'Organization sites',
    communities: 'Communities',
};

/** Layer endpoints (Wayfinder-free: plain paths under /map/layers). */
const ENDPOINTS: Partial<Record<LayerKey, string>> = {
    density: '/map/layers/density',
    lga_choropleth: '/map/layers/lga-choropleth',
    state_choropleth: '/map/layers/state-choropleth',
    flashpoints: '/map/layers/flashpoints',
    threats: '/map/layers/threats',
    suggestions: '/map/layers/suggestions',
    formations: '/map/layers/formations',
    sites: '/map/layers/sites',
    communities: '/map/layers/communities',
};

/**
 * Every counted status (no drafts, no invalid reports): the map layers'
 * default, passed explicitly to the incident list, whose own default is
 * open work only.
 */
export const COUNTED_STATUSES = [
    'submitted',
    'returned',
    'verified',
    'under_investigation',
    'resolved',
    'closed',
];

/** Filters shared by the incident-based layers. */
export type IncidentLayerFilters = {
    from: string;
    to: string;
    types: string[];
    category: string;
    severities: string[];
    statuses: string[];
};

export type LayerResponse = FeatureCollection & {
    meta?: {
        mode?: 'polygons' | 'centroids' | 'unavailable';
        notice?: string | null;
        max?: number;
        capped?: boolean;
        hex_meters?: number;
        measure?: 'count' | 'rate';
    };
};

/** Query parameters for the incident filters (and the incident list). */
export function filterParams(filters: IncidentLayerFilters): URLSearchParams {
    const params = new URLSearchParams();

    if (filters.from) params.set('from', filters.from);
    if (filters.to) params.set('to', filters.to);
    if (filters.category) params.set('category', filters.category);
    filters.types.forEach((t) => params.append('types[]', t));
    filters.severities.forEach((s) => params.append('severities[]', s));
    filters.statuses.forEach((s) => params.append('statuses[]', s));

    return params;
}

/** Fetches one layer for the view; aborts with the signal. */
export async function fetchLayer(
    key: LayerKey,
    view: { bbox: number[]; zoom: number },
    params: URLSearchParams,
    signal: AbortSignal,
): Promise<LayerResponse | null> {
    const url = ENDPOINTS[key];

    if (!url) {
        return null;
    }

    const query = new URLSearchParams(params);
    query.set('bbox', view.bbox.join(','));
    query.set('zoom', String(view.zoom));

    const response = await fetch(`${url}?${query.toString()}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
        signal,
    });

    return response.ok ? ((await response.json()) as LayerResponse) : null;
}

/** Five-step sequential colours (low to high). */
export const SCALE = ['#fef3c7', '#fcd34d', '#f59e0b', '#ea580c', '#b91c1c'];

/** The scale step for a value, log-scaled against the maximum. */
export function step(value: number, max: number): number {
    if (value <= 0 || max <= 0) {
        return 0;
    }

    const ratio = Math.log1p(value) / Math.log1p(max);

    return Math.min(SCALE.length - 1, Math.floor(ratio * SCALE.length));
}

/** Popup content built from text nodes (no HTML from data). */
export function popupElement(
    title: string,
    lines: (string | null | undefined)[],
    link?: { href: string; label: string },
    image?: string | null,
): HTMLElement {
    const root = document.createElement('div');

    if (image) {
        root.appendChild(popupImage(image));
    }

    const heading = document.createElement('strong');
    heading.textContent = title;
    root.appendChild(heading);

    for (const line of lines) {
        if (line) {
            const div = document.createElement('div');
            div.className = 'text-xs';
            div.textContent = line;
            root.appendChild(div);
        }
    }

    if (link) {
        const a = document.createElement('a');
        a.href = link.href;
        a.textContent = link.label;
        a.className = 'text-xs font-medium underline';
        root.appendChild(a);
    }

    return root;
}

const num = (v: unknown) => (typeof v === 'number' ? v : Number(v ?? 0));
const str = (v: unknown) => (typeof v === 'string' ? v : '');

/**
 * A layer response as a drawable GeoLayer. Incident aggregations link to
 * the incident list filtered to the area and period.
 */
export function toGeoLayer(
    key: LayerKey,
    data: LayerResponse,
    filters: IncidentLayerFilters,
): GeoLayer {
    const list = (extra: Record<string, string>) => {
        const params = filterParams(
            filters.statuses.length
                ? filters
                : { ...filters, statuses: COUNTED_STATUSES },
        );
        Object.entries(extra).forEach(([k, v]) => params.set(k, v));

        return `/incidents?${params.toString()}`;
    };
    const max = data.meta?.max ?? 0;

    switch (key) {
        case 'density':
            return {
                id: key,
                data,
                style: (p) => ({
                    color: '#7c2d12',
                    weight: 0.5,
                    fillColor: SCALE[step(num(p.count), max)],
                    fillOpacity: 0.55,
                }),
                tooltip: (p) => `${num(p.count)} incidents`,
                popup: (p) =>
                    popupElement(
                        `${num(p.count)} incidents`,
                        ['In this hexagon, in the period.'],
                        {
                            href: list({ hex: str(p.hex) }),
                            label: 'List them',
                        },
                    ),
            };
        case 'lga_choropleth':
        case 'state_choropleth': {
            const filter = key === 'lga_choropleth' ? 'lga' : 'state';
            const rate = data.meta?.measure === 'rate';

            return {
                id: key,
                data,
                style: (p) => ({
                    color: '#475569',
                    weight: 1,
                    fillColor: SCALE[step(num(p.value), max)],
                    fillOpacity: data.meta?.mode === 'centroids' ? 0.8 : 0.5,
                }),
                pointRadius: (p) =>
                    max > 0 ? 4 + 16 * Math.sqrt(num(p.value) / max) : 4,
                tooltip: (p) =>
                    `${str(p.name)}: ${rate ? `${p.value ?? '—'} per 100,000` : num(p.count)}`,
                popup: (p) =>
                    popupElement(
                        str(p.name),
                        [
                            `${num(p.count)} incidents`,
                            rate
                                ? p.value === null
                                    ? 'No population estimate'
                                    : `${p.value} per 100,000 people`
                                : null,
                        ],
                        {
                            href: list({ [filter]: str(p.ulid) }),
                            label: 'List them',
                        },
                    ),
            };
        }
        case 'flashpoints':
            return {
                id: key,
                data,
                style: (p) => {
                    const colour =
                        CONCERN_COLOURS[
                            str(p.concern) as keyof typeof CONCERN_COLOURS
                        ] ?? '#475569';

                    // Shared with the viewer (Phase 7A): teal outline.
                    return {
                        color: p.shared === true ? SHARED_COLOUR : colour,
                        fillColor: colour,
                        weight: p.shared === true ? 4 : 2,
                        fillOpacity: 0.7,
                    };
                },
                pointRadius: () => 7,
                tooltip: (p) =>
                    p.shared === true
                        ? `${str(p.name)} (shared with you)`
                        : str(p.name),
                popup: (p) =>
                    popupElement(
                        str(p.name),
                        [
                            `${str(p.kind)} · ${str(p.threat_type)}`,
                            `${str(p.concern_label)} concern · radius ${num(p.radius_meters)} m`,
                        ],
                        {
                            href: str(p.href),
                            label: 'Open',
                        },
                    ),
            };
        case 'threats':
            return {
                id: key,
                data,
                style: (p) => {
                    const colour =
                        URGENCY_COLOURS[
                            str(p.urgency) as keyof typeof URGENCY_COLOURS
                        ] ?? '#475569';

                    return {
                        color: p.shared === true ? SHARED_COLOUR : colour,
                        weight: p.shared === true ? 4 : 2,
                        fillColor: colour,
                        fillOpacity: p.area === 'lga' ? 0.08 : 0.7,
                        dashArray: p.area === 'lga' ? '6 4' : undefined,
                    };
                },
                pointRadius: () => 8,
                tooltip: (p) =>
                    `${str(p.reference_no)} — ${str(p.title)}${p.shared === true ? ' (shared with you)' : ''}`,
                popup: (p) =>
                    popupElement(
                        `${str(p.reference_no)} — ${str(p.title)}`,
                        [
                            `${str(p.threat_type)} · ${str(p.urgency_label)} · ${str(p.credibility)}`,
                            p.area === 'lga'
                                ? `Whole of ${str(p.lga)} LGA`
                                : null,
                        ],
                        { href: str(p.href), label: 'Open' },
                    ),
            };
        case 'suggestions':
            return {
                id: key,
                data,
                style: () => ({
                    color: '#7c3aed',
                    weight: 2,
                    fillColor: '#a78bfa',
                    fillOpacity: 0.6,
                    dashArray: '4 3',
                }),
                pointRadius: () => 9,
                tooltip: (p) =>
                    `Suggested blackspot: ${num(p.incident_count)} incidents`,
                popup: (p) =>
                    popupElement(
                        'Suggested blackspot',
                        [
                            `${num(p.incident_count)} incidents · ${str(p.dominant_type)}`,
                            `${str(p.lga)} LGA · radius ${num(p.radius_meters)} m`,
                        ],
                        {
                            href: str(p.href),
                            label: 'Review',
                        },
                    ),
            };
        default: {
            const colour =
                key === 'formations'
                    ? '#1d4ed8'
                    : key === 'sites'
                      ? '#0f766e'
                      : '#64748b';

            return {
                id: key,
                data,
                style: () => ({
                    color: '#ffffff',
                    weight: 1,
                    fillColor: colour,
                    fillOpacity: 0.9,
                }),
                pointRadius: () => 5,
                tooltip: (p) => str(p.name),
                popup: (p) =>
                    popupElement(
                        str(p.name),
                        [
                            str(p.agency) || str(p.organization) || null,
                            str(p.type) || str(p.relevance_label) || null,
                        ],
                        {
                            href: str(p.href),
                            label: 'Open',
                        },
                        // Organization sites: the cover photo (Phase 11D).
                        str(p.image) || null,
                    ),
            };
        }
    }
}

/** A link to the operational map with a given view. */
export function operationalMapUrl(state: {
    layers: LayerKey[];
    center?: [number, number] | null;
    zoom?: number;
    from?: string;
    to?: string;
    urgencies?: string[];
}): string {
    const params = new URLSearchParams();
    params.set('layers', state.layers.join(','));

    if (state.center) {
        params.set('lat', String(state.center[0]));
        params.set('lng', String(state.center[1]));
    }
    if (state.zoom) params.set('z', String(state.zoom));
    if (state.from) params.set('from', state.from);
    if (state.to) params.set('to', state.to);
    if (state.urgencies?.length)
        params.set('urgencies', state.urgencies.join(','));

    return `/map?${params.toString()}`;
}
