import { Head } from '@inertiajs/react';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import type { NationalDashboardProps } from '@/types/dashboard';

/** Director General and Deputy Director General. */
export default function NationalDashboard({
    scope,
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
                <DashboardMap view={map} />
            </div>
        </>
    );
}

NationalDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
