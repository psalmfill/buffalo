import { Head, Link, router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import BlackspotSuggestionController from '@/actions/App/Http/Controllers/Map/BlackspotSuggestionController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import IncidentTable from '@/components/incidents/incident-table';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapCircle } from '@/components/map/BuffaloMap';
import { Button } from '@/components/ui/button';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { formatDate } from '@/lib/time';
import type { IncidentListRow } from '@/types/incidents';
import type { NamedRef } from '@/types/local-government';

type Suggestion = {
    ulid: string;
    lga: NamedRef;
    state: NamedRef;
    latitude: number;
    longitude: number;
    radius_meters: number;
    /** Visible to you; never the full cluster. */
    incident_count: number;
    dominant_type: string | null;
    category: string | null;
    first_occurred_at: string;
    last_occurred_at: string;
    generated_at: string;
};

/**
 * Suggested blackspots (Phase 6C): clusters of recent incidents not yet
 * covered by a flashpoint. Counts and lists include only incidents you
 * can see. Accept opens the flashpoint form pre-filled; dismiss needs a
 * reason (the area is not suggested again for a while).
 */
export default function BlackspotSuggestions({
    suggestions,
    open,
    settings,
    can,
}: {
    suggestions: Suggestion[];
    open: { ulid: string; members: IncidentListRow[] } | null;
    settings: { eps_meters: number; min_points: number; days: number };
    can: { refresh: boolean; accept: boolean };
}) {
    const [dismissing, setDismissing] = useState<Suggestion | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const circles = useMemo<MapCircle[]>(
        () =>
            suggestions.map((s) => ({
                id: s.ulid,
                center: [s.latitude, s.longitude],
                radius: s.radius_meters,
                color: '#7c3aed',
                title: `${s.incident_count} incidents · ${s.lga.name}`,
                href: BlackspotSuggestionController.index({
                    query: { open: s.ulid },
                }).url,
                dashed: true,
            })),
        [suggestions],
    );

    return (
        <>
            <Head title="Suggested blackspots" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{
                            topic: 'guides/flashpoints',
                            section: 'suggested-blackspots',
                        }}
                        title="Suggested blackspots"
                        description={`Places where at least ${settings.min_points} incidents happened within about ${settings.eps_meters} m of each other in the last ${settings.days} days, clearly more than chance, and no flashpoint covers yet. Refreshed nightly.`}
                    />
                    {can.refresh && (
                        <Button
                            variant="outline"
                            disabled={refreshing}
                            onClick={() =>
                                router.post(
                                    BlackspotSuggestionController.refresh().url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setRefreshing(true),
                                        onFinish: () => setRefreshing(false),
                                    },
                                )
                            }
                        >
                            <RefreshCw
                                className={refreshing ? 'animate-spin' : ''}
                            />
                            Refresh now
                        </Button>
                    )}
                </div>

                {suggestions.length > 0 && (
                    <BuffaloMap
                        className="h-80 w-full rounded-lg border"
                        circles={circles}
                        fitCircles
                    />
                )}

                {suggestions.length === 0 ? (
                    <EmptyState
                        title="No suggestions"
                        description="No unexplained clusters of incidents you can see."
                    />
                ) : (
                    <ul className="space-y-3" aria-label="Suggested blackspots">
                        {suggestions.map((s) => (
                            <li
                                key={s.ulid}
                                className="space-y-3 rounded-lg border p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="space-y-0.5">
                                        <p className="font-medium">
                                            {s.incident_count} incidents
                                            {s.dominant_type
                                                ? `, mostly ${s.dominant_type}`
                                                : ''}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {s.lga.name} LGA, {s.state.name} ·
                                            radius {s.radius_meters} m ·{' '}
                                            {formatDate(
                                                s.first_occurred_at.slice(
                                                    0,
                                                    10,
                                                ),
                                            )}{' '}
                                            –{' '}
                                            {formatDate(
                                                s.last_occurred_at.slice(0, 10),
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link
                                                href={BlackspotSuggestionController.index(
                                                    {
                                                        query:
                                                            open?.ulid ===
                                                            s.ulid
                                                                ? {}
                                                                : {
                                                                      open: s.ulid,
                                                                  },
                                                    },
                                                )}
                                                preserveScroll
                                            >
                                                {open?.ulid === s.ulid
                                                    ? 'Hide incidents'
                                                    : 'Show incidents'}
                                            </Link>
                                        </Button>
                                        {can.accept && (
                                            <Button size="sm" asChild>
                                                <Link
                                                    href={FlashpointController.create(
                                                        {
                                                            query: {
                                                                suggestion:
                                                                    s.ulid,
                                                            },
                                                        },
                                                    )}
                                                >
                                                    Accept
                                                </Link>
                                            </Button>
                                        )}
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setDismissing(s)}
                                        >
                                            Dismiss
                                        </Button>
                                    </div>
                                </div>
                                {open?.ulid === s.ulid && (
                                    <IncidentTable
                                        rows={open.members}
                                        compact
                                        empty="No incidents you can see."
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {dismissing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(o) => !o && setDismissing(null)}
                    title="Dismiss this suggestion?"
                    description="Say why it is not a blackspot. The area will not be suggested again for 90 days."
                    confirmLabel="Dismiss"
                    requireReason
                    url={
                        BlackspotSuggestionController.dismiss(dismissing.ulid)
                            .url
                    }
                />
            )}
        </>
    );
}

BlackspotSuggestions.layout = {
    breadcrumbs: [
        {
            title: 'Suggested blackspots',
            href: BlackspotSuggestionController.index(),
        },
    ],
};
