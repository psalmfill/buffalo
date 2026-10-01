import { Link } from '@inertiajs/react';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import EmptyState from '@/components/empty-state';
import type { MapCircle, MapMarker } from '@/components/map/BuffaloMap';
import { Badge } from '@/components/ui/badge';
import { formatDateTime, timeAgo } from '@/lib/time';
import type { ThreatRow, ThreatUrgency } from '@/types/threats';

/** One colour per urgency (badges, map points and circles). */
export const URGENCY_COLOURS: Record<ThreatUrgency, string> = {
    routine: '#2563eb',
    priority: '#ea580c',
    immediate: '#b91c1c',
};

export function UrgencyBadge({
    urgency,
}: {
    urgency: { value: ThreatUrgency; label: string };
}) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span
                aria-hidden
                className="inline-block size-2.5 rounded-full"
                style={{ background: URGENCY_COLOURS[urgency.value] }}
            />
            {urgency.label}
        </span>
    );
}

/** "expires in 3 hours" / "expired 2 days ago". */
export function ExpiryText({ row }: { row: ThreatRow }) {
    return (
        <span title={formatDateTime(row.expires_at)}>
            {row.status.value === 'active' ? 'expires ' : 'expiry '}
            {timeAgo(row.expires_at)}
            {row.expiring_soon && (
                <Badge variant="destructive" className="ml-2">
                    Expiring soon
                </Badge>
            )}
        </span>
    );
}

/** "Sat 4 Oct, 06:00 – Sun 5 Oct, 18:00", or null. */
export function expectedWindow(row: ThreatRow): string | null {
    if (!row.expected_from && !row.expected_until) {
        return null;
    }

    return `${row.expected_from ? formatDateTime(row.expected_from) : '…'} – ${row.expected_until ? formatDateTime(row.expected_until) : '…'}`;
}

/** Threats with a point: circles (with a radius) and markers. */
export function threatMapLayers(rows: ThreatRow[]): {
    circles: MapCircle[];
    markers: MapMarker[];
} {
    const located = rows.filter(
        (t) => t.latitude !== null && t.longitude !== null,
    );
    const href = (ulid: string) => ThreatReportController.show(ulid).url;

    return {
        circles: located
            .filter((t) => t.radius_meters !== null)
            .map((t) => ({
                id: t.ulid,
                center: [t.latitude as number, t.longitude as number],
                radius: t.radius_meters as number,
                color: URGENCY_COLOURS[t.urgency.value],
                title: `${t.reference_no} — ${t.title}${t.shared ? ' (shared with you)' : ''}`,
                popup: `${t.threat_type.name} · ${t.urgency.label} · ${t.credibility.label}`,
                href: href(t.ulid),
                dashed: t.status.value !== 'active',
                shared: t.shared === true,
            })),
        markers: located.map((t) => ({
            id: t.ulid,
            position: [t.latitude as number, t.longitude as number],
            title: `${t.reference_no} — ${t.title}`,
            popup: `${t.threat_type.name} · ${t.urgency.label} · ${t.status.label}`,
            href: href(t.ulid),
            color: URGENCY_COLOURS[t.urgency.value],
            shape: 'triangle',
        })),
    };
}

/** Threat reports as a compact list (dashboards, tabs). */
export function ThreatList({
    rows,
    empty,
    label,
}: {
    rows: ThreatRow[];
    empty: string;
    label: string;
}) {
    if (rows.length === 0) {
        return <EmptyState title={empty} />;
    }

    return (
        <ul className="divide-y rounded-lg border" aria-label={label}>
            {rows.map((t) => (
                <li
                    key={t.ulid}
                    className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                >
                    <div className="min-w-0 space-y-0.5">
                        <Link
                            href={ThreatReportController.show(t.ulid)}
                            className="text-sm font-medium hover:underline"
                        >
                            <span className="font-mono text-xs">
                                {t.reference_no}
                            </span>{' '}
                            {t.title}
                        </Link>
                        <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                            <span>{t.threat_type.name}</span>
                            <UrgencyBadge urgency={t.urgency} />
                            <span>
                                · {t.credibility.label} · {t.status.label} ·{' '}
                                {t.lga.name}, {t.state.name} ·{' '}
                            </span>
                            <ExpiryText row={t} />
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}
