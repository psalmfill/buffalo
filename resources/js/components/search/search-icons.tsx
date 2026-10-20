import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    ClipboardList,
    Crosshair,
    Flame,
    Gavel,
    Landmark,
    MapPin,
    Network,
    Search,
    Shield,
    TriangleAlert,
    UserSearch,
} from 'lucide-react';

/** One icon per global search result type (as in the navigation). */
export const SEARCH_ICONS: Record<string, LucideIcon> = {
    incidents: ClipboardList,
    threats: TriangleAlert,
    arrests: Gavel,
    flashpoints: Flame,
    persons: UserSearch,
    pois: Crosshair,
    groups: Network,
    communities: MapPin,
    organizations: Building2,
    formations: Shield,
    places: Landmark,
};

export function SearchIcon({
    type,
    className,
}: {
    type: string;
    className?: string;
}) {
    const Icon = SEARCH_ICONS[type] ?? Search;

    return <Icon className={className} aria-hidden />;
}
