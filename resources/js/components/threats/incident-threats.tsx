import { Link } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import { StatusDialog } from '@/components/threats/status-dialogs';
import { UrgencyBadge } from '@/components/threats/threat-display';
import { Button } from '@/components/ui/button';
import type { IncidentThreats, ThreatRow } from '@/types/threats';

/**
 * Threat reports on an incident page (Phase 6B): those linked to it, and
 * active ones recorded as possibly related when it was submitted. Only
 * reports the viewer can see are sent; "Mark as materialised" only where
 * the viewer may manage the report.
 */
export default function IncidentThreatsSection({
    data,
    incident,
}: {
    data: IncidentThreats;
    incident: { ulid: string; label: string };
}) {
    const [materialising, setMaterialising] = useState<string | null>(null);

    if (data.linked.length + data.possible.length === 0) {
        return null;
    }

    const item = (t: ThreatRow, extra: string | null, action: boolean) => (
        <li
            key={t.ulid}
            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
        >
            <div className="space-y-0.5">
                <Link
                    href={ThreatReportController.show(t.ulid)}
                    className="text-sm font-medium hover:underline"
                >
                    <span className="font-mono text-xs">{t.reference_no}</span>{' '}
                    {t.title}
                </Link>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span>{t.threat_type.name}</span>
                    <UrgencyBadge urgency={t.urgency} />
                    <span>
                        · {t.credibility.label} · {t.status.label}
                        {extra ? ` · ${extra}` : ''}
                    </span>
                </p>
            </div>
            {action && (
                <Button size="sm" onClick={() => setMaterialising(t.ulid)}>
                    Mark as materialised
                </Button>
            )}
        </li>
    );

    return (
        <section
            className="space-y-3 rounded-lg border border-red-600/30 p-4"
            aria-label="Threat reports"
        >
            <h2 className="flex items-center gap-2 text-sm font-semibold">
                <TriangleAlert className="size-4 text-red-700" />
                Threat reports
            </h2>
            {data.possible.length > 0 && (
                <div className="space-y-1" data-test="possibly-related-threats">
                    <h3 className="text-xs text-muted-foreground">
                        Possibly related threat reports
                    </h3>
                    <ul className="divide-y rounded-md border">
                        {data.possible.map((t) =>
                            item(t, null, t.can_materialise),
                        )}
                    </ul>
                </div>
            )}
            {data.linked.length > 0 && (
                <div className="space-y-1">
                    <h3 className="text-xs text-muted-foreground">Linked</h3>
                    <ul className="divide-y rounded-md border">
                        {data.linked.map((t) =>
                            item(t, t.relation.label, false),
                        )}
                    </ul>
                </div>
            )}
            {materialising && (
                <StatusDialog
                    threatUlid={materialising}
                    status="materialised"
                    incident={{ value: incident.ulid, label: incident.label }}
                    onClose={() => setMaterialising(null)}
                />
            )}
        </section>
    );
}

/** Header badge: "Possibly related threat report: THR/…" (first one). */
export function PossibleThreatBadge({
    data,
}: {
    data: IncidentThreats | null;
}) {
    if (!data || data.possible.length === 0) {
        return null;
    }

    const more = data.possible.length - 1;

    return (
        <span
            className="inline-flex items-center gap-1 rounded border border-red-600/40 bg-red-50 px-1.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-950 dark:text-red-300"
            data-test="possible-threat"
        >
            <TriangleAlert className="size-3" />
            Possibly related threat report: {data.possible[0].reference_no}
            {more > 0 ? ` (+${more})` : ''}
        </span>
    );
}
