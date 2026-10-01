import { router, useForm } from '@inertiajs/react';
import { Bookmark, ChevronDown, Search, X } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import IncidentSavedFilterController from '@/actions/App/Http/Controllers/Incidents/IncidentSavedFilterController';
import PickerController from '@/actions/App/Http/Controllers/Pickers/PickerController';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import EntityPicker from '@/components/pickers/entity-picker';
import type { PickedEntity } from '@/components/pickers/entity-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useAuth } from '@/hooks/use-auth';
import type {
    IncidentFilterValues,
    IncidentListShared,
    SavedIncidentFilter,
} from '@/types/incidents';

/** Drops empty values so the URL stays short. */
function clean(values: IncidentFilterValues): IncidentFilterValues {
    return Object.fromEntries(
        Object.entries(values).filter(
            ([, v]) =>
                v !== undefined &&
                v !== '' &&
                !(Array.isArray(v) && v.length === 0),
        ),
    ) as IncidentFilterValues;
}

/**
 * The incident filters (Phase 5D), shared by the list and the map. They
 * live in the URL, so a view can be shared: each recipient still sees only
 * what their own scopes allow.
 */
export default function IncidentFilters({
    shared,
    url,
    showSort = true,
}: {
    shared: IncidentListShared;
    url: string;
    showSort?: boolean;
}) {
    const { can } = useAuth();
    const { filterOptions: options } = shared;
    const [values, setValues] = useState<IncidentFilterValues>({
        ...shared.filters,
        statuses: shared.filters.statuses ?? shared.appliedStatuses,
    });
    const [community, setCommunity] = useState<PickedEntity | null>(
        shared.filters.community
            ? {
                  value: shared.filters.community,
                  label: shared.filterLabels.community ?? 'Selected community',
              }
            : null,
    );
    const [group, setGroup] = useState<PickedEntity | null>(
        shared.filters.group
            ? {
                  value: shared.filters.group,
                  label: shared.filterLabels.group ?? 'Selected group',
              }
            : null,
    );
    const [officer, setOfficer] = useState<PickedEntity | null>(
        shared.filters.officer
            ? {
                  value: shared.filters.officer,
                  label: shared.filterLabels.officer ?? 'Selected officer',
              }
            : null,
    );
    const [saving, setSaving] = useState(false);

    const set = <K extends keyof IncidentFilterValues>(
        key: K,
        value: IncidentFilterValues[K],
    ) => setValues((v) => ({ ...v, [key]: value }));
    const toggle = (key: 'types' | 'severities' | 'statuses', value: string) =>
        setValues((v) => {
            const list = v[key] ?? [];

            return {
                ...v,
                [key]: list.includes(value)
                    ? list.filter((x) => x !== value)
                    : [...list, value],
            };
        });

    const current = (): IncidentFilterValues =>
        clean({
            ...values,
            community: community?.value,
            group: group?.value,
            officer: officer?.value,
            // The default statuses need not be in the URL.
            statuses: sameSet(values.statuses ?? [], shared.defaultStatuses)
                ? undefined
                : values.statuses,
        });

    const apply = (next?: IncidentFilterValues) => {
        router.get(url, next ?? current(), {
            preserveState: false,
            preserveScroll: true,
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply();
    };

    const lgas = values.state
        ? options.lgas.filter((l) => l.state === values.state)
        : options.lgas;

    return (
        <form
            onSubmit={submit}
            className="space-y-3 rounded-lg border p-3"
            aria-label="Incident filters"
        >
            <SavedFilters
                shared={shared}
                onApply={(filters) => apply(filters)}
                onSave={() => setSaving(true)}
            />

            <div className="flex flex-wrap gap-2">
                <div className="relative min-w-60 flex-1">
                    <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input
                        aria-label="Search incidents"
                        className="pl-8"
                        placeholder="Reference (BUF/…) or words in the title and description"
                        value={values.q ?? ''}
                        onChange={(e) => set('q', e.target.value)}
                    />
                </div>
                <Button type="submit">Apply</Button>
                <Button type="button" variant="ghost" onClick={() => apply({})}>
                    Reset
                </Button>
            </div>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Dropdown
                    label={`Type${values.types?.length ? ` (${values.types.length})` : ''}`}
                >
                    {options.types.map((t) => (
                        <CheckRow
                            key={t.value}
                            label={t.label}
                            checked={values.types?.includes(t.value) ?? false}
                            onChange={() => toggle('types', t.value)}
                        />
                    ))}
                </Dropdown>
                <Select
                    label="Category"
                    value={values.category}
                    onChange={(v) => set('category', v)}
                    options={options.categories}
                />
                <Dropdown
                    label={`Severity${values.severities?.length ? ` (${values.severities.length})` : ''}`}
                >
                    {options.severities.map((o) => (
                        <CheckRow
                            key={o.value}
                            label={o.label}
                            checked={
                                values.severities?.includes(o.value) ?? false
                            }
                            onChange={() => toggle('severities', o.value)}
                        />
                    ))}
                </Dropdown>
                <Dropdown label={`Status (${values.statuses?.length ?? 0})`}>
                    {options.statuses.map((o) => (
                        <CheckRow
                            key={o.value}
                            label={o.label}
                            checked={
                                values.statuses?.includes(o.value) ?? false
                            }
                            onChange={() => toggle('statuses', o.value)}
                        />
                    ))}
                </Dropdown>
                <Select
                    label="Escalation"
                    value={values.escalated}
                    onChange={(v) =>
                        set('escalated', v as IncidentFilterValues['escalated'])
                    }
                    options={[
                        { value: 'yes', label: 'Escalated' },
                        {
                            value: 'unacknowledged',
                            label: 'Escalated, unacknowledged',
                        },
                        { value: 'no', label: 'Not escalated' },
                    ]}
                />
                <div className="grid grid-cols-2 gap-2">
                    <Field label="From" id="filter-from">
                        <Input
                            id="filter-from"
                            type="date"
                            value={values.from ?? ''}
                            onChange={(e) => set('from', e.target.value)}
                        />
                    </Field>
                    <Field label="To" id="filter-to">
                        <Input
                            id="filter-to"
                            type="date"
                            value={values.to ?? ''}
                            onChange={(e) => set('to', e.target.value)}
                        />
                    </Field>
                </div>
                {options.states.length > 0 && (
                    <Select
                        label="State"
                        value={values.state}
                        onChange={(v) =>
                            setValues((x) => ({
                                ...x,
                                state: v,
                                lga: undefined,
                            }))
                        }
                        options={options.states}
                    />
                )}
                {options.lgas.length > 1 && (
                    <Select
                        label="LGA"
                        value={values.lga}
                        onChange={(v) => set('lga', v)}
                        options={lgas}
                    />
                )}
                <Field label="Community" id="filter-community">
                    <EntityPicker
                        id="filter-community"
                        endpoint={PickerController.communities().url}
                        params={values.lga ? { lga: values.lga } : {}}
                        placeholder="Search communities"
                        value={community}
                        onChange={setCommunity}
                    />
                </Field>
                <Select
                    label="Casualty outcome"
                    value={values.outcome}
                    onChange={(v) => set('outcome', v)}
                    options={options.outcomes}
                />
                {can('criminal_groups.view') && (
                    <Field label="Involves criminal group" id="filter-group">
                        <EntityPicker
                            id="filter-group"
                            endpoint={PickerController.groups().url}
                            placeholder="Search groups"
                            value={group}
                            onChange={setGroup}
                        />
                    </Field>
                )}
                {can('users.view') && (
                    <Field label="Reporting officer" id="filter-officer">
                        <EntityPicker
                            id="filter-officer"
                            endpoint={PickerController.users().url}
                            placeholder="Search officers"
                            value={officer}
                            onChange={setOfficer}
                        />
                    </Field>
                )}
                {showSort && (
                    <div className="grid grid-cols-[2fr_1fr] gap-2">
                        <Select
                            label="Sort by"
                            value={values.sort}
                            placeholder="Time (or relevance)"
                            onChange={(v) => set('sort', v)}
                            options={[
                                { value: 'occurred_at', label: 'Time' },
                                { value: 'reference', label: 'Reference' },
                                { value: 'severity', label: 'Severity' },
                                { value: 'status', label: 'Status' },
                                { value: 'type', label: 'Type' },
                            ]}
                        />
                        <Select
                            label="Order"
                            value={values.dir}
                            placeholder="Newest first"
                            onChange={(v) => set('dir', v as 'asc' | 'desc')}
                            options={[
                                { value: 'desc', label: 'Descending' },
                                { value: 'asc', label: 'Ascending' },
                            ]}
                        />
                    </div>
                )}
            </div>
            <div className="flex flex-wrap gap-4 text-sm">
                <CheckRow
                    label="Has casualties"
                    checked={values.has_casualties === '1'}
                    onChange={(c) => set('has_casualties', c ? '1' : undefined)}
                />
                <CheckRow
                    label="Verification overdue"
                    checked={values.overdue === '1'}
                    onChange={(c) => set('overdue', c ? '1' : undefined)}
                />
                <CheckRow
                    label="My reports"
                    checked={values.mine === '1'}
                    onChange={(c) => set('mine', c ? '1' : undefined)}
                />
                <CheckRow
                    label="No map location"
                    checked={values.no_location === '1'}
                    onChange={(c) => set('no_location', c ? '1' : undefined)}
                />
            </div>

            {saving && (
                <SaveDialog
                    filters={current()}
                    onClose={() => setSaving(false)}
                />
            )}
        </form>
    );
}

function sameSet(a: string[], b: string[]): boolean {
    return a.length === b.length && a.every((x) => b.includes(x));
}

function SavedFilters({
    shared,
    onApply,
    onSave,
}: {
    shared: IncidentListShared;
    onApply: (f: IncidentFilterValues) => void;
    onSave: () => void;
}) {
    const chip = (f: SavedIncidentFilter, own: boolean) => (
        <span
            key={`${own ? 's' : 'd'}-${f.name}`}
            className="inline-flex items-center overflow-hidden rounded-full border text-xs"
        >
            <button
                type="button"
                className="px-3 py-1 hover:bg-muted"
                onClick={() => onApply(f.filters)}
            >
                {f.name}
            </button>
            {own && f.ulid && (
                <button
                    type="button"
                    aria-label={`Remove saved filter ${f.name}`}
                    className="border-l px-1.5 py-1 hover:bg-muted"
                    onClick={() =>
                        router.delete(
                            IncidentSavedFilterController.destroy(
                                f.ulid as string,
                            ).url,
                            { preserveScroll: true },
                        )
                    }
                >
                    <X className="size-3" />
                </button>
            )}
        </span>
    );

    return (
        <div
            className="flex flex-wrap items-center gap-2"
            aria-label="Saved views"
        >
            {shared.defaultFilters.map((f) => chip(f, false))}
            {shared.savedFilters.map((f) => chip(f, true))}
            <Button type="button" size="sm" variant="ghost" onClick={onSave}>
                <Bookmark /> Save this view
            </Button>
        </div>
    );
}

function SaveDialog({
    filters,
    onClose,
}: {
    filters: IncidentFilterValues;
    onClose: () => void;
}) {
    const form = useForm({ name: '', filters });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(IncidentSavedFilterController.store().url, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="space-y-3">
                    <DialogHeader>
                        <DialogTitle>Save this view</DialogTitle>
                    </DialogHeader>
                    <Field label="Name" id="saved-filter-name">
                        <Input
                            id="saved-filter-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                        />
                        <InputError message={form.errors.name} />
                    </Field>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Field({
    label,
    id,
    children,
}: {
    label: string;
    id: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-1">
            <Label htmlFor={id} className="text-xs">
                {label}
            </Label>
            {children}
        </div>
    );
}

function Select({
    label,
    value,
    onChange,
    options,
    placeholder = 'Any',
}: {
    label: string;
    value: string | undefined;
    onChange: (value: string | undefined) => void;
    options: { value: string; label: string }[];
    placeholder?: string;
}) {
    const id = `filter-${label.toLowerCase().replace(/\W+/g, '-')}`;

    return (
        <Field label={label} id={id}>
            <NativeSelect
                id={id}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value || undefined)}
            >
                <option value="">{placeholder}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </NativeSelect>
        </Field>
    );
}

function Dropdown({ label, children }: { label: string; children: ReactNode }) {
    return (
        <details className="group relative self-end">
            <summary className="flex h-9 cursor-pointer list-none items-center justify-between rounded-md border px-3 text-sm">
                {label} <ChevronDown className="size-4 text-muted-foreground" />
            </summary>
            <div className="absolute z-20 mt-1 max-h-72 w-full min-w-56 space-y-1 overflow-y-auto rounded-md border bg-popover p-2 shadow-md">
                {children}
            </div>
        </details>
    );
}

function CheckRow({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex cursor-pointer items-center gap-2 text-sm">
            <Checkbox
                checked={checked}
                onCheckedChange={(c) => onChange(c === true)}
            />
            {label}
        </label>
    );
}
