import { Head, Link } from '@inertiajs/react';
import { StateThreats } from '@/components/dashboard/threat-report-figures';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import PersonDuplicateController from '@/actions/App/Http/Controllers/Persons/PersonDuplicateController';
import PersonMergeRequestController from '@/actions/App/Http/Controllers/Persons/PersonMergeRequestController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import AccessFigures from '@/components/dashboard/access-figures';
import { StateOrganizations } from '@/components/dashboard/organization-figures';
import { StateCoverage } from '@/components/dashboard/coverage-figures';
import { BackToNational } from '@/components/dashboard/back-to-national';
import { StateArrests } from '@/components/dashboard/arrest-figures';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import { KpiTiles, PeriodSelector } from '@/components/dashboard/kpi-tiles';
import { SharedWithUs } from '@/components/dashboard/dissemination-figures';
import { StateIncidents } from '@/components/dashboard/incident-figures';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import StatCard from '@/components/dashboard/stat-card';
import { PoiByPlaceTable } from '@/components/dashboard/threat-figures';
import EmptyState from '@/components/empty-state';
import CompletenessBar from '@/components/local-government/completeness-bar';
import { useAuth } from '@/hooks/use-auth';
import { formatDateTime } from '@/lib/time';
import { dashboard } from '@/routes';
import type { StateDashboardProps } from '@/types/dashboard';

