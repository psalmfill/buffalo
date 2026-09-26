import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import 'leaflet/dist/leaflet.css';
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
};

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
    /**
     * Points to display. Plain markers: clustering would need a new package
     * (see the Phase 2B handover).
     */
    markers?: MapMarker[];
    /** Zoom to fit the markers once they are shown. */
    fitMarkers?: boolean;
    picker?: MapPicker;
};

/** Popup content built from text nodes, so names cannot inject HTML. */
function popupContent(marker: MapMarker): HTMLElement {
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
}: BuffaloMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<L.Map | null>(null);
    const markerLayerRef = useRef<L.LayerGroup | null>(null);
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

        L.control.attribution({ prefix: 'Leaflet' }).addTo(map);

        mapRef.current = map;
        markerLayerRef.current = L.layerGroup().addTo(map);
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

        for (const marker of markers ?? []) {
            L.marker(marker.position, {
                title: marker.title,
                ...(marker.kind === 'religious'
                    ? { icon: RELIGIOUS_ICON }
                    : {}),
            })
                .bindPopup(popupContent(marker))
                .addTo(layer);
        }

        if (fitMarkers && markers && markers.length > 0) {
            map.fitBounds(
                L.latLngBounds(markers.map((marker) => marker.position)),
                { padding: [24, 24], maxZoom: 13 },
            );
        }
    }, [ready, markers, fitMarkers]);

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
