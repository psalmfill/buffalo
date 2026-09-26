import { Link } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import PoliticalOfficeController from '@/actions/App/Http/Controllers/Political/PoliticalOfficeController';
import EmptyState from '@/components/empty-state';
import { officePlace, officeTerm } from '@/components/political/office-place';
import { Button } from '@/components/ui/button';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { PoliticalOffice } from '@/types/political';

function OfficeTable({
    offices,
    onDelete,
}: {
    offices: PoliticalOffice[];
    onDelete: (office: PoliticalOffice) => void;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Office</th>
                        <th className="px-3 py-2 font-medium">Place</th>
                        <th className="px-3 py-2 font-medium">Party</th>
                        <th className="px-3 py-2 font-medium">Term</th>
                        <th className="px-3 py-2">
                            <span className="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {offices.map((office) => (
                        <tr key={office.ulid}>
                            <td className="px-3 py-2 font-medium">
                                {office.title}
                            </td>
                            <td className="px-3 py-2">{officePlace(office)}</td>
                            <td className="px-3 py-2">{office.party ?? '—'}</td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {officeTerm(office)}
                            </td>
                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                {office.can?.update && (
                                    <Button variant="ghost" size="icon" asChild>
                                        <Link
                                            href={PoliticalOfficeController.edit(
                                                office.ulid,
                                            )}
                                            aria-label={`Edit ${office.title}`}
                                        >
                                            <Pencil />
                                        </Link>
                                    </Button>
                                )}
                                {office.can?.delete && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Delete ${office.title}`}
                                        onClick={() => onDelete(office)}
                                    >
                                        <Trash2 />
                                    </Button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/** A person's political offices, current and past. */
export default function PoliticalOfficesPanel({
    personUlid,
    offices,
    canAdd,
}: {
    personUlid: string;
    offices: { current: PoliticalOffice[]; past: PoliticalOffice[] };
    canAdd: boolean;
}) {
    const [deleting, setDeleting] = useState<PoliticalOffice | null>(null);

    return (
        <div className="space-y-6">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold">Current offices</h3>
                {canAdd && (
                    <Button asChild>
                        <Link
                            href={PoliticalOfficeController.create(personUlid)}
                        >
                            <Plus /> Record office
                        </Link>
                    </Button>
                )}
            </div>
            {offices.current.length === 0 ? (
                <div className="rounded-lg border">
                    <EmptyState
                        title="No current offices"
                        description="Recording an office gives the person the political leader role."
                    />
                </div>
            ) : (
                <OfficeTable offices={offices.current} onDelete={setDeleting} />
            )}

            {offices.past.length > 0 && (
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Past offices</h3>
                    <OfficeTable
                        offices={offices.past}
                        onDelete={setDeleting}
                    />
                </section>
            )}

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Delete the office "${deleting.title}"?`}
                    description="Use this for a mistaken record; to record the end of a term, edit the office and set its end date. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={PoliticalOfficeController.destroy(deleting.ulid).url}
                />
            )}
        </div>
    );
}