/** State Director and Deputy State Director. */
export default function StateDashboard({
    place,
    period,
    kpis,
    incidents,
    arrests,
    threatReports,
    sharedWithUs,
    state,
    lgas,
    securityNotesByLga,
    vulnerableTotals,
    threats,
    map,
    placeholders,
    access,
    coverage,
    organizations,
    duplicates,
}: StateDashboardProps) {
    const { can } = useAuth();
    const vacant = lgas.filter((lga) => lga.chief === null).length;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader
                    title={state.label}
                    subtitle={[
                        state.zone ? `${state.zone} Zone` : null,
                        `${lgas.length} LGAs`,
                        `${vacant} without an LGA Security Chief`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                >
                    {/* A national user's view of this state (Phase 11A.2). */}
                    {place && <BackToNational />}
                </DashboardHeader>
                <PeriodSelector period={period} analyticsPlace={place} />
                <KpiTiles tiles={kpis} />

                {sharedWithUs && <SharedWithUs data={sharedWithUs} />}
                {threatReports && <StateThreats data={threatReports} />}
                {incidents && <StateIncidents data={incidents} />}
                {arrests && <StateArrests data={arrests} />}

                {(can('poi.view') || can('criminal_groups.view')) && (
                    <div className="grid gap-4 xl:grid-cols-[2fr_1fr]">
                        {can('poi.view') && (
                            <PoiByPlaceTable
                                title="Open persons of interest by LGA and risk"
                                place="LGA"
                                rows={threats.poisByLga}
                                riskLevels={threats.riskLevels}
                            />
                        )}
                        <div className="grid content-start gap-4">
                            {can('poi.view') && (
                                <StatCard
                                    label="POI reviews overdue"
                                    value={threats.overdueReviews}
                                    tone={
                                        threats.overdueReviews > 0
                                            ? 'warning'
                                            : 'default'
                                    }
                                    href={PersonOfInterestController.index({
                                        query: { overdue: '1' },
                                    })}
                                />
                            )}
                            {can('criminal_groups.view') && (
                                <StatCard
                                    label="Active criminal groups"
                                    value={threats.activeGroups}
                                    href={CriminalGroupController.index({
                                        query: { status: 'active' },
                                    })}
                                />
                            )}
                        </div>
                    </div>
                )}

                {duplicates && (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <StatCard
                            label="Possible duplicate persons"
                            value={duplicates.nightly?.total ?? 0}
                            tone={
                                (duplicates.nightly?.certain ?? 0) > 0
                                    ? 'warning'
                                    : 'default'
                            }
                            href={PersonDuplicateController.index()}
                        />
                        <StatCard
                            label="Merge requests waiting"
                            value={duplicates.pendingRequests}
                            tone={
                                duplicates.pendingRequests > 0
                                    ? 'warning'
                                    : 'default'
                            }
                            href={PersonMergeRequestController.index()}
                        />
                    </div>
                )}

                {coverage && <StateCoverage data={coverage} />}
                {organizations && <StateOrganizations data={organizations} />}
                {access && <AccessFigures data={access} />}

                <PlaceholderCardGrid cards={placeholders} />

                <div className="grid gap-4 xl:grid-cols-2">
                    <section className="rounded-xl border bg-card">
                        <h2 className="border-b px-4 py-3 text-sm font-medium">
                            Active security notes by LGA
                        </h2>
                        {securityNotesByLga.length === 0 ? (
                            <EmptyState
                                title="No active security notes"
                                description="None that you may see."
                            />
                        ) : (
                            <ul className="divide-y text-sm">
                                {securityNotesByLga.map((row) => (
                                    <li
                                        key={row.ulid}
                                        className="flex items-center justify-between px-4 py-2"
                                    >
                                        <Link
                                            href={LgaController.show(row.ulid, {
                                                query: {
                                                    tab: 'security_notes',
                                                    status: 'active',
                                                },
                                            })}
                                            className="hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                        <span className="font-medium tabular-nums">
                                            {row.active}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <section className="rounded-xl border bg-card">
                        <h2 className="border-b px-4 py-3 text-sm font-medium">
                            Vulnerable population (latest LGA-wide figures)
                        </h2>
                        {vulnerableTotals.length === 0 ? (
                            <EmptyState
                                title="No figures recorded"
                                description="LGA-wide figures appear here once recorded."
                            />
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="px-4 py-2 font-medium">
                                            Category
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Adults
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Children
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {vulnerableTotals.map((row) => (
                                        <tr key={row.category}>
                                            <td className="px-4 py-2">
                                                {row.label}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {(
                                                    row.adult_male +
                                                    row.adult_female
                                                ).toLocaleString('en-NG')}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {(
                                                    row.child_male +
                                                    row.child_female
                                                ).toLocaleString('en-NG')}
                                            </td>
                                            <td className="px-4 py-2 text-right font-medium tabular-nums">
                                                {row.total.toLocaleString(
                                                    'en-NG',
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </section>
                </div>

                <div className="grid gap-4 xl:grid-cols-2">
                    <DashboardMap view={map} mode="state" days={period.days} />

                    <section className="rounded-xl border bg-card">
                        <h2 className="border-b px-4 py-3 text-sm font-medium">
                            LGAs: Security Chiefs and profiles (least complete
                            first)
                        </h2>
                        {lgas.length === 0 ? (
                            <EmptyState
                                title="No LGAs"
                                description="LGAs for this state have not been imported yet."
                            />
                        ) : (
                            <div className="max-h-[360px] overflow-y-auto">
                                <table className="w-full text-sm">
                                    <thead className="sticky top-0 bg-muted/80 text-left backdrop-blur">
                                        <tr>
                                            <th className="px-4 py-2 font-medium">
                                                LGA
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Headquarters
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Security Chief
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Profile
                                            </th>
                                            <th className="px-4 py-2 font-medium">
                                                Last reviewed
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {lgas.map((lga) => (
                                            <tr key={lga.ulid}>
                                                <td className="px-4 py-2">
                                                    <Link
                                                        href={LgaController.show(
                                                            lga.ulid,
                                                        )}
                                                        className="hover:underline"
                                                    >
                                                        {lga.name}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-2 text-muted-foreground">
                                                    {lga.headquarters ?? '—'}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {lga.chief === null ? (
                                                        <span className="text-amber-700 dark:text-amber-400">
                                                            Vacant
                                                        </span>
                                                    ) : can('users.view') ? (
                                                        <Link
                                                            href={UserController.show(
                                                                lga.chief.ulid,
                                                            )}
                                                            className="hover:underline"
                                                        >
                                                            {lga.chief.name}
                                                        </Link>
                                                    ) : (
                                                        lga.chief.name
                                                    )}
                                                </td>
                                                <td className="px-4 py-2">
                                                    <CompletenessBar
                                                        percent={
                                                            lga.completeness
                                                        }
                                                    />
                                                </td>
                                                <td className="px-4 py-2 text-muted-foreground">
                                                    {lga.last_reviewed_at
                                                        ? formatDateTime(
                                                              lga.last_reviewed_at,
                                                          )
                                                        : 'Never'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

StateDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
