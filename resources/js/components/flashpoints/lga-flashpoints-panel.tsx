import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import ConcernBadge, {
    ConcernLegend,
} from '@/components/flashpoints/concern-badge';
import {
    flashpointCircles,
    formatRadius,
} from '@/components/flashpoints/flashpoint-areas';
import BuffaloMap from '@/components/map/BuffaloMap';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/time';
import type { MapView } from '@/types/dashboard';
import type { FlashpointRow } from '@/types/flashpoints';

export type LgaFlashpointsTab = {
    rows: FlashpointRow[];
    map: MapView;
    canCreate: boolean;
};

/** The Flashpoints tab of an LGA (Phase 6A): areas the viewer can see. */
export default function LgaFlashpointsPanel({
    lgaUlid,
    data,
}: {
    lgaUlid: string;
    data: LgaFlashpointsTab;
}) {
    const circles = useMemo(
        () =>
            flashpointCircles(
                data.rows,
                (ulid) => FlashpointController.show(ulid).url,
            ),
        [data.rows],
    );

    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                <h2 className="text-sm font-semibold">
                    Flashpoints and blackspots
                </h2>
                {data.canCreate && (
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={FlashpointController.create({
                                query: { lga: lgaUlid },
                            })}
                        >
                            <Plus /> Record flashpoint
                        </Link>
                    </Button>
                )}
            </div>
            <div className="space-y-2">
                <BuffaloMap
                    className="h-80 w-full rounded-lg border"
                    center={data.map.center ?? undefined}
                    zoom={data.map.zoom}
                    outline={data.map.outline}
                    circles={circles}
                />
                <ConcernLegend />
            </div>
            {data.rows.length === 0 ? (
                <EmptyState
                    title="No flashpoints"
                    description="None recorded in this LGA that you can view."
                />
            ) : (
                <ul className="divide-y rounded-lg border">
                    {data.rows.map((f) => (
                        <li
                            key={f.ulid}
                            className="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
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
                                        · {f.status.label} ·{' '}
                                        {formatRadius(f.radius_meters)} · review{' '}
                                        {formatDate(f.review_due_at)}
                                    </span>
                                    {f.review_overdue && (
                                        <Badge variant="destructive">
                                            Overdue
                                        </Badge>
                                    )}
                                </p>
                            </div>
                            <ClassificationBadge
                                level={f.classification.value}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
