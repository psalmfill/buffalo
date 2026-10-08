import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonMergeRequestController from '@/actions/App/Http/Controllers/Persons/PersonMergeRequestController';
import InputError from '@/components/input-error';
import PersonPicker from '@/components/persons/person-picker';
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
import type { NamedRef } from '@/types/local-government';

/**
 * Asks the State Director to merge two of the officer's own records
 * (Phase 10A.7). The other record is picked among the officer's own
 * jurisdiction only (never records seen under a grant), unless given.
 */
export default function RequestMergeDialog({
    open,
    onOpenChange,
    person,
    other: presetOther = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    person: NamedRef;
    other?: NamedRef | null;
}) {
    const [other, setOther] = useState<NamedRef | null>(presetOther);
    const form = useForm({
        person: person.ulid,
        other: presetOther?.ulid ?? '',
        suggested_survivor: '',
        reason: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, other: other?.ulid ?? '' }));
        form.post(PersonMergeRequestController.store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Request a merge</DialogTitle>
                        <DialogDescription>
                            The State Director reviews the two records and
                            decides. Merges happen only within one state.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-1 text-sm">
                        <p className="text-muted-foreground">
                            This may be the same person as
                        </p>
                        <p className="font-medium">{person.name}</p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="merge-other">Other record</Label>
                        {presetOther ? (
                            <p className="rounded-md border px-3 py-2 text-sm font-medium">
                                {presetOther.name}
                            </p>
                        ) : (
                            <PersonPicker
                                id="merge-other"
                                value={other}
                                onChange={setOther}
                                except={person.ulid}
                                own
                            />
                        )}
                        <InputError message={form.errors.other} />
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Record to keep (suggestion)
                        </legend>
                        {[
                            { value: '', label: 'No preference' },
                            { value: person.ulid, label: person.name },
                            ...(other
                                ? [{ value: other.ulid, label: other.name }]
                                : []),
                        ].map((option) => (
                            <label
                                key={option.value || 'none'}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="radio"
                                    name="suggested_survivor"
                                    value={option.value}
                                    checked={
                                        form.data.suggested_survivor ===
                                        option.value
                                    }
                                    onChange={() =>
                                        form.setData(
                                            'suggested_survivor',
                                            option.value,
                                        )
                                    }
                                />
                                {option.label}
                            </label>
                        ))}
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="merge-reason">
                            Why are these the same person?
                        </Label>
                        <Textarea
                            id="merge-reason"
                            value={form.data.reason}
                            minLength={20}
                            maxLength={2000}
                            rows={4}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                            required
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || other === null}
                        >
                            Send request
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
