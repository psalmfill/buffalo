import { Link } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { GeoLayer, MapViewChange } from '@/components/map/BuffaloMap';
import {
    fetchLayer,
    filterParams,
    operationalMapUrl,
    toGeoLayer,
} from '@/components/map/operational-layers';
import type {
    IncidentLayerFilters,
    LayerKey,
    LayerResponse,
} from '@/components/map/operational-layers';
import type { MapView } from '@/types/dashboard';

type Mode = 'lga' | 'state' | 'national';

/** What each dashboard shows (Phase 6C): aggregated layers only, for the dashboard period (Phase 7C). */
const SETUP: Record<Mode, { area: LayerKey; urgencies: string[] }> = {
    lga: { area: 'density', urgencies: [] },
    state: { area: 'lga_choropleth', urgencies: ['immediate', 'priority'] },
    national: { area: 'state_choropleth', urgencies: ['immediate'] },
};

function lastDays(days: number): IncidentLayerFilters {
    const to = new Date();
    const from = new Date(to.getTime() - days * 86_400_000);

    return {
        from: from.toISOString().slice(0, 10),
        to: to.toISOString().slice(0, 10),
        types: [],
        category: '',
        severities: [],
        statuses: [],
    };
}

/**
 * The dashboard map (Phase 6C): server-aggregated incidents for the last
 * 30 days (density for an LGA; LGA or state choropleth, or density without
 * boundaries), plus flashpoints and the relevant active threats. No raw
 * incident points. Links to the operational map with the same view.
 */
export default function DashboardMap({
    view,
    mode,
    days = 30,
}: {
    view: MapView;
    mode: Mode;
    /** The dashboard period (7, 30 or 90 days). */
    days?: number;
}) {
    const setup = SETUP[mode];
    const filters = useMemo(() => lastDays(days), [days]);
    const [current, setCurrent] = useState<MapViewChange | null>(null);
    const [area, setArea] = useState<LayerKey>(setup.area);
    const [data, setData] = useState<Partial<Record<LayerKey, LayerResponse>>>(
        {},
    );
    const onViewChange = useCallback((v: MapViewChange) => setCurrent(v), []);
    const keys = useMemo<LayerKey[]>(
        () => [
            area,
            ...(mode === 'national' ? [] : (['flashpoints'] as LayerKey[])),
            'threats',
        ],
        [area, mode],
    );

    useEffect(() => {
        if (!current) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            const next: Partial<Record<LayerKey, LayerResponse>> = {};

            await Promise.all(
                keys.map(async (key) => {
                    const params = filterParams(filters);
                    if (key === 'threats')
                        setup.urgencies.forEach((u) =>
                            params.append('urgencies[]', u),
                        );
                    const result = await fetchLayer(
                        key,
                        current,
                        params,
                        controller.signal,
                    ).catch(() => null);
                    if (result) next[key] = result;
                }),
            );

            // Without boundaries, fall back to the density layer.
            if (
                area !== 'density' &&
                next[area]?.meta?.mode === 'unavailable'
            ) {
                setArea('density');

                return;
            }

            setData(next);
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [current, keys, filters, area, setup.urgencies]);

    const geoLayers = useMemo<GeoLayer[]>(
        () =>
            keys
                .filter((k) => data[k])
                .map((k) => toGeoLayer(k, data[k] as LayerResponse, filters)),
        [keys, data, filters],
    );

    return (
        <div className="space-y-2">
            <BuffaloMap
                center={view.center ?? undefined}
                zoom={view.zoom}
                outline={view.outline}
                geoLayers={geoLayers}
                onViewChange={onViewChange}
                className="h-[420px] w-full rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
            />
            <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>
                    Incidents in the last {days} days (aggregated), flashpoints
                    and active threats.
                </span>
                <Link
                    href={operationalMapUrl({
                        layers: keys,
                        center: current?.center ?? view.center,
                        zoom: current?.zoom ?? view.zoom,
                        from: filters.from,
                        to: filters.to,
                        urgencies: setup.urgencies,
                    })}
                    className="font-medium underline"
                >
                    Open the operational map
                </Link>
            </div>
        </div>
    );
}
