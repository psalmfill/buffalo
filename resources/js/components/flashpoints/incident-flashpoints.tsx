import { Link, router } from '@inertiajs/react';
import { Flame, Link2 } from 'lucide-react';
import { useState } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import FlashpointLinkController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointLinkController';
import { Button } from '@/components/ui/button';
import ConcernBadge from '@/components/flashpoints/concern-badge';
import { formatRadius } from '@/components/flashpoints/flashpoint-areas';
import type { FlashpointRow, IncidentFlashpoints } from '@/types/flashpoints';

/** "Near flashpoint" next to an incident's title (visible areas only). */
export function NearFlashpointBadge({
    data,
}: {
    data: IncidentFlashpoints | null;
}) {
    if (!data || data.near.length + data.linked.length === 0) {
        return null;
    }

    return (
        <span
            className="inline-flex items-center gap-1 rounded border border-orange-600/40 bg-orange-50 px-1.5 py-0.5 text-xs font-medium text-orange-800 dark:bg-orange-950 dark:text-orange-300"
            data-test="near-flashpoint"
        >
            <Flame className="size-3" />
            {data.linked.length > 0
                ? 'Linked to a flashpoint'
                : 'Near flashpoint'}
        </span>
    );
}

/**
 * The Flashpoints section of an incident (Phase 6A): flashpoints it is
 * linked to, and active or monitoring areas that contain it. Only
 * flashpoints the viewer can see are listed.
 */
export default function IncidentFlashpointsSection({
    data,
    incidentUlid,
}: {
    data: IncidentFlashpoints;
    incidentUlid: string;
}) {
    const [busy, setBusy] = useState<string | null>(null);

    if (data.linked.length + data.near.length === 0) {
        return null;
    }

    const link = (flashpointUlid: string) =>
        router.post(
            FlashpointLinkController.storeIncident(flashpointUlid).url,
            { record: incidentUlid },
            {
                preserveScroll: true,
                onStart: () => setBusy(flashpointUlid),
                onFinish: () => setBusy(null),
            },
        );

    const item = (f: FlashpointRow, note?: string | null, canLink = false) => (
        <li
            key={f.ulid}
            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
        >
            <div className="space-y-0.5">
                <Link
                    href={FlashpointController.show(f.ulid)}
                    className="text-sm font-medium hover:underline"
                >
                    {f.name}
                </Link>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span>
                        {f.kind.label} · {f.threat_type.name}
                    </span>
                    <ConcernBadge level={f.concern_level} />
                    <span>
                        · {f.status.label} · {formatRadius(f.radius_meters)}{' '}
                        radius
                    </span>
                </p>
                {note && <p className="text-xs whitespace-pre-line">{note}</p>}
            </div>
            {canLink && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy === f.ulid}
                    onClick={() => link(f.ulid)}
                >
                    <Link2 /> Link
                </Button>
            )}
        </li>
    );

    return (
        <section
            className="space-y-3 rounded-lg border p-4"
            aria-label="Flashpoints"
        >
            <h2 className="text-sm font-semibold">Flashpoints</h2>
            {data.linked.length > 0 && (
                <div className="space-y-1">
                    <h3 className="text-xs text-muted-foreground">Linked</h3>
                    <ul className="divide-y rounded-md border">
                        {data.linked.map((f) => item(f, f.link_note))}
                    </ul>
                </div>
            )}
            {data.near.length > 0 && (
                <div className="space-y-1">
                    <h3 className="text-xs text-muted-foreground">
                        Inside the area of (not linked)
                    </h3>
                    <ul className="divide-y rounded-md border">
                        {data.near.map((f) => item(f, null, f.can_link))}
                    </ul>
                </div>
            )}
        </section>
    );
}
