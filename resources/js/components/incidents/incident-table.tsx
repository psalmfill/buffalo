import { Link } from '@inertiajs/react';
import { Siren } from 'lucide-react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { IncidentListRow } from '@/types/incidents';

export const SEVERITY_COLOURS: Record<string, string> = {
    critical: '#b91c1c',
    high: '#ea580c',
    medium: '#ca8a04',
    low: '#2563eb',
};

export function SeverityBadge({
    severity,
}: {
    severity: { value: string; label: string };
}) {
    return (
        <span className="inline-flex items-center gap-1.5 text-sm">
            <span
                aria-hidden
                className="inline-block size-2.5 rounded-full"
                style={{
                    background: SEVERITY_COLOURS[severity.value] ?? '#64748b',
                }}
            />
            {severity.label}
        </span>
    );
}

/**
 * Incidents as a table (Phase 5D): the list, the Incidents tabs on other
 * records and the dashboards. Only what the server sent: rows the viewer
 * may see, with no descriptions.
 */
export default function IncidentTable({
    rows,
    showState = false,
    showInvolvement = false,
    compact = false,
    empty = 'No incidents.',
}: {
    rows: IncidentListRow[];
    showState?: boolean;
    showInvolvement?: boolean;
    compact?: boolean;
    empty?: string;
}) {
    if (rows.length === 0) {
        return <EmptyState title={empty} />;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Reference</th>
                        <th className="px-3 py-2 font-medium">Incident</th>
                        <th className="px-3 py-2 font-medium">
                            {showState ? 'Place' : 'LGA'}
                        </th>
                        <th className="px-3 py-2 font-medium">Occurred</th>
                        <th className="px-3 py-2 font-medium">Severity</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        {!compact && (
                            <th className="px-3 py-2 font-medium">
                                Casualties
                            </th>
                        )}
                        {showInvolvement && (
                            <th className="px-3 py-2 font-medium">
                                Involvement
                            </th>
                        )}
                        {!compact && (
                            <th className="px-3 py-2 font-medium">
                                Reported by
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((row) => (
                        <tr
                            key={row.ulid}
                            className={cn(
                                row.overdue &&
                                    'bg-amber-50 dark:bg-amber-950/40',
                            )}
                        >
                            <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                <Link
                                    href={IncidentController.show(row.ulid)}
                                    className="hover:underline"
                                >
                                    {row.reference_no ?? 'Draft'}
                                </Link>
                            </td>
                            <td className="px-3 py-2">
                                <Link
                                    href={IncidentController.show(row.ulid)}
                                    className="font-medium hover:underline"
                                >
                                    {row.title}
                                </Link>
                                <span className="block text-xs text-muted-foreground">
                                    {row.type.name}
                                </span>
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {row.lga.name}
                                {showState && (
                                    <span className="block text-xs text-muted-foreground">
                                        {row.state.name}
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {formatDateTime(row.occurred_at)}
                                {row.overdue && (
                                    <span className="block text-xs font-medium text-amber-700 dark:text-amber-300">
                                        Verification overdue
                                    </span>
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                <SeverityBadge severity={row.severity} />
                            </td>
                            <td className="px-3 py-2">
                                <span className="flex flex-wrap items-center gap-1">
                                    <Badge variant="secondary">
                                        {row.status.label}
                                    </Badge>
                                    {row.escalation && (
                                        <Badge
                                            variant={
                                                row.escalation.acknowledged
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                            className="gap-1"
                                        >
                                            <Siren className="size-3" />
                                            {row.escalation.acknowledged
                                                ? 'Escalated'
                                                : 'Escalated, unacknowledged'}
                                        </Badge>
                                    )}
                                </span>
                            </td>
                            {!compact && (
                                <td className="px-3 py-2 text-xs">
                                    {row.casualties || '—'}
                                </td>
                            )}
                            {showInvolvement && (
                                <td className="px-3 py-2">
                                    {row.involvement ?? '—'}
                                </td>
                            )}
                            {!compact && (
                                <td className="px-3 py-2 text-xs">
                                    {row.reporting_officer ?? '—'}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
