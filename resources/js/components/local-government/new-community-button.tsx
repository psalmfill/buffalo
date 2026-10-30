import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import NativeSelect from '@/components/native-select';
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

export type CreateLga = { ulid: string; name: string; state: string };

/**
 * New community from the communities list (CRUD coverage step). A
 * community always belongs to an LGA, so the user first picks one of the
 * LGAs where they may add communities (one LGA: straight to the form).
 */
export default function NewCommunityButton({ lgas }: { lgas: CreateLga[] }) {
    const [open, setOpen] = useState(false);
    const [lga, setLga] = useState('');

    if (lgas.length === 0) {
        return null;
    }

    if (lgas.length === 1) {
        return (
            <Button asChild data-test="new-community">
                <Link href={CommunityController.create(lgas[0].ulid)}>
                    <Plus /> New community
                </Link>
            </Button>
        );
    }

    const byState = Object.entries(
        lgas.reduce<Record<string, CreateLga[]>>((acc, row) => {
            (acc[row.state] ??= []).push(row);

            return acc;
        }, {}),
    ).sort(([a], [b]) => a.localeCompare(b));

    return (
        <>
            <Button data-test="new-community" onClick={() => setOpen(true)}>
                <Plus /> New community
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>New community</DialogTitle>
                        <DialogDescription>
                            Choose the LGA the community belongs to.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (lga !== '') {
                                router.visit(CommunityController.create(lga));
                            }
                        }}
                    >
                        <div className="grid gap-2">
                            <Label htmlFor="new-community-lga">LGA</Label>
                            <NativeSelect
                                id="new-community-lga"
                                value={lga}
                                required
                                onChange={(event) => setLga(event.target.value)}
                            >
                                <option value="">Choose an LGA…</option>
                                {byState.map(([state, rows]) => (
                                    <optgroup key={state} label={state}>
                                        {rows.map((row) => (
                                            <option
                                                key={row.ulid}
                                                value={row.ulid}
                                            >
                                                {row.name}
                                            </option>
                                        ))}
                                    </optgroup>
                                ))}
                            </NativeSelect>
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setOpen(false)}
                            >
                                Cancel
                            </Button>
                            <Button type="submit" disabled={lga === ''}>
                                Continue
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
