import { Head, Link } from '@inertiajs/react';
import ExportListButton from '@/components/exports/export-list-button';
import { Plus } from 'lucide-react';
import { useMemo } from 'react';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import { FilterBar, useFilters } from '@/components/filter-bar';
import ConcernBadge, {
    ConcernLegend,
} from '@/components/flashpoints/concern-badge';
import {
    flashpointCircles,
    formatRadius,
} from '@/components/flashpoints/flashpoint-areas';
import Heading from '@/components/heading';
import BuffaloMap from '@/components/map/BuffaloMap';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/time';
import type { MapView } from '@/types/dashboard';
import SharedMarker from '@/components/disseminations/shared-marker';
import type { FlashpointOptions, FlashpointRow } from '@/types/flashpoints';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';

type Filters = {
    q: string;
    kind: string;
    concern: string;
    status: string;
    threat_type: string;
    state: string;
    lga: string;
    overdue: string;
    shared: string;
};

/** Flashpoints and blackspots within the user's jurisdiction, with a map. */
export default function FlashpointsIndex({
    flashpoints,
    areas,
    filters,
    options,
    states,
    lgas,
    map,
    can,
}: {
    flashpoints: Paginated<FlashpointRow>;
    areas: FlashpointRow[];
    filters: Filters;
    options: FlashpointOptions;
    states: Option[];
    lgas: Option[];
    map: MapView;
    can: { create: boolean };
}) {
    const { values, setValues, apply, reset } = useFilters(
        FlashpointController.index().url,
        filters,
    );
    const circles = useMemo(
        () =>
            flashpointCircles(
                areas,
                (ulid) => FlashpointController.show(ulid).url,
            ),
        [areas],
    );

    const select = (
        field: keyof Filters,
        label: string,
        items: Option[],
        all: string,
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
                <option value="">{all}</option>
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
            <Head title="Flashpoints" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        help={{ topic: 'guides/flashpoints' }}
                        title="Flashpoints and blackspots"
                        description="Flashpoints are places where tension could erupt into violence; blackspots are places where incidents repeatedly happen."
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportListButton
                            list="flashpoints"
                            filters={{ ...filters }}
                        />
                        {can.create && (
                            <Button asChild>
                                <Link href={FlashpointController.create()}>
                                    <Plus /> Record flashpoint
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <FilterBar onApply={() => apply()} onReset={reset}>
                    <div className="grid gap-2">
                        <Label htmlFor="filter-q">Name</Label>
                        <Input
                            id="filter-q"
                            value={values.q}
                            onChange={(e) =>
                                setValues({ ...values, q: e.target.value })
                            }
                        />
                    </div>
                    {select('kind', 'Kind', options.kinds, 'Both')}
                    {select(
                        'threat_type',
                        'Threat type',
                        options.threatTypes,
                        'All threat types',
                    )}
                    {select('concern', 'Concern', options.concernLevels, 'Any')}
                    {select('status', 'Status', options.statuses, 'Any')}
                    {states.length > 0 &&
                        select('state', 'State', states, 'All states')}
                    {lgas.length > 0 && select('lga', 'LGA', lgas, 'All LGAs')}
                    {select(
                        'overdue',
                        'Review',
                        [{ value: '1', label: 'Review overdue' }],
                        'Any',
                    )}
                    {select(
                        'shared',
                        'Origin',
                        [{ value: '1', label: 'Shared with us' }],
                        'All',
                    )}
                </FilterBar>

                <div className="space-y-2">
                    <BuffaloMap
                        className="h-96 w-full rounded-lg border"
                        center={map.center ?? undefined}
                        zoom={map.zoom}
                        outline={map.outline}
                        circles={circles}
                        fitCircles={circles.length > 0}
                    />
                    <ConcernLegend />
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">
                                    Threat
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Concern
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2 font-medium">LGA</th>
                                <th className="px-3 py-2 font-medium">
                                    Radius
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Next review
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Classification
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {flashpoints.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="p-0">
                                        <EmptyState
                                            title="No flashpoints"
                                            description="None match these filters."
                                        />
                                    </td>
                                </tr>
                            )}
                            {flashpoints.data.map((f) => (
                                <tr key={f.ulid}>
                                    <td className="px-3 py-2">
                                        <Link
                                            href={FlashpointController.show(
                                                f.ulid,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {f.name}
                                        </Link>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            {f.kind.label}
                                            {f.shared && <SharedMarker />}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.threat_type.name}
                                    </td>
                                    <td className="px-3 py-2">
                                        <ConcernBadge level={f.concern_level} />
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.status.label}
                                    </td>
                                    <td className="px-3 py-2">
                                        {f.lga.name}
                                        <div className="text-xs text-muted-foreground">
                                            {f.state.name}
                                        </div>
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {formatRadius(f.radius_meters)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {formatDate(f.review_due_at)}
                                        {f.review_overdue && (
                                            <Badge
                                                variant="destructive"
                                                className="ml-2"
                                            >
                                                Overdue
                                            </Badge>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        <ClassificationBadge
                                            level={f.classification.value}
                                        />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Pagination page={flashpoints} />
            </div>
        </>
    );
}

FlashpointsIndex.layout = {
    breadcrumbs: [{ title: 'Flashpoints', href: FlashpointController.index() }],
};
