import { Link } from '@inertiajs/react';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import StatCard from '@/components/dashboard/stat-card';
import { RelevanceBadge } from '@/components/organizations/site-parts';
import type { NamedRef } from '@/types/local-government';
import type { SiteRow } from '@/types/organizations';

export type LgaOrganizationData = Record<
    'low' | 'medium' | 'high' | 'critical_infrastructure',
    number
>;

export type StateOrganizationData = {
    importantByLga: { lga: NamedRef; sites: SiteRow[] }[];
    withoutGovernmentSecurity: SiteRow[];
};

export type NationalOrganizationData = {
    state: NamedRef;
    sector: string;
    critical: number;
    high: number;
}[];

/** LGA chief: organization sites in the LGA by security relevance. */
export function LgaOrganizations({ data }: { data: LgaOrganizationData }) {
    return (
        <section className="space-y-3" aria-label="Organization sites">
            <h2 className="text-sm font-semibold">Organization sites</h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Critical infrastructure"
                    value={data.critical_infrastructure}
                    tone={
                        data.critical_infrastructure > 0 ? 'danger' : 'default'
                    }
                />
                <StatCard
                    label="High relevance"
                    value={data.high}
                    tone={data.high > 0 ? 'warning' : 'default'}
                />
                <StatCard label="Medium relevance" value={data.medium} />
                <StatCard label="Low relevance" value={data.low} />
            </div>
        </section>
    );
}

function SiteLine({ site }: { site: SiteRow }) {
    return (
        <li className="flex flex-wrap items-center gap-2">
            <Link
                href={OrganizationSiteController.show(site.ulid)}
                className="hover:underline"
            >
                {site.name}
            </Link>
            <span className="text-muted-foreground">
                {site.organization.name}
            </span>
            <RelevanceBadge relevance={site.security_relevance} />
        </li>
    );
}

/** State roles: critical and high sites by LGA; sites without government security. */
export function StateOrganizations({ data }: { data: StateOrganizationData }) {
    return (
        <section
            className="grid gap-6 lg:grid-cols-2"
            aria-label="Organization sites"
        >
            <div
                className="space-y-2 rounded-lg border p-4 text-sm"
                data-test="critical-sites"
            >
                <h2 className="font-semibold">
                    Critical infrastructure and high-relevance sites
                </h2>
                {data.importantByLga.length === 0 ? (
                    <p className="text-muted-foreground">None recorded.</p>
                ) : (
                    data.importantByLga.map((group) => (
                        <div key={group.lga.ulid} className="space-y-1">
                            <div className="font-medium">{group.lga.name}</div>
                            <ul className="ml-2 space-y-1">
                                {group.sites.map((site) => (
                                    <SiteLine key={site.ulid} site={site} />
                                ))}
                            </ul>
                        </div>
                    ))
                )}
            </div>
            <div className="space-y-2 rounded-lg border p-4 text-sm">
                <h2 className="font-semibold">
                    Sites without government security
                </h2>
                {data.withoutGovernmentSecurity.length === 0 ? (
                    <p className="text-muted-foreground">None.</p>
                ) : (
                    <ul className="space-y-1">
                        {data.withoutGovernmentSecurity.map((site) => (
                            <SiteLine key={site.ulid} site={site} />
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

/** National: high and critical sites by state and sector. */
export function NationalOrganizations({
    data,
}: {
    data: NationalOrganizationData;
}) {
    return (
        <section className="space-y-2" aria-label="Organization sites">
            <h2 className="text-sm font-semibold">
                High and critical organization sites by state and sector
            </h2>
            {data.length === 0 ? (
                <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                    None recorded.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 font-medium">
                                    Sector
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Critical
                                </th>
                                <th className="px-3 py-2 text-right font-medium">
                                    High
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {data.map((row) => (
                                <tr key={`${row.state.ulid}-${row.sector}`}>
                                    <td className="px-3 py-2">
                                        {row.state.name}
                                    </td>
                                    <td className="px-3 py-2">{row.sector}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.critical}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.high}
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
