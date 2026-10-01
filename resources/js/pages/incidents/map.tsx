import { Head, Link } from '@inertiajs/react';
import { List } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import IncidentFilters from '@/components/incidents/incident-filters';
import {
    CATEGORY_SHAPES,
    incidentMarkers,
} from '@/components/incidents/incident-markers';
import { SEVERITY_COLOURS } from '@/components/incidents/incident-table';
import BuffaloMap from '@/components/map/BuffaloMap';
import { Button } from '@/components/ui/button';
import { getJson } from '@/lib/http';
import type { MapView } from '@/types/dashboard';
import type { IncidentListShared, IncidentMapPoint } from '@/types/incidents';

type MapData = {
    points: IncidentMapPoint[];
    total: number;
    capped: boolean;
    cap: number;
    missing_location: number;
};

/**
 * The incident map (Phase 5D): the list's filters (default: the last 30
 * days), clustered markers coloured by severity and shaped by category.
 */
export default function IncidentMap(
    props: IncidentListShared & { map: MapView },
) {
    const { filters, filterOptions, map } = props;
    const [data, setData] = useState<MapData | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let active = true;
        getJson<MapData>(IncidentListController.mapData({ query: filters }).url)
            .then((d) => active && setData(d))
            .catch(() => active && setFailed(true));

        return () => {
            active = false;
        };
    }, [filters]);

    const markers = useMemo(() => incidentMarkers(data?.points ?? []), [data]);

    return (
        <>
            <Head title="Incident map" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Incident map</h1>
                        <p className="text-sm text-muted-foreground">
                            {data === null
                                ? 'Loading…'
                                : `${data.total.toLocaleString('en-NG')} incident${data.total === 1 ? '' : 's'} with a location`}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link
                            href={IncidentListController.index({
                                query: filters,
                            })}
                        >
                            <List /> List
                        </Link>
                    </Button>
                </div>
                <IncidentFilters
                    shared={props}
                    url={IncidentListController.map().url}
                    showSort={false}
                />
                {failed && (
                    <p className="text-sm text-destructive">
                        The map data could not be loaded.
                    </p>
                )}
                {data?.capped && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm dark:bg-amber-950"
                    >
                        Showing the newest {data.cap.toLocaleString('en-NG')} of{' '}
                        {data.total.toLocaleString('en-NG')} incidents. Narrow
                        the filters to see them all.
                    </p>
                )}
                {data !== null && data.missing_location > 0 && (
                    <p role="status" className="text-sm text-muted-foreground">
                        {data.missing_location.toLocaleString('en-NG')} incident
                        {data.missing_location === 1 ? '' : 's'} in this view{' '}
                        {data.missing_location === 1 ? 'has' : 'have'} no map
                        location.{' '}
                        <Link
                            href={IncidentListController.index({
                                query: { ...filters, no_location: '1' },
                            })}
                            className="underline"
                        >
                            See them in the list
                        </Link>
                        .
                    </p>
                )}
                <BuffaloMap
                    className="h-[65vh] rounded-lg border"
                    center={map.center ?? undefined}
                    zoom={map.zoom}
                    markers={markers}
                    cluster
                />
                <Legend categories={filterOptions.categories} />
            </div>
        </>
    );
}

function Legend({
    categories,
}: {
    categories: { value: string; label: string }[];
}) {
    return (
        <div
            className="flex flex-wrap gap-x-6 gap-y-2 text-xs text-muted-foreground"
            aria-label="Legend"
        >
            <span className="flex flex-wrap items-center gap-3">
                {Object.entries(SEVERITY_COLOURS).map(([severity, colour]) => (
                    <span
                        key={severity}
                        className="inline-flex items-center gap-1"
                    >
                        <span
                            className="inline-block size-2.5 rounded-full"
                            style={{ background: colour }}
                        />
                        {severity}
                    </span>
                ))}
            </span>
            <span>
                Shape by category:{' '}
                {categories
                    .map(
                        (c) =>
                            `${c.label} (${CATEGORY_SHAPES[c.value] ?? 'circle'})`,
                    )
                    .join(', ')}
            </span>
        </div>
    );
}

IncidentMap.layout = {
    breadcrumbs: [
        { title: 'Incidents', href: IncidentListController.index() },
        { title: 'Map', href: IncidentListController.map() },
    ],
};
