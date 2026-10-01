import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import type { FormationRow } from '@/types/formations';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';

/**
 * Colour-blind-friendly categorical palette for agencies. Agencies are
 * administrator-extensible, so colours are assigned by order of appearance
 * on the map (stable for a given list) and explained in the legend.
 */
const PALETTE = [
    '#1d4ed8',
    '#b91c1c',
    '#047857',
    '#a16207',
    '#7c3aed',
    '#0e7490',
    '#be185d',
    '#4d7c0f',
    '#c2410c',
    '#334155',
];

export function agencyColours(
    formations: FormationRow[],
): Map<string, { colour: string; name: string }> {
    const colours = new Map<string, { colour: string; name: string }>();

    for (const formation of formations) {
        if (!colours.has(formation.agency.ulid)) {
            colours.set(formation.agency.ulid, {
                colour: PALETTE[colours.size % PALETTE.length],
                name: formation.agency.short_name || formation.agency.name,
            });
        }
    }

    return colours;
}

/** Formations with a location, one colour per agency, with a legend. */
export default function FormationMap({
    formations,
    center,
    zoom,
    className = 'h-96',
}: {
    formations: FormationRow[];
    center?: [number, number] | null;
    zoom?: number;
    className?: string;
}) {
    const located = formations.filter(
        (f) => f.latitude !== null && f.longitude !== null,
    );
    const colours = agencyColours(located);

    const markers: MapMarker[] = located.map((f) => ({
        id: f.ulid,
        position: [f.latitude as number, f.longitude as number],
        title: f.name,
        popup: `${f.agency.short_name || f.agency.name} · ${f.formation_type.label}${f.is_temporary ? ' (temporary)' : ''} · ${f.lga.name}`,
        href: FormationController.show(f.ulid).url,
        color: colours.get(f.agency.ulid)?.colour,
    }));

    return (
        <div className="space-y-2">
            <BuffaloMap
                className={`${className} rounded-lg border`}
                markers={markers}
                fitMarkers={markers.length > 0}
                cluster
                {...(center ? { center, zoom } : {})}
            />
            {colours.size > 0 ? (
                <ul
                    className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    aria-label="Map legend"
                >
                    {[...colours.entries()].map(([ulid, { colour, name }]) => (
                        <li key={ulid} className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="inline-block size-3 rounded-full border border-white shadow"
                                style={{ background: colour }}
                            />
                            {name}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-xs text-muted-foreground">
                    No formations with a recorded location.
                </p>
            )}
        </div>
    );
}
