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

type BuffaloMapProps = {
    center?: L.LatLngExpression;
    zoom?: number;
    className?: string;
};

export default function BuffaloMap({
    center = NIGERIA_CENTER,
    zoom = 6,
    className,
}: BuffaloMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const [tilesMissing, setTilesMissing] = useState(false);

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
            map.remove();
        };
        // The map is created once; later phases will add props for layers
        // and react to changes explicitly.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className={cn('relative isolate overflow-hidden', className)}>
            <div
                ref={containerRef}
                className="h-full w-full !bg-muted"
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
