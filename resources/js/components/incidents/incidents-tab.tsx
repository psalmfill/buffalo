import IncidentTable from '@/components/incidents/incident-table';
import Pagination from '@/components/pagination';
import type { IncidentListRow } from '@/types/incidents';
import type { Paginated } from '@/types/pagination';

/**
 * The Incidents tab on other records (Phase 5D): incidents visible to the
 * viewer that are linked to the record. The server hides the tab when
 * there are none.
 */
export default function IncidentsTab({
    incidents,
    showInvolvement = false,
}: {
    incidents: Paginated<IncidentListRow>;
    showInvolvement?: boolean;
}) {
    return (
        <div className="space-y-2">
            <IncidentTable
                rows={incidents.data}
                showState
                showInvolvement={showInvolvement}
            />
            <Pagination page={incidents} />
        </div>
    );
}
