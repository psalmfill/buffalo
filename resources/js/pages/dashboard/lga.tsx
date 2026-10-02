import { Head, Link } from '@inertiajs/react';
import { LgaThreats } from '@/components/dashboard/threat-report-figures';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import AccessFigures from '@/components/dashboard/access-figures';
import { LgaOrganizations } from '@/components/dashboard/organization-figures';
import { LgaCoverage } from '@/components/dashboard/coverage-figures';
import DashboardHeader from '@/components/dashboard/dashboard-header';
import { KpiTiles, PeriodSelector } from '@/components/dashboard/kpi-tiles';
import { SharedWithUs } from '@/components/dashboard/dissemination-figures';
import { LgaIncidents } from '@/components/dashboard/incident-figures';
import DashboardMap from '@/components/dashboard/dashboard-map';
import { PlaceholderCardGrid } from '@/components/dashboard/placeholder-card';
import StatCard from '@/components/dashboard/stat-card';
import { PoiRiskCards } from '@/components/dashboard/threat-figures';
import CompletenessBar from '@/components/local-government/completeness-bar';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/time';
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
    period,
    kpis,
    incidents,
    threatReports,
    sharedWithUs,
    lga,
    profile,
    information,
    threats,
    map,
    placeholders,
    access,
    coverage,
    organizations,
}: LgaDashboardProps) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-col gap-6 p-4">
                <DashboardHeader title={lga.label} subtitle={lga.state} />
                <PeriodSelector period={period} />
                <KpiTiles tiles={kpis} />

                {sharedWithUs && <SharedWithUs data={sharedWithUs} />}
                {threatReports && <LgaThreats data={threatReports} />}
                {incidents && <LgaIncidents data={incidents} />}

                <section
                    className="flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-4"
                    data-test="profile-card"
                >
                    <div className="space-y-1">
                        <h2 className="text-sm font-medium">LGA profile</h2>
                        <CompletenessBar percent={profile.completeness} />
                        <p className="text-xs text-muted-foreground">
                            {profile.missing.length > 0
                                ? `Missing: ${profile.missing.join(', ')}.`
                                : 'Complete.'}{' '}
                            {profile.last_reviewed_at
                                ? `Last reviewed ${formatDateTime(profile.last_reviewed_at)}.`
                                : 'Never reviewed.'}
                        </p>
                    </div>
                    <StatCard
                        label="Communities"
                        value={profile.communities}
                        href={LgaController.show(lga.ulid, {
                            query: { tab: 'communities' },
                        })}
                    />
                    {profile.completeness < 100 && (
                        <Button asChild>
                            <Link
                                href={LgaController.show(lga.ulid, {
                                    query: { tab: 'profile' },
                                })}
                            >
                                Complete the profile
                            </Link>
                        </Button>
                    )}
                </section>

                <div className="grid gap-4 sm:grid-cols-2">
                    <StatCard
                        label="Active security notes"
                        value={information.activeSecurityNotes}
                        tone={
                            information.activeSecurityNotes > 0
                                ? 'warning'
                                : 'default'
                        }
                        href={LgaController.show(lga.ulid, {
                            query: { tab: 'security_notes', status: 'active' },
                        })}
                    />
                    <StatCard
                        label="Religious institutions"
                        value={information.religiousInstitutions}
                        href={LgaController.show(lga.ulid, {
                            query: { tab: 'religious_institutions' },
                        })}
                    />
                </div>

                {threats && (
                    <PoiRiskCards
                        byRisk={threats.poisByRisk}
                        overdue={threats.overdueReviews}
                    />
                )}

                {coverage && <LgaCoverage data={coverage} />}
                {organizations && <LgaOrganizations data={organizations} />}
                {access && <AccessFigures data={access} />}

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
                        <DashboardMap
                            view={map}
                            mode="lga"
                            days={period.days}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

LgaDashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
