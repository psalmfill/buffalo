import { Head } from '@inertiajs/react';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import CompletenessBar from '@/components/local-government/completeness-bar';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import type { NationalDashboardProps } from '@/types/dashboard';

/** Director General and Deputy Director General. */
export default function NationalDashboard({
    scope,
    completenessByState,
    map,
    placeholders,
}: NationalDashboardProps) {
    const zoned = scope.zones.length > 0;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader
                    title={zoned ? 'Zonal overview' : 'National overview'}
                    subtitle={`${scope.states} states · ${scope.lgas} LGAs`}
                >
                    {zoned && (
                        <div
                            className="flex flex-wrap items-center gap-2 pt-1 text-sm"
                            data-test="assigned-zones"
                        >
                            <span className="text-muted-foreground">
                                Assigned zones:
                            </span>
                            {scope.zones.map((zone) => (
                                <Badge key={zone} variant="secondary">
                                    {zone} Zone
                                </Badge>
                            ))}
                        </div>
                    )}
                </DashboardHeader>

                <PlaceholderCardGrid cards={placeholders} />

                <div className="grid gap-4 xl:grid-cols-2">
                    <DashboardMap view={map} />
                    <section className="rounded-xl border bg-card">
                        <h2 className="border-b px-4 py-3 text-sm font-medium">
                            LGA profile completeness by state (lowest first)
                        </h2>
                        <div className="max-h-[360px] overflow-y-auto">
                            <table className="w-full text-sm">
                                <thead className="sticky top-0 bg-muted/80 text-left backdrop-blur">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            State
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            LGAs
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Average completeness
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {completenessByState.map((row) => (
                                        <tr key={row.state}>
                                            <td className="px-4 py-2">
                                                {row.state}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {row.lgas}
                                            </td>
                                            <td className="px-4 py-2">
                                                <CompletenessBar
                                                    percent={row.average}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

NationalDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
