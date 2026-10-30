import { Head } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import HierarchyController from '@/actions/App/Http/Controllers/System/HierarchyController';
import Heading from '@/components/heading';
import { CODE_HELP } from '@/components/hierarchy/code-help';
import EntryActions from '@/components/hierarchy/entry-actions';
import EntryDialog from '@/components/hierarchy/entry-dialog';
import EntryStatus from '@/components/hierarchy/entry-status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type {
    HierarchyEntry,
    HierarchyLga,
    HierarchyStateSummary,
} from '@/types/hierarchy';

type Props = {
    state: HierarchyStateSummary;
    lga: HierarchyLga;
    wards: HierarchyEntry[];
};

type Dialog =
    | { kind: 'new' }
    | { kind: 'rename'; entry: HierarchyEntry }
    | { kind: 'toggle'; entry: HierarchyEntry };

/** One LGA's wards: create, rename, deactivate and reactivate. */
export default function HierarchyLgaPage({ state, lga, wards }: Props) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [showInactive, setShowInactive] = useState(false);
    const close = (open: boolean) => !open && setDialog(null);
    const visible = showInactive ? wards : wards.filter((w) => w.is_active);

    return (
        <>
            <Head title={`${lga.name} — Hierarchy`} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={`${lga.name} LGA`}
                        description={`${state.name} · Code ${lga.code} · ${lga.district.name} · Headquarters ${lga.headquarters ?? 'not recorded'}. Wards have no fixed total.`}
                    />
                    <Button
                        data-test="new-ward"
                        disabled={!lga.is_active}
                        onClick={() => setDialog({ kind: 'new' })}
                    >
                        <Plus /> New ward
                    </Button>
                </div>

                <EntryStatus entry={lga} />

                <div className="flex items-center gap-2 text-sm">
                    <Checkbox
                        id="show-inactive"
                        checked={showInactive}
                        onCheckedChange={(checked) =>
                            setShowInactive(checked === true)
                        }
                    />
                    <Label htmlFor="show-inactive">
                        Show deactivated wards
                    </Label>
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Ward</th>
                                <th className="px-3 py-2 font-medium">Code</th>
                                <th className="px-3 py-2 font-medium">
                                    Status
                                </th>
                                <th className="px-3 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {visible.map((ward) => (
                                <tr key={ward.ulid}>
                                    <td className="px-3 py-2 font-medium">
                                        {ward.name}
                                    </td>
                                    <td className="px-3 py-2 font-mono text-xs">
                                        {ward.code}
                                    </td>
                                    <td className="px-3 py-2">
                                        <EntryStatus entry={ward} />
                                    </td>
                                    <td className="px-3 py-2 text-right whitespace-nowrap">
                                        <EntryActions
                                            entry={ward}
                                            onRename={() =>
                                                setDialog({
                                                    kind: 'rename',
                                                    entry: ward,
                                                })
                                            }
                                            onToggle={() =>
                                                setDialog({
                                                    kind: 'toggle',
                                                    entry: ward,
                                                })
                                            }
                                        />
                                    </td>
                                </tr>
                            ))}
                            {visible.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-3 py-6 text-center text-muted-foreground"
                                    >
                                        No wards.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {dialog?.kind === 'new' && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`New ward in ${lga.name}`}
                    description="The code and name follow the same rules as the official CSV import."
                    submitLabel="Create ward"
                    method="post"
                    url={HierarchyController.storeWard(lga.ulid).url}
                    fields={[
                        {
                            name: 'code',
                            label: 'Code (1–20 uppercase letters, digits or hyphens)',
                            help: CODE_HELP,
                            required: true,
                            mono: true,
                        },
                        { name: 'name', label: 'Name', required: true },
                    ]}
                />
            )}
            {dialog?.kind === 'rename' && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`Rename ${dialog.entry.name}`}
                    description={`The code ${dialog.entry.code} cannot change. A later official CSV import with a different name renames it back.`}
                    submitLabel="Save"
                    method="put"
                    url={HierarchyController.renameWard(dialog.entry.ulid).url}
                    fields={[{ name: 'name', label: 'Name', required: true }]}
                    initial={{ name: dialog.entry.name }}
                />
            )}
            {dialog?.kind === 'toggle' && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`${dialog.entry.is_active ? 'Deactivate' : 'Reactivate'} ${dialog.entry.name}?`}
                    description={
                        dialog.entry.is_active
                            ? 'It stays on every record that already uses it, but can no longer be chosen for new records. Nothing is deleted.'
                            : 'It can be chosen for new records again.'
                    }
                    submitLabel={
                        dialog.entry.is_active ? 'Deactivate' : 'Reactivate'
                    }
                    destructive={dialog.entry.is_active}
                    method="put"
                    url={HierarchyController.wardActive(dialog.entry.ulid).url}
                    fields={[]}
                    data={{ active: !dialog.entry.is_active }}
                />
            )}
        </>
    );
}

HierarchyLgaPage.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Hierarchy', href: HierarchyController.index() },
        {
            title: props.state.name,
            href: HierarchyController.state(props.state.ulid),
        },
        {
            title: props.lga.name,
            href: HierarchyController.lga(props.lga.ulid),
        },
    ],
});
