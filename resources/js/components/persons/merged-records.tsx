import { Link, router, useForm } from '@inertiajs/react';
import { History, Undo2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonMergeController from '@/actions/App/Http/Controllers/Persons/PersonMergeController';
import InputError from '@/components/input-error';
import PersonPicker from '@/components/persons/person-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { NamedRef } from '@/types/local-government';
import type { MergedRecord } from '@/types/persons';

function UndoDialog({
    merge,
    onClose,
}: {
    merge: MergedRecord;
    onClose: () => void;
}) {
    const form = useForm<{ reason: string; keep: string[] }>({
        reason: '',
        keep: [],
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(PersonMergeController.undo(merge.ulid).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const toggle = (field: string, keep: boolean) =>
        form.setData(
            'keep',
            keep
                ? [...form.data.keep, field]
                : form.data.keep.filter((value) => value !== field),
        );

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Undo this merge?</DialogTitle>
                        <DialogDescription>
                            Both records exist again, each with exactly the
                            links it had before the merge. Links added to this
                            record since the merge stay with it.
                        </DialogDescription>
                    </DialogHeader>

                    <p className="rounded-md border border-amber-300 p-3 text-sm">
                        Temporary access that was revoked and access requests
                        that were cancelled by the merge are <b>not</b>{' '}
                        restored. Their holders can request access again.
                    </p>

                    {merge.edits.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                Edited since the merge
                            </legend>
                            <p className="text-xs text-muted-foreground">
                                Tick a field to keep its current value;
                                otherwise it returns to its value before the
                                merge.
                            </p>
                            {merge.edits.map((edit) => (
                                <div
                                    key={edit.field}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <Checkbox
                                        id={`keep-${edit.field}`}
                                        checked={form.data.keep.includes(
                                            edit.field,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggle(edit.field, checked === true)
                                        }
                                    />
                                    <Label
                                        htmlFor={`keep-${edit.field}`}
                                        className="font-normal"
                                    >
                                        <span className="font-medium">
                                            {edit.label}
                                        </span>
                                        {edit.protected ? (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                (protected value changed)
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                : now "{edit.current ?? '—'}",
                                                before the merge "
                                                {edit.before_merge ?? '—'}"
                                            </span>
                                        )}
                                    </Label>
                                </div>
                            ))}
                        </fieldset>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="undo-reason">Reason</Label>
                        <Textarea
                            id="undo-reason"
                            value={form.data.reason}
                            minLength={10}
                            maxLength={1000}
                            rows={3}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.reason} />
                        <InputError
                            message={
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).undo
                            }
                        />
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
                            variant="destructive"
                            disabled={form.processing}
                        >
                            <Undo2 /> Undo merge
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * "Merged records" on a survivor's page (Phase 10A.7): the records merged
 * into this one, a link to each one's history, and Undo within the window.
 */
export default function MergedRecords({ merges }: { merges: MergedRecord[] }) {
    const [undoing, setUndoing] = useState<MergedRecord | null>(null);

    if (merges.length === 0) {
        return null;
    }

    return (
        <section className="space-y-2 rounded-lg border p-4">
            <h2 className="text-sm font-medium">Merged records</h2>
            <ul className="divide-y text-sm">
                {merges.map((merge) => (
                    <li
                        key={merge.ulid}
                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                    >
                        <div>
                            <p>
                                {merge.source ? (
                                    <span className="font-medium">
                                        {merge.source.name}
                                    </span>
                                ) : (
                                    <span className="text-muted-foreground">
                                        A record outside your view
                                    </span>
                                )}{' '}
                                <span className="text-xs text-muted-foreground">
                                    ({merge.source?.ulid ?? merge.ulid})
                                </span>
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Merged by {merge.executedBy} on{' '}
                                {new Date(merge.executedAt).toLocaleString()}
                                {merge.undoneAt &&
                                    ` · undone by ${merge.undoneBy} on ${new Date(merge.undoneAt).toLocaleString()}`}
                                {!merge.undoneAt &&
                                    merge.undoDeadline &&
                                    merge.can.undo &&
                                    ` · can be undone until ${new Date(merge.undoDeadline).toLocaleDateString()}`}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Button size="sm" variant="ghost" asChild>
                                <Link
                                    href={PersonMergeController.history(
                                        merge.ulid,
                                    )}
                                >
                                    <History /> History
                                </Link>
                            </Button>
                            {merge.can.undo && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setUndoing(merge)}
                                >
                                    <Undo2 /> Undo
                                </Button>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            {undoing && (
                <UndoDialog merge={undoing} onClose={() => setUndoing(null)} />
            )}
        </section>
    );
}

/**
 * State Directors and national users: pick another of their own records
 * and open the review page (Phase 10A.7).
 */
export function MergeWithDialog({
    person,
    onClose,
}: {
    person: NamedRef;
    onClose: () => void;
}) {
    const [other, setOther] = useState<NamedRef | null>(null);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Merge with another record</DialogTitle>
                    <DialogDescription>
                        {person.name} may be the same person as another record
                        in your jurisdiction. Pick it to review both side by
                        side; nothing changes until you confirm.
                    </DialogDescription>
                </DialogHeader>
                <PersonPicker
                    value={other}
                    onChange={setOther}
                    except={person.ulid}
                    own
                />
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={other === null}
                        onClick={() =>
                            other &&
                            router.visit(
                                PersonMergeController.review({
                                    query: { a: person.ulid, b: other.ulid },
                                }).url,
                            )
                        }
                    >
                        Review
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
