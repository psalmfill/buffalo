import { Head } from '@inertiajs/react';
import { Pause, Play } from 'lucide-react';
import {
    Fragment,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { incidentMarkers } from '@/components/incidents/incident-markers';
import BuffaloMap, { SHARED_COLOUR } from '@/components/map/BuffaloMap';
import type { GeoLayer, MapViewChange } from '@/components/map/BuffaloMap';
import {
    LAYER_KEYS,
    LAYER_LABELS,
    SCALE,
    fetchLayer,
    filterParams,
    toGeoLayer,
} from '@/components/map/operational-layers';
import type {
    IncidentLayerFilters,
    LayerKey,
    LayerResponse,
} from '@/components/map/operational-layers';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/time';
import type { MapView } from '@/types/dashboard';
import type { IncidentMapPoint } from '@/types/incidents';
import type { Option } from '@/types/local-government';

type Props = {
    view: MapView;
    national: boolean;
    layers: LayerKey[];
    boundaries: {
        lga: 'polygons' | 'centroids' | 'none';
        state: 'polygons' | 'centroids' | 'none';
    };
    filterOptions: {
        types: (Option & { category: string })[];
        categories: Option[];
        severities: Option[];
        statuses: Option[];
    };
    defaults: { from: string; to: string };
};

const AGGREGATED: LayerKey[] = [
    'density',
    'lga_choropleth',
    'state_choropleth',
];
const URGENCIES = ['immediate', 'priority', 'routine'];

/** The state kept in the URL (shared views). */
type UrlState = {
    layers: LayerKey[];
    filters: IncidentLayerFilters;
    measure: 'count' | 'rate';
    urgencies: string[];
    week: number | null;
    center: [number, number] | null;
    zoom: number | null;
};

function readUrl(props: Props): UrlState {
    const q = new URLSearchParams(window.location.search);
    const list = (key: string) =>
        (q.get(key) ?? '').split(',').filter((v) => v !== '');
    const layers = list('layers').filter((l): l is LayerKey =>
        props.layers.includes(l as LayerKey),
    );
    const lat = Number(q.get('lat'));
    const lng = Number(q.get('lng'));
    const week = q.get('week');

    return {
        layers:
            layers.length > 0 || q.has('layers')
                ? layers
                : (['density', 'flashpoints', 'threats'] as LayerKey[]).filter(
                      (l) => props.layers.includes(l),
                  ),
        filters: {
            from: q.get('from') ?? props.defaults.from,
            to: q.get('to') ?? props.defaults.to,
            types: list('types'),
            category: q.get('category') ?? '',
            severities: list('severities'),
            statuses: list('statuses'),
        },
        measure: q.get('measure') === 'rate' ? 'rate' : 'count',
        urgencies: list('urgencies'),
        week: week === null ? null : Number(week),
        center: q.has('lat') && q.has('lng') ? [lat, lng] : null,
        zoom: q.has('z') ? Number(q.get('z')) : null,
    };
}

function writeUrl(state: UrlState) {
    const q = new URLSearchParams();
    q.set('layers', state.layers.join(','));
    q.set('from', state.filters.from);
    q.set('to', state.filters.to);
    if (state.filters.category) q.set('category', state.filters.category);
    if (state.filters.types.length)
        q.set('types', state.filters.types.join(','));
    if (state.filters.severities.length)
        q.set('severities', state.filters.severities.join(','));
    if (state.filters.statuses.length)
        q.set('statuses', state.filters.statuses.join(','));
    if (state.measure === 'rate') q.set('measure', 'rate');
    if (state.urgencies.length) q.set('urgencies', state.urgencies.join(','));
    if (state.week !== null) q.set('week', String(state.week));
    if (state.center) {
        q.set('lat', String(state.center[0]));
        q.set('lng', String(state.center[1]));
    }
    if (state.zoom) q.set('z', String(state.zoom));

    window.history.replaceState(
        window.history.state,
        '',
        `/map?${q.toString()}`,
    );
}

/** Weeks of the period, as [from, to] date strings. */
function weeksOf(from: string, to: string): [string, string][] {
    const start = new Date(`${from}T00:00:00Z`);
    const end = new Date(`${to}T00:00:00Z`);
    const weeks: [string, string][] = [];

    for (let d = start; d <= end; d = new Date(d.getTime() + 7 * 86_400_000)) {
        const last = new Date(
            Math.min(d.getTime() + 6 * 86_400_000, end.getTime()),
        );
        weeks.push([
            d.toISOString().slice(0, 10),
            last.toISOString().slice(0, 10),
        ]);
    }

    return weeks;
}

/**
 * The operational map (Phase 6C): every layer the user may see, aggregated
 * on the server through their own scopes. Layer, filter and view state is
 * kept in the URL.
 */
export default function OperationalMap(props: Props) {
    const [initial] = useState(() => readUrl(props));
    const [layers, setLayers] = useState<LayerKey[]>(initial.layers);
    const [filters, setFilters] = useState<IncidentLayerFilters>(
        initial.filters,
    );
    const [measure, setMeasure] = useState(initial.measure);
    const [urgencies, setUrgencies] = useState<string[]>(initial.urgencies);
    const [week, setWeek] = useState<number | null>(initial.week);
    const [playing, setPlaying] = useState(false);
    const [view, setView] = useState<MapViewChange | null>(null);
    const [data, setData] = useState<Partial<Record<LayerKey, LayerResponse>>>(
        {},
    );
    const [points, setPoints] = useState<IncidentMapPoint[]>([]);
    const [loading, setLoading] = useState(false);
    const abort = useRef<AbortController | null>(null);

    const weeks = useMemo(
        () => weeksOf(filters.from, filters.to),
        [filters.from, filters.to],
    );
    const activeWeek =
        week !== null && week < weeks.length ? weeks[week] : null;
    // Density follows the time slider; other incident layers use the period.
    const densityFilters = useMemo(
        () =>
            activeWeek
                ? { ...filters, from: activeWeek[0], to: activeWeek[1] }
                : filters,
        [filters, activeWeek],
    );

    // Keep the URL in step (shared views).
    useEffect(() => {
        writeUrl({
            layers,
            filters,
            measure,
            urgencies,
            week,
            center: view?.center ?? initial.center,
            zoom: view?.zoom ?? initial.zoom,
        });
    }, [layers, filters, measure, urgencies, week, view, initial]);

    // Load the enabled layers for the view (debounced, cancellable).
    useEffect(() => {
        if (!view) {
            return;
        }

        const timer = window.setTimeout(async () => {
            abort.current?.abort();
            const controller = new AbortController();
            abort.current = controller;
            setLoading(true);

            try {
                const results = await Promise.all(
                    layers
                        .filter((l) => l !== 'incidents')
                        .map(async (key) => {
                            const params = filterParams(
                                key === 'density' ? densityFilters : filters,
                            );
                            if (key.endsWith('choropleth'))
                                params.set('measure', measure);
                            if (key === 'threats')
                                urgencies.forEach((u) =>
                                    params.append('urgencies[]', u),
                                );

                            return [
                                key,
                                await fetchLayer(
                                    key,
                                    view,
                                    params,
                                    controller.signal,
                                ),
                            ] as const;
                        }),
                );
                const next: Partial<Record<LayerKey, LayerResponse>> = {};
                results.forEach(([key, value]) => {
                    if (value) next[key] = value;
                });

                if (layers.includes('incidents')) {
                    // Every counted status unless some are ticked (the list's default is open work only).
                    const counted = filters.statuses.length
                        ? filters
                        : {
                              ...filters,
                              statuses: props.filterOptions.statuses.map(
                                  (o) => o.value,
                              ),
                          };
                    const response = await fetch(
                        `/incidents/map/data?${filterParams(counted).toString()}`,
                        {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                            signal: controller.signal,
                        },
                    );
                    setPoints(
                        response.ok
                            ? (
                                  (await response.json()) as {
                                      points: IncidentMapPoint[];
                                  }
                              ).points
                            : [],
                    );
                } else {
                    setPoints([]);
                }

                setData(next);
            } catch {
                // Aborted by a newer request.
            } finally {
                if (abort.current === controller) setLoading(false);
            }
        }, 250);

        return () => window.clearTimeout(timer);
    }, [
        view,
        layers,
        filters,
        densityFilters,
        measure,
        urgencies,
        props.filterOptions.statuses,
    ]);

    // The time slider's play button: one week every 1.5 seconds.
    useEffect(() => {
        if (!playing) {
            return;
        }

        const timer = window.setInterval(() => {
            setWeek((w) => {
                const next = (w ?? -1) + 1;

                if (next >= weeks.length) {
                    setPlaying(false);

                    return w;
                }

                return next;
            });
        }, 1500);

        return () => window.clearInterval(timer);
    }, [playing, weeks.length]);

    const geoLayers = useMemo<GeoLayer[]>(
        () =>
            LAYER_KEYS.filter((k) => layers.includes(k) && data[k]).map((k) =>
                toGeoLayer(
                    k,
                    data[k] as LayerResponse,
                    k === 'density' ? densityFilters : filters,
                ),
            ),
        [layers, data, filters, densityFilters],
    );
    const markers = useMemo(() => incidentMarkers(points), [points]);
    const onViewChange = useCallback((v: MapViewChange) => setView(v), []);

    const toggle = (key: LayerKey, on: boolean) =>
        setLayers((current) =>
            on ? [...current, key] : current.filter((k) => k !== key),
        );
    const setFilter = <K extends keyof IncidentLayerFilters>(
        key: K,
        value: IncidentLayerFilters[K],
    ) => {
        setFilters((f) => ({ ...f, [key]: value }));
        setWeek(null);
    };
    const toggleIn = (list: string[], value: string, on: boolean) =>
        on ? [...list, value] : list.filter((v) => v !== value);

    const notices = AGGREGATED.filter((k) => layers.includes(k))
        .map((k) => data[k]?.meta?.notice)
        .filter((n): n is string => !!n);

    return (
        <>
            <Head title="Operational map" />
            <div className="grid h-[calc(100vh-4rem)] grid-cols-1 lg:grid-cols-[1fr_340px]">
                <div className="relative min-h-[60vh]">
                    <BuffaloMap
                        className="h-full w-full"
                        center={
                            initial.center ?? props.view.center ?? undefined
                        }
                        zoom={initial.zoom ?? props.view.zoom}
                        outline={props.view.outline}
                        geoLayers={geoLayers}
                        markers={markers}
                        cluster
                        onViewChange={onViewChange}
                    />
                    <div className="pointer-events-none absolute top-3 left-1/2 z-[1000] flex -translate-x-1/2 flex-col items-center gap-1">
                        {loading && (
                            <span
                                className="rounded-md border bg-background/95 px-2 py-1 text-xs shadow-sm"
                                role="status"
                            >
                                Loading…
                            </span>
                        )}
                        {notices.map((n) => (
                            <span
                                key={n}
                                className="rounded-md border bg-background/95 px-2 py-1 text-xs shadow-sm"
                                role="status"
                            >
                                {n}
                            </span>
                        ))}
                    </div>
                </div>

                <aside
                    className="space-y-5 overflow-y-auto border-l p-4 text-sm"
                    aria-label="Layers and filters"
                >
                    <section className="space-y-2">
                        <h2 className="font-semibold">Layers</h2>
                        {LAYER_KEYS.filter((k) => props.layers.includes(k)).map(
                            (key) => {
                                const unavailable =
                                    (key === 'lga_choropleth' &&
                                        props.boundaries.lga === 'none') ||
                                    (key === 'state_choropleth' &&
                                        props.boundaries.state === 'none');

                                return (
                                    <Fragment key={key}>
                                        <div className="flex items-center gap-2">
                                            <Checkbox
                                                id={`layer-${key}`}
                                                checked={layers.includes(key)}
                                                disabled={unavailable}
                                                onCheckedChange={(c) =>
                                                    toggle(key, c === true)
                                                }
                                            />
                                            <Label
                                                htmlFor={`layer-${key}`}
                                                className={
                                                    unavailable
                                                        ? 'text-muted-foreground'
                                                        : ''
                                                }
                                            >
                                                {LAYER_LABELS[key]}
                                                {unavailable &&
                                                    ' (boundaries not imported)'}
                                                {!unavailable &&
                                                    key.endsWith(
                                                        'choropleth',
                                                    ) &&
                                                    props.boundaries[
                                                        key === 'lga_choropleth'
                                                            ? 'lga'
                                                            : 'state'
                                                    ] === 'centroids' &&
                                                    ' (circles at centres)'}
                                            </Label>
                                        </div>
                                        {key === 'lga_choropleth' &&
                                            (layers.includes(
                                                'lga_choropleth',
                                            ) ||
                                                layers.includes(
                                                    'state_choropleth',
                                                )) && (
                                                <div className="grid gap-1 pl-6">
                                                    <Label htmlFor="measure">
                                                        Choropleth value
                                                    </Label>
                                                    <NativeSelect
                                                        id="measure"
                                                        value={measure}
                                                        onChange={(e) =>
                                                            setMeasure(
                                                                e.target
                                                                    .value ===
                                                                    'rate'
                                                                    ? 'rate'
                                                                    : 'count',
                                                            )
                                                        }
                                                    >
                                                        <option value="count">
                                                            Number of incidents
                                                        </option>
                                                        <option value="rate">
                                                            Per 100,000 people
                                                        </option>
                                                    </NativeSelect>
                                                </div>
                                            )}
                                        {key === 'threats' &&
                                            layers.includes('threats') && (
                                                <fieldset className="space-y-1 pl-6">
                                                    <legend className="text-xs text-muted-foreground">
                                                        Threat urgency (none
                                                        ticked: all)
                                                    </legend>
                                                    {URGENCIES.map((u) => (
                                                        <div
                                                            key={u}
                                                            className="flex items-center gap-2"
                                                        >
                                                            <Checkbox
                                                                id={`urgency-${u}`}
                                                                checked={urgencies.includes(
                                                                    u,
                                                                )}
                                                                onCheckedChange={(
                                                                    c,
                                                                ) =>
                                                                    setUrgencies(
                                                                        (
                                                                            list,
                                                                        ) =>
                                                                            toggleIn(
                                                                                list,
                                                                                u,
                                                                                c ===
                                                                                    true,
                                                                            ),
                                                                    )
                                                                }
                                                            />
                                                            <Label
                                                                htmlFor={`urgency-${u}`}
                                                                className="capitalize"
                                                            >
                                                                {u}
                                                            </Label>
                                                        </div>
                                                    ))}
                                                </fieldset>
                                            )}
                                    </Fragment>
                                );
                            },
                        )}
                    </section>

                    {AGGREGATED.some((k) => layers.includes(k)) && (
                        <section className="space-y-1" aria-label="Legend">
                            <h2 className="font-semibold">Legend</h2>
                            <div className="flex items-center gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Fewer
                                </span>
                                {SCALE.map((c) => (
                                    <span
                                        key={c}
                                        className="inline-block h-3 w-6"
                                        style={{ background: c }}
                                    />
                                ))}
                                <span className="text-xs text-muted-foreground">
                                    More
                                </span>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Counts include only incidents you can see;
                                drafts and invalid reports are never counted.
                            </p>
                        </section>
                    )}

                    {(layers.includes('flashpoints') ||
                        layers.includes('threats')) && (
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <span
                                aria-hidden
                                className="inline-block size-3 rounded-full border-4"
                                style={{ borderColor: SHARED_COLOUR }}
                            />
                            Teal outline: shared with you by another state or
                            LGA (read-only).
                        </p>
                    )}

                    <section className="space-y-3">
                        <h2 className="font-semibold">Incidents</h2>
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1">
                                <Label htmlFor="from">From</Label>
                                <Input
                                    id="from"
                                    type="date"
                                    value={filters.from}
                                    onChange={(e) =>
                                        setFilter('from', e.target.value)
                                    }
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="to">To</Label>
                                <Input
                                    id="to"
                                    type="date"
                                    value={filters.to}
                                    onChange={(e) =>
                                        setFilter('to', e.target.value)
                                    }
                                />
                            </div>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="category">Category</Label>
                            <NativeSelect
                                id="category"
                                value={filters.category}
                                onChange={(e) =>
                                    setFilter('category', e.target.value)
                                }
                            >
                                <option value="">All categories</option>
                                {props.filterOptions.categories.map((o) => (
                                    <option key={o.value} value={o.value}>
                                        {o.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="type">Incident type</Label>
                            <NativeSelect
                                id="type"
                                value={filters.types[0] ?? ''}
                                onChange={(e) =>
                                    setFilter(
                                        'types',
                                        e.target.value ? [e.target.value] : [],
                                    )
                                }
                            >
                                <option value="">All types</option>
                                {props.filterOptions.types
                                    .filter(
                                        (t) =>
                                            !filters.category ||
                                            t.category === filters.category,
                                    )
                                    .map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </div>
                        <fieldset className="space-y-1">
                            <legend className="text-xs text-muted-foreground">
                                Severity (none ticked: all)
                            </legend>
                            {props.filterOptions.severities.map((o) => (
                                <div
                                    key={o.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`severity-${o.value}`}
                                        checked={filters.severities.includes(
                                            o.value,
                                        )}
                                        onCheckedChange={(c) =>
                                            setFilter(
                                                'severities',
                                                toggleIn(
                                                    filters.severities,
                                                    o.value,
                                                    c === true,
                                                ),
                                            )
                                        }
                                    />
                                    <Label htmlFor={`severity-${o.value}`}>
                                        {o.label}
                                    </Label>
                                </div>
                            ))}
                        </fieldset>
                        <fieldset className="space-y-1">
                            <legend className="text-xs text-muted-foreground">
                                Status (none ticked: all counted)
                            </legend>
                            {props.filterOptions.statuses.map((o) => (
                                <div
                                    key={o.value}
                                    className="flex items-center gap-2"
                                >
                                    <Checkbox
                                        id={`status-${o.value}`}
                                        checked={filters.statuses.includes(
                                            o.value,
                                        )}
                                        onCheckedChange={(c) =>
                                            setFilter(
                                                'statuses',
                                                toggleIn(
                                                    filters.statuses,
                                                    o.value,
                                                    c === true,
                                                ),
                                            )
                                        }
                                    />
                                    <Label htmlFor={`status-${o.value}`}>
                                        {o.label}
                                    </Label>
                                </div>
                            ))}
                        </fieldset>
                    </section>

                    {layers.includes('density') && weeks.length > 1 && (
                        <section className="space-y-2" aria-label="Time slider">
                            <h2 className="font-semibold">Week by week</h2>
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="by-week"
                                    checked={week !== null}
                                    onCheckedChange={(c) => {
                                        setWeek(c === true ? 0 : null);
                                        setPlaying(false);
                                    }}
                                />
                                <Label htmlFor="by-week">
                                    Step through the period by week
                                </Label>
                            </div>
                            {week !== null && (
                                <>
                                    <input
                                        type="range"
                                        aria-label="Week"
                                        min={0}
                                        max={weeks.length - 1}
                                        value={week}
                                        onChange={(e) =>
                                            setWeek(Number(e.target.value))
                                        }
                                        className="w-full accent-primary"
                                    />
                                    <div className="flex items-center justify-between gap-2">
                                        <span
                                            className="text-xs"
                                            data-test="week-label"
                                        >
                                            Week {week + 1} of {weeks.length}:{' '}
                                            {activeWeek
                                                ? `${formatDate(activeWeek[0])} – ${formatDate(activeWeek[1])}`
                                                : ''}
                                        </span>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                if (
                                                    !playing &&
                                                    week >= weeks.length - 1
                                                )
                                                    setWeek(0);
                                                setPlaying(!playing);
                                            }}
                                        >
                                            {playing ? <Pause /> : <Play />}
                                            {playing ? 'Pause' : 'Play'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </section>
                    )}
                </aside>
            </div>
        </>
    );
}
