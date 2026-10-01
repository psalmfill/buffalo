import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo } from 'react';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import Heading from '@/components/heading';
import BuffaloMap from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import {
    ExpiryText,
    URGENCY_COLOURS,
    UrgencyBadge,
    expectedWindow,
    threatMapLayers,
} from '@/components/threats/threat-display';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { MapView } from '@/types/dashboard';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { ThreatOptions, ThreatRow, ThreatUrgency } from '@/types/threats';

type Filters = {
    status: string;
    threat_type: string;
    credibility: string;
    urgency: string;
    state: string;
    lga: string;
    expiring: string;
};

/** Threat reports within the user's jurisdiction: active ones by default. */
export default function ThreatsIndex({
    threats,
    points,
    filters,
    options,
    states,
    lgas,
    map,
    can,
}: {
    threats: Paginated<ThreatRow>;
    points: ThreatRow[];
    filters: Filters;
    options: ThreatOptions;
    states: Option[];
    lgas: Option[];
    map: MapView;
    can: { create: boolean };
}) {
    const { values, apply, reset } = useFilters(
        ThreatReportController.index().url,
        filters,
    );
    const layers = useMemo(() => threatMapLayers(points), [points]);

    const select = (
        field: keyof Filters,
        label: string,
        items: Option[],
        all: string | null,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <NativeSelect
                id={`filter-${field}`}
                value={values[field]}
                onChange={(e) =>
                    apply(
                        field === 'state'
                            ? { state: e.target.value, lga: '' }
                            : { [field]: e.target.value },
                    )
                }
            >
                {all !== null && <option value="">{all}</option>}
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    return (
        <>
            <Head title="Threat reports" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Threat reports"
                        description="Early warnings of what may be about to happen, most urgent first."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={ThreatReportController.create()}>
                                <Plus /> Record threat report
                            </Link>
                        </Button>
                    )}
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    {select(
                        'status',
                        'Status',
                        [
                            ...options.statuses,
                            { value: 'any', label: 'Any status' },
                        ],
                        null,
                    )}
                    {select(
                        'threat_type',
                        'Threat type',
                        options.threatTypes,
                        'All threat types',
                    )}
                    {select('urgency', 'Urgency', options.urgencies, 'Any')}
                    {select(
                        'credibility',
                        'Credibility',
                        options.credibilities,
                        'Any',
                    )}
                    {states.length > 0 &&
                        select('state', 'State', states, 'All states')}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    {select(
                        'expiring',
                        'Expiry',
                        [{ value: '1', label: 'Expiring within 48 hours' }],
                        'Any',
                    )}
                </FilterBar>

                <div className="space-y-2">
                    <BuffaloMap
                        className="h-96 w-full rounded-lg border"
                        center={map.center ?? undefined}
                        zoom={map.zoom}
                        outline={map.outline}
                        circles={layers.circles}
                        markers={layers.markers}
                        fitMarkers={layers.markers.length > 0}
                    />
                    <ul
                        className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
                        aria-label="Map legend"
                    >
                        {(Object.keys(URGENCY_COLOURS) as ThreatUrgency[]).map(
                            (u) => (
                                <li
                                    key={u}
                                    className="inline-flex items-center gap-1.5"
                                >
                                    <span
                                        aria-hidden
                                        className="inline-block size-2.5 rounded-full"
                                        style={{
                                            background: URGENCY_COLOURS[u],
                                        }}
                                    />
                                    <span className="capitalize">{u}</span>
                                </li>
                            ),
                        )}
                        <li>Reports without a location are not on the map.</li>
                    </ul>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    Reference
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Threat
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Urgency
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Credibility
                                </th>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">
                                    Expected
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {threats.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="p-0">
                                        <EmptyState
                                            title="No threat reports"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {threats.data.map((t) => (
                                <tr key={t.ulid}>
                                    <td className="px-3 py-2 font-mono text-xs whitespace-nowrap">
                                        <Link
                                            href={ThreatReportController.show(
                                                t.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {t.reference_no}
                                        </Link>
                                    </td>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={ThreatReportController.show(
                                                t.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {t.title}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {t.threat_type.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <UrgencyBadge urgency={t.urgency} />
                                    </td>
                                    <td className="px-3 py-2">
                                        {t.credibility.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {t.lga.name}
                                        <div className="text-xs text-muted-foreground">
                                            {t.state.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 text-xs">
                                        {expectedWindow(t) ?? '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {t.status.label}
                                        <div className="text-xs text-muted-foreground">
                                            <ExpiryText row={t} />
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={t.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={threats} />
            </div>
        </>
    );
}

ThreatsIndex.layout = {
    breadcrumbs: [
        { title: 'Threat reports', href: ThreatReportController.index() },
    ],
};
