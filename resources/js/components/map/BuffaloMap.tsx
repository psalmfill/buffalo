import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import 'leaflet/dist/leaflet.css';
import 'leaflet.markercluster';
import 'leaflet.markercluster/dist/MarkerCluster.css';
import 'leaflet.markercluster/dist/MarkerCluster.Default.css';
import { usePage } from '@inertiajs/react';
import type { Geometry } from 'geojson';
import { PMTiles } from 'pmtiles';
import { leafletLayer } from 'protomaps-leaflet';
import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { NIGERIA_BOUNDS, NIGERIA_CENTER } from './nigeria';

/**
 * Self-hosted basemap: a Protomaps extract of Nigeria, served by nginx from
 * the map_tiles volume (see scripts/fetch-map-tiles.sh). Nothing is loaded
 * from external hosts:
 * - Leaflet's CSS and marker images are bundled by Vite.
 * - protomaps-leaflet draws labels on canvas with the system sans-serif
 *   font; no glyphs, sprites or web fonts are fetched.
 */
const TILES_URL = '/tiles/nigeria.pmtiles';

// Leaflet derives marker image paths from its CSS at runtime, which breaks
// once Vite fingerprints the files. Point it at the bundled assets directly.
L.Icon.Default.imagePath = '';
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

// Plain-text attribution. The library defaults contain links to external
// sites; OpenStreetMap attribution is still required by the ODbL.
const ATTRIBUTION = 'Protomaps © OpenStreetMap contributors';

/** A point shown on the map, with an optional popup and link. */
export type MapMarker = {
    id: string;
    position: [number, number];
    title: string;
    /** Plain text (never HTML). */
    popup?: string;
    href?: string;
    /** Marker style: the default pin, or a layer-specific symbol. */
    kind?: 'default' | 'religious';
    /**
     * A coloured dot instead of the pin (e.g. one colour per agency). A
     * hex colour from our own palette; never user input.
     */
    color?: string;
    /** The dot's shape (e.g. one per incident category). */
    shape?: MarkerShape;
};

/**
 * An area drawn as a circle (e.g. a flashpoint and its radius), with an
 * optional popup and link.
 */
export type MapCircle = {
    id: string;
    center: [number, number];
    /** Metres. */
    radius: number;
    /** Hex colour from our own palette; never user input. */
    color: string;
    title: string;
    /** Plain text (never HTML). */
    popup?: string;
    href?: string;
    /** Dashed outline (e.g. a dormant or resolved area). */
    dashed?: boolean;
};

export type MarkerShape = 'circle' | 'square' | 'diamond' | 'triangle' | 'ring';

