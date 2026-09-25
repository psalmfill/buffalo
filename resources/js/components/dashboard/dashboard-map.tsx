import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapView } from '@/types/dashboard';

/**
 * The dashboard map: centred on the jurisdiction's centroid when known,
 * otherwise the national view. Later phases add layers.
 */
export default function DashboardMap({ view }: { view: MapView }) {
    return (
        <BuffaloMap
            center={view.center ?? undefined}
            zoom={view.zoom}
            className="h-[420px] w-full rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        />
    );
}
