import { Link } from '@inertiajs/react';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import { cn } from '@/lib/utils';
import type { Relevance, SiteRow } from '@/types/organizations';

const RELEVANCE_STYLES: Record<Relevance, string> = {
    low: 'border-slate-400/50 bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-300',
    medium: 'border-amber-600/40 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    high: 'border-orange-600/50 bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    critical_infrastructure:
        'border-red-600/50 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-300',
};

/** Marker colours by relevance (the legend repeats them as text). */
const RELEVANCE_COLOURS: Record<Relevance, string> = {
    low: '#64748b',
    medium: '#b45309',
    high: '#c2410c',
    critical_infrastructure: '#b91c1c',
};

export function RelevanceBadge({
    relevance,
}: {
    relevance: { value: Relevance; label: string };
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded border px-1.5 py-0.5 text-xs font-medium',
                RELEVANCE_STYLES[relevance.value],
            )}
        >
            {relevance.label}
        </span>
    );
}

/** Sites with a location, coloured by security relevance, with a legend. */
export function SitesMap({
    sites,
    center,
    zoom,
    className = 'h-80',
}: {
    sites: SiteRow[];
    center?: [number, number] | null;
    zoom?: number;
    className?: string;
}) {
    const markers: MapMarker[] = sites
        .filter((s) => s.latitude !== null && s.longitude !== null)
        .map((s) => ({
            id: s.ulid,
            position: [s.latitude as number, s.longitude as number],
            title: s.name,
            popup: `${s.organization.name} · ${s.site_type.label} · ${s.security_relevance.label}`,
            href: OrganizationSiteController.show(s.ulid).url,
            color: RELEVANCE_COLOURS[s.security_relevance.value],
        }));
    const levels = [
        ...new Map(
            sites.map((s) => [
                s.security_relevance.value,
                s.security_relevance.label,
            ]),
        ).entries(),
    ];

    return (
        <div className="space-y-2">
            <BuffaloMap
                className={`${className} rounded-lg border`}
                markers={markers}
                fitMarkers={markers.length > 0}
                cluster
                {...(center ? { center, zoom } : {})}
            />
            {markers.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                    No sites with a recorded location.
                </p>
            ) : (
                <ul
                    className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                    aria-label="Map legend"
                >
                    {levels.map(([value, label]) => (
                        <li key={value} className="flex items-center gap-1.5">
                            <span
                                aria-hidden
                                className="inline-block size-3 rounded-full border border-white shadow"
                                style={{
                                    background:
                                        RELEVANCE_COLOURS[value as Relevance],
                                }}
                            />
                            {label}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/** A table of sites (the viewer's visible sites only). */
export function SitesTable({
    sites,
    showOrganization = true,
    emptyText = 'No sites that you can view.',
}: {
    sites: SiteRow[];
    showOrganization?: boolean;
    emptyText?: string;
}) {
    if (sites.length === 0) {
        return <EmptyState title="No sites" description={emptyText} />;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Site</th>
                        {showOrganization && (
                            <th className="px-3 py-2 font-medium">
                                Organization
                            </th>
                        )}
                        <th className="px-3 py-2 font-medium">Type</th>
                        <th className="px-3 py-2 font-medium">Relevance</th>
                        <th className="px-3 py-2 font-medium">LGA</th>
                        <th className="px-3 py-2 font-medium">
                            Classification
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {sites.map((s) => (
                        <tr key={s.ulid}>
                            <td className="px-3 py-2">
                                <Link
                                    href={OrganizationSiteController.show(
                                        s.ulid,
                                    )}
                                    className="font-medium hover:underline"
                                >
                                    {s.name}
                                </Link>
                                {s.status.value !== 'active' && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {s.status.label.toLowerCase()}
                                    </span>
                                )}
                            </td>
                            {showOrganization && (
                                <td className="px-3 py-2">
                                    {s.organization.name}
                                </td>
                            )}
                            <td className="px-3 py-2">{s.site_type.label}</td>
                            <td className="px-3 py-2">
                                <RelevanceBadge
                                    relevance={s.security_relevance}
                                />
                            </td>
                            <td className="px-3 py-2">
                                {s.lga.name}
                                <div className="text-xs text-muted-foreground">
                                    {s.state.name}
                                </div>
                            </td>
                            <td className="px-3 py-2">
                                <ClassificationBadge
                                    level={s.classification.value}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