/** A coloured dot marker, optionally shaped (no image assets). */
function dotIcon(color: string, shape: MarkerShape = 'circle'): L.DivIcon {
    const safe = /^#[0-9a-f]{6}$/i.test(color) ? color : '#475569';
    const style: Record<MarkerShape, string> = {
        circle: 'border-radius:9999px',
        square: 'border-radius:2px',
        diamond: 'border-radius:2px;transform:rotate(45deg) scale(.85)',
        triangle: 'border-radius:2px;clip-path:polygon(50% 0,100% 100%,0 100%)',
        ring: `border-radius:9999px;background:#fff !important;border:4px solid ${safe} !important`,
    };

    return L.divIcon({
        className: '',
        html: `<span style="display:block;width:14px;height:14px;background:${safe};border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,.45);${style[shape]}"></span>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
        popupAnchor: [0, -8],
    });
}

/** A small purple dot for religious institutions (no image assets). */
const RELIGIOUS_ICON = L.divIcon({
    className: '',
    html: '<span style="display:block;width:14px;height:14px;border-radius:9999px;background:#7c3aed;border:2px solid #fff;box-shadow:0 0 0 1px #4c1d95"></span>',
    iconSize: [14, 14],
    iconAnchor: [7, 7],
    popupAnchor: [0, -8],
});

/** Click-to-set, draggable point (MapPointPicker). */
export type MapPicker = {
    value: [number, number] | null;
    onChange: (value: [number, number]) => void;
};

type BuffaloMapProps = {
    center?: L.LatLngExpression;
    zoom?: number;
    className?: string;
    /** Points to display. */
    markers?: MapMarker[];
    /**
     * Group nearby markers into clusters showing counts (leaflet.
     * markercluster, Phase 5D): for layers that can hold many points.
     * Decided when the map is created.
     */
    cluster?: boolean;
    /** Zoom to fit the markers once they are shown. */
    fitMarkers?: boolean;
    picker?: MapPicker;
    /**
     * A place's boundary (GeoJSON geometry from MapView), drawn as an
     * outline below the markers.
     */
    outline?: Geometry | null;
    /** Zoom to fit the outline once it is shown. */
    fitOutline?: boolean;
    /** Areas drawn as circles, below the markers. */
    circles?: MapCircle[];
    /** Zoom to fit the circles once they are shown. */
    fitCircles?: boolean;
};

/** Attribution strings are rendered as HTML by Leaflet: escape them. */
function escapeText(text: string): string {
    const span = document.createElement('span');
    span.textContent = text;

    return span.innerHTML;
}

/** Popup content built from text nodes, so names cannot inject HTML. */
function popupContent(
    marker: Pick<MapMarker, 'title' | 'popup' | 'href'>,
): HTMLElement {
    const root = document.createElement('div');
    const title = document.createElement(marker.href ? 'a' : 'strong');
    title.textContent = marker.title;

    if (marker.href && title instanceof HTMLAnchorElement) {
        title.href = marker.href;
        title.className = 'font-medium underline';
    }

    root.appendChild(title);

    if (marker.popup) {
        const detail = document.createElement('div');
        detail.textContent = marker.popup;
        detail.className = 'text-xs';
        root.appendChild(detail);
    }

    return root;
}

export default function BuffaloMap({
    center = NIGERIA_CENTER,
    zoom = 6,
    className,
    markers,
    fitMarkers = false,
    picker,
    cluster = false,
    outline = null,
    fitOutline = false,
    circles,
    fitCircles = false,
}: BuffaloMapProps) {
    const boundaryAttribution = usePage().props.boundaryAttribution;
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<L.Map | null>(null);
    const markerLayerRef = useRef<L.LayerGroup | null>(null);
    const outlineLayerRef = useRef<L.GeoJSON | null>(null);
    const circleLayerRef = useRef<L.FeatureGroup | null>(null);
    const pickerMarkerRef = useRef<L.Marker | null>(null);
    // The latest picker callback, for the map's click handler.
    const pickerRef = useRef<MapPicker | undefined>(picker);
    const [tilesMissing, setTilesMissing] = useState(false);
    const [ready, setReady] = useState(false);

    useEffect(() => {
        pickerRef.current = picker;
    });

    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        let cancelled = false;

        const map = L.map(container, {
            center,
            zoom,
            minZoom: 5,
            maxZoom: 18,
            maxBounds: NIGERIA_BOUNDS.pad(0.1),
            maxBoundsViscosity: 1.0,
            attributionControl: false,
        });

        const attribution = L.control
            .attribution({ prefix: 'Leaflet' })
            .addTo(map);

        // Imported boundaries carry their own attribution (Phase 6A).
        if (boundaryAttribution) {
            attribution.addAttribution(
                `Boundaries: ${escapeText(boundaryAttribution)}`,
            );
        }

        mapRef.current = map;
        circleLayerRef.current = L.featureGroup().addTo(map);
        markerLayerRef.current = (
            cluster
                ? L.markerClusterGroup({
                      chunkedLoading: true,
                      showCoverageOnHover: false,
                      maxClusterRadius: 50,
                  })
                : L.layerGroup()
        ).addTo(map);
        map.on('click', (event: L.LeafletMouseEvent) => {
            pickerRef.current?.onChange([
                Number(event.latlng.lat.toFixed(6)),
                Number(event.latlng.lng.toFixed(6)),
            ]);
        });
        setReady(true);

        const archive = new PMTiles(TILES_URL);

        // Read the archive header first so a missing or unreadable file is
        // reported instead of silently rendering blank tiles.
        archive
            .getHeader()
            .then((header) => {
                if (cancelled) {
                    return;
                }

                const dark =
                    document.documentElement.classList.contains('dark');
                // protomaps-leaflet returns a Leaflet GridLayer subclass
                // instance but types it as a plain object.
                const basemap = leafletLayer({
                    url: archive,
                    flavor: dark ? 'dark' : 'light',
                    lang: 'en',
                    attribution: ATTRIBUTION,
                    maxDataZoom: header.maxZoom,
                    bounds: NIGERIA_BOUNDS,
                }) as unknown as L.Layer;

                basemap.addTo(map);
            })
            .catch(() => {
                if (!cancelled) {
                    setTilesMissing(true);
                }
            });

        return () => {
            cancelled = true;
            mapRef.current = null;
            markerLayerRef.current = null;
            outlineLayerRef.current = null;
            circleLayerRef.current = null;
            pickerMarkerRef.current = null;
            setReady(false);
            map.remove();
        };
        // The map is created once; later phases will add props for layers
        // and react to changes explicitly.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Display markers.
    useEffect(() => {
        const map = mapRef.current;
        const layer = markerLayerRef.current;

        if (!ready || !map || !layer) {
            return;
        }

        layer.clearLayers();

        // Popups are built on demand: thousands of markers stay cheap.
        const created = (markers ?? []).map((marker) =>
            L.marker(marker.position, {
                title: marker.title,
                ...(marker.color
                    ? { icon: dotIcon(marker.color, marker.shape) }
                    : marker.kind === 'religious'
                      ? { icon: RELIGIOUS_ICON }
                      : {}),
            }).bindPopup(() => popupContent(marker)),
        );

        if (layer instanceof L.MarkerClusterGroup) {
            layer.addLayers(created);
        } else {
            created.forEach((m) => m.addTo(layer));
        }

        if (fitMarkers && markers && markers.length > 0) {
            map.invalidateSize();
            map.fitBounds(
                L.latLngBounds(markers.map((marker) => marker.position)),
                { padding: [24, 24], maxZoom: 13 },
            );
        }
    }, [ready, markers, fitMarkers]);

    // Display the place outline (non-interactive, below markers).
    useEffect(() => {
        const map = mapRef.current;

        if (!ready || !map) {
            return;
        }

        outlineLayerRef.current?.remove();
        outlineLayerRef.current = null;

        if (!outline) {
            return;
        }

        const layer = L.geoJSON(outline, {
            interactive: false,
            style: {
                color: '#0f766e',
                weight: 2,
                fillColor: '#14b8a6',
                fillOpacity: 0.06,
            },
        }).addTo(map);
        layer.bringToBack();
        outlineLayerRef.current = layer;

        if (fitOutline) {
            map.invalidateSize();
            map.fitBounds(layer.getBounds(), { padding: [16, 16] });
        }
    }, [ready, outline, fitOutline]);

    // Display areas (circles), above the outline and below markers.
    useEffect(() => {
        const map = mapRef.current;
        const layer = circleLayerRef.current;

        if (!ready || !map || !layer) {
            return;
        }

        layer.clearLayers();

        for (const area of circles ?? []) {
            const color = /^#[0-9a-f]{6}$/i.test(area.color)
                ? area.color
                : '#475569';

            L.circle(area.center, {
                radius: area.radius,
                color,
                weight: 2,
                fillColor: color,
                fillOpacity: 0.18,
                dashArray: area.dashed ? '6 6' : undefined,
            })
                .bindTooltip(area.title)
                .bindPopup(() => popupContent(area))
                .addTo(layer);
        }

        if (fitCircles && (circles ?? []).length > 0) {
            // Bounds from geometry: a circle's own getBounds() depends on
            // its projection at the current zoom.
            const bounds = (circles ?? []).reduce(
                (all, area) =>
                    all.extend(L.latLng(area.center).toBounds(area.radius * 2)),
                L.latLngBounds([]),
            );
            map.invalidateSize();
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 14 });
        }
    }, [ready, circles, fitCircles]);

    // The picker's draggable marker follows its value.
    const pickerValue = picker?.value ?? null;
    const picking = picker !== undefined;

    useEffect(() => {
        const map = mapRef.current;

        if (!ready || !map) {
            return;
        }

        if (!picking || pickerValue === null) {
            pickerMarkerRef.current?.remove();
            pickerMarkerRef.current = null;

            return;
        }

        if (pickerMarkerRef.current) {
            pickerMarkerRef.current.setLatLng(pickerValue);
        } else {
            const marker = L.marker(pickerValue, {
                draggable: true,
                title: 'Selected location (drag to adjust)',
            }).addTo(map);
            marker.on('dragend', () => {
                const position = marker.getLatLng();
                pickerRef.current?.onChange([
                    Number(position.lat.toFixed(6)),
                    Number(position.lng.toFixed(6)),
                ]);
            });
            pickerMarkerRef.current = marker;
        }
    }, [ready, picking, pickerValue]);

    return (
        <div className={cn('relative isolate overflow-hidden', className)}>
            <div
                ref={containerRef}
                className={cn(
                    'h-full w-full !bg-muted',
                    picker && 'cursor-crosshair',
                )}
                aria-label="Map of Nigeria"
            />
            {tilesMissing && (
                <div
                    role="status"
                    className="pointer-events-none absolute top-3 left-1/2 z-[1000] -translate-x-1/2 rounded-md border bg-background/95 px-3 py-1.5 text-xs text-muted-foreground shadow-sm"
                >
                    Map tiles not installed — run <code>make tiles</code>.
                </div>
            )}
        </div>
    );
}
