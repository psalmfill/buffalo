import { Link } from '@inertiajs/react';
import ArrestController from '@/actions/App/Http/Controllers/Arrests/ArrestController';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { formatDateTime } from '@/lib/time';
import type { ArrestRow } from '@/types/arrests';
import type { Paginated } from '@/types/pagination';

/**
 * Arrests as a table (Phase 11C): the Arrests list and the Arrests tabs on
 * person, incident, LGA and formation pages. Rows come through the
 * viewer's scopes; no names are listed.
 */
export default function ArrestsTable({
    arrests,
    empty = 'No arrests recorded, or none you may see.',
}: {
    arrests: Paginated<ArrestRow>;
    empty?: string;
}) {
    if (arrests.data.length === 0) {
        return <EmptyState title="No arrests" description={empty} />;
    }

    return (
        <div className="space-y-3">
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Reference</th>
                            <th className="px-3 py-2 font-medium">Date</th>
                            <th className="px-3 py-2 font-medium">Agency</th>
                            <th className="px-3 py-2 text-right font-medium">
                                Persons
                            </th>
                            <th className="px-3 py-2 font-medium">Offences</th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="px-3 py-2 font-medium">LGA</th>
                            <th className="px-3 py-2 font-medium">Incident</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {arrests.data.map((a) => (
                            <tr key={a.ulid} data-test="arrest-row">
                                <td className="px-3 py-2">
                                    <Link
                                        href={ArrestController.show(a.ulid)}
                                        className="font-medium underline"
                                    >
                                        {a.reference_no}
                                    </Link>{' '}
                                    {a.classification.value !==
                                        'restricted' && (
                                        <ClassificationBadge
                                            level={a.classification.value}
                                        />
                                    )}
                                </td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {formatDateTime(a.arrested_at)}
                                    {a.arrested_at_precision.value !==
                                        'exact' && (
                                        <span className="block text-xs text-muted-foreground">
                                            {a.arrested_at_precision.label}
                                        </span>
                                    )}
                                </td>
                                <td className="px-3 py-2" title={a.agency.name}>
                                    {a.agency.short_name}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {a.persons_count}
                                </td>
                                <td className="px-3 py-2">
                                    {a.offences.join(', ') || '—'}
                                </td>
                                <td className="px-3 py-2">{a.status.label}</td>
                                <td className="px-3 py-2">{a.lga.name}</td>
                                <td className="px-3 py-2">
                                    {a.incident ? (
                                        <Link
                                            href={IncidentController.show(
                                                a.incident.ulid,
                                            )}
                                            className="underline"
                                        >
                                            {a.incident.reference_no ??
                                                a.incident.title}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination page={arrests} />
        </div>
    );
}
