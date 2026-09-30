import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/time';
import type { IncidentRow } from '@/types/incidents';
import type { Paginated } from '@/types/pagination';

type Props = {
    incidents: Paginated<IncidentRow>;
    can: { create: boolean };
};

/** The user's own drafts (Phase 5A; the full incident list is 5D). */
export default function IncidentIndex({ incidents, can }: Props) {
    return (
        <>
            <Head title="Incidents" />
            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h1 className="text-xl font-semibold">
                            My draft incidents
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Incidents you have started but not yet submitted.
                        </p>
                    </div>
                    {can.create && (
                        <Button asChild>
                            <Link href={IncidentController.create()}>
                                <Plus /> Report incident
                            </Link>
                        </Button>
                    )}
                </div>
                {incidents.data.length === 0 ? (
                    <EmptyState
                        title="No drafts"
                        description="Incidents you report are saved here as drafts."
                    />
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Title
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Type
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Occurred
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Place
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Severity
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Classification
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {incidents.data.map((i) => (
                                    <tr key={i.ulid}>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={IncidentController.show(
                                                    i.ulid,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {i.title}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {i.type.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {formatDateTime(i.occurred_at)}
                                        </td>
                                        <td className="px-3 py-2">
                                            {i.lga.name}, {i.state.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {i.severity.label}
                                        </td>
                                        <td className="px-3 py-2">
                                            <ClassificationBadge
                                                level={i.classification.value}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pagination page={incidents} />
            </div>
        </>
    );
}

IncidentIndex.layout = {
    breadcrumbs: [{ title: 'Incidents', href: IncidentController.index() }],
};
