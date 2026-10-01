import { CONCERN_COLOURS } from '@/components/flashpoints/flashpoint-areas';
import type { ConcernLevel } from '@/types/flashpoints';

/** A flashpoint's concern level, with its map colour. */
export default function ConcernBadge({
    level,
}: {
    level: { value: ConcernLevel; label: string };
}) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span
                aria-hidden
                className="inline-block size-2.5 rounded-full"
                style={{ background: CONCERN_COLOURS[level.value] }}
            />
            {level.label}
        </span>
    );
}

/** Legend for flashpoint maps. */
export function ConcernLegend() {
    return (
        <ul
            className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
            aria-label="Map legend"
        >
            {(Object.keys(CONCERN_COLOURS) as ConcernLevel[]).map((level) => (
                <li key={level} className="inline-flex items-center gap-1.5">
                    <span
                        aria-hidden
                        className="inline-block size-2.5 rounded-full"
                        style={{ background: CONCERN_COLOURS[level] }}
                    />
                    <span className="capitalize">{level}</span> concern
                </li>
            ))}
            <li>Dashed: dormant or resolved</li>
        </ul>
    );
}
