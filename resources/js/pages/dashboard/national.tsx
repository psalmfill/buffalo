import { Head } from '@inertiajs/react';
import {
    NationalImmediateThreats,
    NationalThreatsByState,
} from '@/components/dashboard/threat-report-figures';
import AccessFigures from '@/components/dashboard/access-figures';
import { NationalOrganizations } from '@/components/dashboard/organization-figures';
import { NationalCoverage } from '@/components/dashboard/coverage-figures';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import { KpiTiles, PeriodSelector } from '@/components/dashboard/kpi-tiles';
import { CrossBorderGaps } from '@/components/dashboard/dissemination-figures';
import { NationalIncidents } from '@/components/dashboard/incident-figures';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import {
    GroupsByType,
    PoiByPlaceTable,
} from '@/components/dashboard/threat-figures';
import EmptyState from '@/components/empty-state';
import CompletenessBar from '@/components/local-government/completeness-bar';
import { Badge } from '@/components/ui/badge';
import { dashboard } from '@/routes';
import type { NationalDashboardProps } from '@/types/dashboard';

/** Director General and Deputy Director General. */
export default function NationalDashboard({
    period,
    kpis,
    incidents,
    threatReports,
    crossBorder,
    scope,
    completenessByState,
    securityNotesByState,
    threats,
    map,
    placeholders,
    access,
    coverage,
    organizations,
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
                <PeriodSelector period={period} />
                <KpiTiles tiles={kpis} />

                {threatReports && (
                    <NationalImmediateThreats data={threatReports} />
                )}
                {crossBorder && <CrossBorderGaps data={crossBorder} />}

                <div className="grid gap-4 xl:grid-cols-[2fr_1fr]">
                    <PoiByPlaceTable
                        title="Open persons of interest by state and risk"
                        place="State"
                        rows={threats.poisByState}
                        riskLevels={threats.riskLevels}
                    />

                    {incidents && <NationalIncidents data={incidents} />}
                    <GroupsByType rows={threats.groupsByType} />
                </div>

                {threatReports && (
                    <NationalThreatsByState data={threatReports} />
                )}
                {coverage && <NationalCoverage data={coverage} />}
                {organizations && (
                    <NationalOrganizations data={organizations} />
                )}
                {access && <AccessFigures data={access} />}

                <PlaceholderCardGrid cards={placeholders} />

                <section className="rounded-xl border bg-card">
                    <h2 className="border-b px-4 py-3 text-sm font-medium">
                        Active security notes by state and category
                    </h2>
                    {securityNotesByState.rows.length === 0 ? (
                        <EmptyState
                            title="No active security notes"
                            description="None that you may see."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            State
                                        </th>
                                        {securityNotesByState.categories.map(
                                            (category) => (
                                                <th
                                                    key={category.value}
                                                    className="px-4 py-2 text-right font-medium"
                                                >
                                                    {category.label}
                                                </th>
                                            ),
                                        )}
                                        <th className="px-4 py-2 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {securityNotesByState.rows.map((row) => (
                                        <tr key={row.state}>
                                            <td className="px-4 py-2">
                                                {row.state}
                                            </td>
                                            {securityNotesByState.categories.map(
                                                (category) => (
                                                    <td
                                                        key={category.value}
                                                        className="px-4 py-2 text-right tabular-nums"
                                                    >
                                                        {row.byCategory[
                                                            category.value
                                                        ] ?? '—'}
                                                    </td>
                                                ),
                                            )}
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

                <div className="grid gap-4 xl:grid-cols-2">
                    <DashboardMap
                        view={map}
                        mode="national"
                        days={period.days}
                    />
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
