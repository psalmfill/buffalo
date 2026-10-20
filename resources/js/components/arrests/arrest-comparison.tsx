import { Link } from '@inertiajs/react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import type { ArrestComparison } from '@/types/arrests';

/**
 * The incident's reported `arrested` count against the persons on its
 * arrests (Phase 11C). A suggestion only: nothing changes automatically;
 * the incident is amended through its normal amendment flow.
 */
export default function ArrestComparisonNotice({
    comparison,
    incidentUlid,
    canAmend,
}: {
    comparison: ArrestComparison;
    incidentUlid: string;
    canAmend: boolean;
}) {
    if (!comparison.differs) {
        return null;
    }

    return (
        <p
            className="rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
            data-test="arrest-comparison"
        >
            The incident reports {comparison.reported} arrested; its arrest
            records list {comparison.recorded}{' '}
            {comparison.recorded === 1 ? 'person' : 'persons'}.{' '}
            {canAmend ? (
                <Link
                    className="underline"
                    href={IncidentController.edit(incidentUlid)}
                >
                    Amend the incident’s casualty figures
                </Link>
            ) : (
                'Someone who may amend the incident can correct its figures.'
            )}
        </p>
    );
}
