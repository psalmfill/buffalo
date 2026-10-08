import { Link, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, UserRoundPen } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PoliticalOfficeController from '@/actions/App/Http/Controllers/Political/PoliticalOfficeController';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import PersonPicker from '@/components/persons/person-picker';
import { officePlace, officeTerm } from '@/components/political/office-place';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { NamedRef } from '@/types/local-government';
import type { PoliticalOffice } from '@/types/political';

/**
 * Phase 10B.3: correct the holder of an office recorded against the wrong
 * person (not for an office that changed hands).
 */
function ReassignDialog({
    office,
    personUlid,
    onClose,
}: {
    office: PoliticalOffice;
    personUlid: string;
    onClose: () => void;
}) {
    const [holder, setHolder] = useState<NamedRef | null>(null);
    const form = useForm({ person: '', reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, person: holder?.ulid ?? '' }));
        form.post(PoliticalOfficeController.reassign(office.ulid).url, {
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>
                            Reassign holder: {office.title}
                        </DialogTitle>
                        <DialogDescription>
                            Use this only to correct an office recorded against
                            the wrong person. If the office changed hands, edit
                            this office to set its end date and record the
                            office for the new holder instead.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="reassign-person">Correct holder</Label>
                        <PersonPicker
                            id="reassign-person"
                            value={holder}
                            onChange={setHolder}
                            except={personUlid}
                            own
                        />
                        <InputError message={form.errors.person} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="reassign-reason">Reason</Label>
                        <Textarea
                            id="reassign-reason"
                            rows={3}
                            minLength={20}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || holder === null}
                        >
                            Reassign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function OfficeTable({
    offices,
    onDelete,
    onReassign,
}: {
    offices: PoliticalOffice[];
    onDelete: (office: PoliticalOffice) => void;
    onReassign: (office: PoliticalOffice) => void;
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
                                {office.can?.reassign && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Reassign holder of ${office.title}`}
                                        title="Reassign holder"
                                        onClick={() => onReassign(office)}
                                    >
                                        <UserRoundPen />
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
    const [reassigning, setReassigning] = useState<PoliticalOffice | null>(
        null,
    );

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
                <OfficeTable
                    offices={offices.current}
                    onDelete={setDeleting}
                    onReassign={setReassigning}
                />
            )}

            {offices.past.length > 0 && (
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">Past offices</h3>
                    <OfficeTable
                        offices={offices.past}
                        onDelete={setDeleting}
                        onReassign={setReassigning}
                    />
                </section>
            )}

            {reassigning && (
                <ReassignDialog
                    office={reassigning}
                    personUlid={personUlid}
                    onClose={() => setReassigning(null)}
                />
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
