import { Head, Link } from '@inertiajs/react';
import { MapIcon, Plus } from 'lucide-react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import IncidentFilters from '@/components/incidents/incident-filters';
import IncidentTable from '@/components/incidents/incident-table';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import type { IncidentListRow, IncidentListShared } from '@/types/incidents';
import type { Paginated } from '@/types/pagination';

type Props = IncidentListShared & {
    incidents: Paginated<IncidentListRow>;
};

/**
 * The incident list (Phase 5D): filters, search, saved views, 25 per page.
 * Everything is narrowed on the server through the viewer's scopes.
 */
export default function IncidentIndex(props: Props) {
    const { incidents, national, can, filters } = props;

    return (
        <>
            <Head title="Incidents" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">Incidents</h1>
                        <p className="text-sm text-muted-foreground">
                            {incidents.total.toLocaleString('en-NG')} incident
                            {incidents.total === 1 ? '' : 's'} in this view
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={IncidentListController.map({
                                    query: filters,
                                })}
                            >
                                <MapIcon /> Map
                            </Link>
                        </Button>
                        {can.create && (
                            <Button asChild>
                                <Link href={IncidentController.create()}>
                                    <Plus /> Report incident
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
                <IncidentFilters
                    shared={props}
                    url={IncidentListController.index().url}
                />
                <IncidentTable
                    rows={incidents.data}
                    showState={national}
                    empty="No incidents match these filters."
                />
                <Pagination page={incidents} />
            </div>
        </>
    );
}

IncidentIndex.layout = {
    breadcrumbs: [{ title: 'Incidents', href: IncidentListController.index() }],
};
