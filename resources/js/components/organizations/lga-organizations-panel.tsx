import { router } from '@inertiajs/react';
import NativeSelect from '@/components/native-select';
import { SitesMap, SitesTable } from '@/components/organizations/site-parts';
import { Label } from '@/components/ui/label';
import type { MapView } from '@/types/dashboard';
import type { Option } from '@/types/local-government';
import type { SiteRow } from '@/types/organizations';

export type LgaOrganizationsTab = {
    sites: SiteRow[];
    relevance: string;
    relevanceOptions: Option[];
    map: MapView;
};

/** The LGA page's "Organizations" tab: organization sites in the LGA. */
export default function LgaOrganizationsPanel({
    baseUrl,
    data,
}: {
    baseUrl: string;
    data: LgaOrganizationsTab;
}) {
    const filter = (relevance: string) =>
        router.get(baseUrl, relevance === '' ? {} : { relevance }, {
            preserveScroll: true,
            preserveState: true,
            only: ['organizations'],
        });

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end justify-between gap-4">
                <p className="text-sm text-muted-foreground">
                    Sites of companies and organizations located in this LGA.
                </p>
                <div className="grid gap-1">
                    <Label htmlFor="filter-relevance">Security relevance</Label>
                    <NativeSelect
                        id="filter-relevance"
                        value={data.relevance}
                        onChange={(e) => filter(e.target.value)}
                    >
                        <option value="">Any</option>
                        {data.relevanceOptions.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
            </div>
            <SitesMap
                sites={data.sites}
                center={data.map.center}
                zoom={data.map.zoom}
                outline={data.map.outline}
            />
            <SitesTable
                sites={data.sites}
                emptyText="No sites in this LGA that you can view."
            />
        </div>
    );
}
