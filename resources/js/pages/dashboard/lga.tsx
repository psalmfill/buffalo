import { Head } from '@inertiajs/react';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import { dashboard } from '@/routes';
import type { LgaDashboardProps } from '@/types/dashboard';

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{value ?? 'Not recorded'}</dd>
        </div>
    );
}

/** LGA Security Chief. */
export default function LgaDashboard({
    lga,
    map,
    placeholders,
}: LgaDashboardProps) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader title={lga.label} subtitle={lga.state} />

                <PlaceholderCardGrid cards={placeholders} />

                <div className="grid gap-4 xl:grid-cols-3">
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-3 text-sm font-medium">
                            LGA details
                        </h2>
                        <dl className="grid gap-3">
                            <Detail
                                label="Senatorial district"
                                value={lga.senatorialDistrict}
                            />
                            <Detail
                                label="Headquarters"
                                value={lga.headquarters}
                            />
                            <Detail
                                label="Area"
                                value={
                                    lga.areaKm2 === null
                                        ? null
                                        : `${lga.areaKm2} km²`
                                }
                            />
                            <Detail
                                label="Population (estimate)"
                                value={
                                    lga.populationEstimate === null
                                        ? null
                                        : lga.populationEstimate.toLocaleString(
                                              'en-NG',
                                          )
                                }
                            />
                        </dl>
                    </section>
                    <div className="xl:col-span-2">
                        <DashboardMap view={map} />
                    </div>
                </div>
            </div>
        </>
    );
}

LgaDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
