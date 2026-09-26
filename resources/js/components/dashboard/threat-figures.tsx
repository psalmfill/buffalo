import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import StatCard from '@/components/dashboard/stat-card';
import EmptyState from '@/components/empty-state';
import type { Option } from '@/types/local-government';

export type RiskCount = Option & { total: number };
export type PoiPlaceRow = {
    name: string;
    total: number;
    byRisk: Record<string, number>;
};

/** Open persons of interest by risk level, plus overdue reviews (LGA dashboard). */
export function PoiRiskCards({
    byRisk,
    overdue,
}: {
    byRisk: RiskCount[];
    overdue: number;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {byRisk.map((risk) => (
                <StatCard
                    key={risk.value}
                    label={`${risk.label} risk POIs`}
                    value={risk.total}
                    tone={
                        risk.value === 'critical' && risk.total > 0
                            ? 'danger'
                            : risk.value === 'high' && risk.total > 0
                              ? 'warning'
                              : 'default'
                    }
                    href={PersonOfInterestController.index({
                        query: { risk: risk.value },
                    })}
                />
            ))}
            <StatCard
                label="POI reviews overdue"
                value={overdue}
                tone={overdue > 0 ? 'warning' : 'default'}
                href={PersonOfInterestController.index({
                    query: { overdue: '1' },
                })}
            />
        </div>
    );
}

/** Open persons of interest per LGA or state, by risk level. */
export function PoiByPlaceTable({
    title,
    place,
    rows,
    riskLevels,
}: {
    title: string;
    place: string;
    rows: PoiPlaceRow[];
    riskLevels: Option[];
}) {
    return (
        <section className="rounded-xl border bg-card">
            <h2 className="border-b px-4 py-3 text-sm font-medium">{title}</h2>
            {rows.length === 0 ? (
                <EmptyState
                    title="No open persons of interest"
                    description="None recorded within your jurisdiction."
                />
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-2 font-medium">
                                    {place}
                                </th>
                                {riskLevels.map((risk) => (
                                    <th
                                        key={risk.value}
                                        className="px-4 py-2 text-right font-medium"
                                    >
                                        {risk.label}
                                    </th>
                                ))}
                                <th className="px-4 py-2 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((row) => (
                                <tr key={row.name}>
                                    <td className="px-4 py-2">{row.name}</td>
                                    {riskLevels.map((risk) => (
                                        <td
                                            key={risk.value}
                                            className="px-4 py-2 text-right tabular-nums"
                                        >
                                            {row.byRisk[risk.value] ?? 0}
                                        </td>
                                    ))}
                                    <td className="px-4 py-2 text-right font-medium tabular-nums">
                                        {row.total}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </section>
    );
}

/** Active criminal groups by type (national dashboard). */
export function GroupsByType({ rows }: { rows: RiskCount[] }) {
    return (
        <section className="rounded-xl border bg-card">
            <h2 className="border-b px-4 py-3 text-sm font-medium">
                Active criminal groups by type
            </h2>
            {rows.length === 0 ? (
                <EmptyState
                    title="No active groups"
                    description="None recorded within your jurisdiction."
                />
            ) : (
                <ul className="divide-y text-sm">
                    {rows.map((row) => (
                        <li
                            key={row.value}
                            className="flex justify-between px-4 py-2"
                        >
                            <span>{row.label}</span>
                            <span className="font-medium tabular-nums">
                                {row.total}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
