import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import HierarchyController from '@/actions/App/Http/Controllers/System/HierarchyController';
import Heading from '@/components/heading';
import { CODE_HELP } from '@/components/hierarchy/code-help';
import EntryActions from '@/components/hierarchy/entry-actions';
import EntryDialog from '@/components/hierarchy/entry-dialog';
import EntryStatus from '@/components/hierarchy/entry-status';
import TotalsWarning from '@/components/hierarchy/totals-warning';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type {
    HierarchyDistrict,
    HierarchyLga,
    HierarchyStateSummary,
} from '@/types/hierarchy';

type Props = {
    state: HierarchyStateSummary;
    districts: HierarchyDistrict[];
    lgas: HierarchyLga[];
    warnings: string[];
};

type Dialog =
    | { kind: 'new-district' }
    | { kind: 'new-lga' }
    | { kind: 'rename-district'; entry: HierarchyDistrict }
    | { kind: 'rename-lga'; entry: HierarchyLga }
    | { kind: 'toggle-district'; entry: HierarchyDistrict }
    | { kind: 'toggle-lga'; entry: HierarchyLga };

/**
 * One state's senatorial districts and LGAs: create, rename, deactivate
 * and reactivate (never delete). Wards are managed on each LGA's page.
 */
export default function HierarchyState({
    state,
    districts,
    lgas,
    warnings,
}: Props) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const [showInactive, setShowInactive] = useState(false);
    const close = (open: boolean) => !open && setDialog(null);

    const visibleDistricts = showInactive
        ? districts
        : districts.filter((d) => d.is_active);
    const visibleLgas = showInactive ? lgas : lgas.filter((l) => l.is_active);
    const districtOptions = districts
        .filter((d) => d.is_active)
        .map((d) => ({ value: d.ulid, label: `${d.name} (${d.code})` }));

    return (
        <>
            <Head title={`${state.name} — Hierarchy`} />
            <div className="space-y-8 p-4">
                <Heading
                    title={`${state.name} State`}
                    description={`Code ${state.code} · Capital ${state.capital ?? 'not recorded'}. Deactivated entries stay on the records that already use them but cannot be chosen for new ones.`}
                />

                <TotalsWarning warnings={warnings} />

                <div className="flex items-center gap-2 text-sm">
                    <Checkbox
                        id="show-inactive"
                        checked={showInactive}
                        onCheckedChange={(checked) =>
                            setShowInactive(checked === true)
                        }
                    />
                    <Label htmlFor="show-inactive">
                        Show deactivated entries
                    </Label>
                </div>

                <section
                    className="space-y-3"
                    aria-label="Senatorial districts"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">
                            Senatorial districts
                        </h2>
                        <Button
                            size="sm"
                            data-test="new-district"
                            onClick={() => setDialog({ kind: 'new-district' })}
                        >
                            <Plus /> New district
                        </Button>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        District
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Code
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Active LGAs
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleDistricts.map((district) => (
                                    <tr key={district.ulid}>
                                        <td className="px-3 py-2 font-medium">
                                            {district.name}
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {district.code}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">
                                            {district.lgas_active}
                                        </td>
                                        <td className="px-3 py-2">
                                            <EntryStatus entry={district} />
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            <EntryActions
                                                entry={district}
                                                onRename={() =>
                                                    setDialog({
                                                        kind: 'rename-district',
                                                        entry: district,
                                                    })
                                                }
                                                onToggle={() =>
                                                    setDialog({
                                                        kind: 'toggle-district',
                                                        entry: district,
                                                    })
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                                {visibleDistricts.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            No senatorial districts.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="space-y-3" aria-label="LGAs">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-base font-semibold">LGAs</h2>
                        <Button
                            size="sm"
                            data-test="new-lga"
                            disabled={districtOptions.length === 0}
                            onClick={() => setDialog({ kind: 'new-lga' })}
                        >
                            <Plus /> New LGA
                        </Button>
                    </div>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        LGA
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Code
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        District
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Headquarters
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Active wards
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Status
                                    </th>
                                    <th className="px-3 py-2">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {visibleLgas.map((lga) => (
                                    <tr key={lga.ulid}>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={HierarchyController.lga(
                                                    lga.ulid,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {lga.name}
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs">
                                            {lga.code}
                                        </td>
                                        <td className="px-3 py-2">
                                            {lga.district.name}
                                        </td>
                                        <td className="px-3 py-2">
                                            {lga.headquarters ?? '—'}
                                        </td>
                                        <td className="px-3 py-2 tabular-nums">
                                            {lga.wards_active ?? 0}
                                        </td>
                                        <td className="px-3 py-2">
                                            <EntryStatus entry={lga} />
                                        </td>
                                        <td className="px-3 py-2 text-right whitespace-nowrap">
                                            <EntryActions
                                                entry={lga}
                                                onRename={() =>
                                                    setDialog({
                                                        kind: 'rename-lga',
                                                        entry: lga,
                                                    })
                                                }
                                                onToggle={() =>
                                                    setDialog({
                                                        kind: 'toggle-lga',
                                                        entry: lga,
                                                    })
                                                }
                                            />
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link
                                                    href={HierarchyController.lga(
                                                        lga.ulid,
                                                    )}
                                                >
                                                    Wards
                                                </Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {visibleLgas.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={7}
                                            className="px-3 py-6 text-center text-muted-foreground"
                                        >
                                            No LGAs.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            {dialog?.kind === 'new-district' && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`New senatorial district in ${state.name}`}
                    description="The code and name follow the same rules as the official CSV import."
                    submitLabel="Create district"
                    method="post"
                    url={HierarchyController.storeDistrict(state.ulid).url}
                    fields={[
                        {
                            name: 'code',
                            label: `Code (${state.code}- then letters or digits, e.g. ${state.code}-C)`,
                            help: CODE_HELP,
                            required: true,
                            mono: true,
                        },
                        { name: 'name', label: 'Name', required: true },
                    ]}
                    initial={{ code: `${state.code}-` }}
                />
            )}
            {dialog?.kind === 'new-lga' && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`New LGA in ${state.name}`}
                    description="The code and name follow the same rules as the official CSV import."
                    submitLabel="Create LGA"
                    method="post"
                    url={HierarchyController.storeLga(state.ulid).url}
                    fields={[
                        {
                            name: 'district',
                            label: 'Senatorial district',
                            options: districtOptions,
                            required: true,
                        },
                        {
                            name: 'code',
                            label: 'Code (exactly 3 uppercase letters)',
                            help: CODE_HELP,
                            required: true,
                            mono: true,
                        },
                        { name: 'name', label: 'Name', required: true },
                        { name: 'headquarters', label: 'Headquarters' },
                    ]}
                />
            )}
            {(dialog?.kind === 'rename-district' ||
                dialog?.kind === 'rename-lga') && (
                <EntryDialog
                    open
                    onOpenChange={close}
                    title={`Rename ${dialog.entry.name}`}
                    description={`The code ${dialog.entry.code} cannot change. Records that use this entry show the new name. A later official CSV import with a different name renames it back.`}
                    submitLabel="Save"
                    method="put"
                    url={
                        dialog.kind === 'rename-district'
                            ? HierarchyController.renameDistrict(
                                  dialog.entry.ulid,
                              ).url
                            : HierarchyController.renameLga(dialog.entry.ulid)
                                  .url
                    }
                    fields={
                        dialog.kind === 'rename-lga'
                            ? [
                                  {
                                      name: 'name',
                                      label: 'Name',
                                      required: true,
                                  },
                                  {
                                      name: 'headquarters',
                                      label: 'Headquarters',
                                  },
                              ]
                            : [{ name: 'name', label: 'Name', required: true }]
                    }
                    initial={
                        dialog.kind === 'rename-lga'
                            ? {
                                  name: dialog.entry.name,
                                  headquarters: dialog.entry.headquarters ?? '',
                              }
                            : { name: dialog.entry.name }
                    }
                />
            )}
            {(dialog?.kind === 'toggle-district' ||
                dialog?.kind === 'toggle-lga') && (
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
                    url={
                        dialog.kind === 'toggle-district'
                            ? HierarchyController.districtActive(
                                  dialog.entry.ulid,
                              ).url
                            : HierarchyController.lgaActive(dialog.entry.ulid)
                                  .url
                    }
                    fields={[]}
                    data={{ active: !dialog.entry.is_active }}
                />
            )}
        </>
    );
}

HierarchyState.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Hierarchy', href: HierarchyController.index() },
        {
            title: props.state.name,
            href: HierarchyController.state(props.state.ulid),
        },
    ],
});
