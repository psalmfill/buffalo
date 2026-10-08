import { Head, Link } from '@inertiajs/react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import ActivityTable from '@/components/users/activity-table';
import type { NamedRef } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { PersonRow } from '@/types/persons';
import type { ActivityEntry } from '@/types/users';

type Props = {
    merge: { ulid: string; executedAt: string; undoneAt: string | null };
    source: NamedRef & { merged: boolean };
    survivor: PersonRow;
    history: Paginated<ActivityEntry>;
};

/**
 * The history of a record merged into another (Phase 10A): audit history
 * stays attached to the original record, reachable from the survivor's
 * "Merged records".
 */
export default function MergedHistory({
    merge,
    source,
    survivor,
    history,
}: Props) {
    return (
        <>
            <Head title="Merged record history" />
            <div className="space-y-6 p-4">
                <Heading
                    title={`History of ${source.name}`}
                    description={`This record was merged into ${survivor.name} on ${new Date(merge.executedAt).toLocaleString()}${merge.undoneAt ? ' (the merge was later undone)' : ''}. Its history stays with the original record.`}
                />
                <p className="text-sm">
                    <Link
                        href={PersonController.show(survivor.ulid)}
                        className="hover:underline"
                    >
                        Back to {survivor.name}
                    </Link>
                </p>
                <ActivityTable entries={history.data} />
                <Pagination page={history} />
            </div>
        </>
    );
}

MergedHistory.layout = {
    breadcrumbs: [{ title: 'Persons', href: PersonController.index() }],
};
