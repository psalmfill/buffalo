import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import InputError from '@/components/input-error';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { MapView } from '@/types/dashboard';
import type {
    AgencyOption,
    FormationDetail,
    FormationOptions,
} from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

type Props = {
    formation: FormationDetail | null;
    lga: NamedRef | null;
    lgas: (Option & { state: string })[];
    communities: Option[];
    agencies: AgencyOption[];
    parents: (Option & { agency: string })[];
    options: FormationOptions;
    map: MapView;
};

/**
 * Add or edit an agency formation. The agency and LGA are chosen once. The
 * parent list shows formations of the chosen agency that the user can see;
 * a parent the user cannot see is kept unless replaced.
 */
export default function FormationForm({
    formation,
    lga,
    lgas,
    communities,
    agencies,
    parents,
    options,
    map,
}: Props) {
    const editing = formation !== null;
    const parentHidden = formation?.parent_hidden ?? false;
    const form = useForm({
        lga: lga?.ulid ?? '',
        agency: formation?.agency.ulid ?? '',
        parent: formation?.parent?.ulid ?? '',
        keep_parent: parentHidden,
        community: formation?.community?.ulid ?? '',
        name: formation?.name ?? '',
        formation_type: formation?.formation_type.value ?? '',
        address: formation?.address ?? '',
        latitude: formation?.latitude?.toString() ?? '',
        longitude: formation?.longitude?.toString() ?? '',
        official_phone: formation?.official_phone ?? '',
        commander_title: formation?.commander_title ?? '',
        commander_name: formation?.commander_name ?? '',
        commander_rank: formation?.commander_rank ?? '',
        personnel_strength: formation?.personnel_strength?.toString() ?? '',
        is_temporary: formation?.is_temporary ?? false,
        active_from: formation?.active_from ?? '',
        active_to: formation?.active_to ?? '',
        status: formation?.status.value ?? 'active',
        classification: formation?.classification.value ?? 'restricted',
        notes: formation?.notes ?? '',
    });

    const selectedLgaState = lgas.find((l) => l.value === form.data.lga)?.state;
    // A state agency's formations must be in its own state.
    const agencyChoices = editing
        ? agencies
        : agencies.filter(
              (a) =>
                  a.state === null ||
                  selectedLgaState === undefined ||
                  a.state === selectedLgaState,
          );
    const parentChoices = parents.filter((p) => p.agency === form.data.agency);

    const chooseLga = (value: string) => {
        form.setData((data) => ({ ...data, lga: value, community: '' }));
        router.get(
            FormationController.create({ query: { lga: value } }).url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['communities', 'lga', 'map'],
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (
            coordinatesProblem({
                latitude: form.data.latitude,
                longitude: form.data.longitude,
            }) !== null
        ) {
            return;
        }

        form.transform((data) => {
            const { keep_parent: keepParent, ...rest } = data;
            const values: Record<string, unknown> = {
                ...rest,
                parent: data.parent === '' ? null : data.parent,
                community: data.community === '' ? null : data.community,
                active_from: data.is_temporary ? data.active_from : null,
                active_to: data.is_temporary ? data.active_to || null : null,
            };

            // Absent = unchanged: keeps a parent the user cannot see.
            if (keepParent) {
                delete values.parent;
            }

            if (editing) {
                delete values.lga;
                delete values.agency;
            }

            return values;
        });

        if (editing) {
            form.put(FormationController.update(formation.ulid).url);
        } else {
            form.post(FormationController.store().url);
        }
    };

    const text = (
        field:
            | 'name'
            | 'address'
            | 'official_phone'
            | 'commander_title'
            | 'commander_name'
            | 'commander_rank'
            | 'personnel_strength'
            | 'active_from'
            | 'active_to',
        label: string,
        type = 'text',
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input
                id={field}
                type={type}
                min={type === 'number' ? 0 : undefined}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            />
            <InputError message={form.errors[field]} />
        </div>
    );

    const select = (
        field:
            | 'agency'
            | 'parent'
            | 'community'
            | 'formation_type'
            | 'status'
            | 'classification',
        label: string,
        items: Option[],
        placeholder: string | null,
        disabled = false,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                disabled={disabled}
                onChange={(e) => form.setData(field, e.target.value)}
            >
                {placeholder !== null && (
                    <option value="">{placeholder}</option>
                )}
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={form.errors[field]} />
        </div>
    );

    return (
        <>
            <Head
                title={editing ? `Edit ${formation.name}` : 'Add formation'}
            />
            <form onSubmit={submit} className="max-w-5xl space-y-6 p-4">
                <h1 className="text-xl font-semibold">
                    {editing ? `Edit ${formation.name}` : 'Add formation'}
                </h1>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="space-y-4">
                        {editing ? (
                            <p className="text-sm text-muted-foreground">
                                {formation.agency.name} · {formation.lga.name}{' '}
                                LGA, {formation.state.name} State
                            </p>
                        ) : (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="lga">LGA</Label>
                                    <NativeSelect
                                        id="lga"
                                        value={form.data.lga}
                                        onChange={(e) =>
                                            chooseLga(e.target.value)
                                        }
                                    >
                                        <option value="">Choose an LGA</option>
                                        {lgas.map((item) => (
                                            <option
                                                key={item.value}
                                                value={item.value}
                                            >
                                                {item.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={form.errors.lga} />
                                </div>
                                {select(
                                    'agency',
                                    'Agency',
                                    agencyChoices,
                                    'Choose an agency',
                                )}
                            </>
                        )}
                        {text('name', 'Name (e.g. "Ikeja Division")')}
                        {select(
                            'formation_type',
                            'Type',
                            options.types,
                            'Choose a type',
                        )}
                        {parentHidden && form.data.keep_parent ? (
                            <div className="grid gap-2 text-sm">
                                <Label>Parent formation</Label>
                                <p className="text-muted-foreground">
                                    A formation you cannot view is recorded.{' '}
                                    <button
                                        type="button"
                                        className="underline"
                                        onClick={() =>
                                            form.setData('keep_parent', false)
                                        }
                                    >
                                        Replace
                                    </button>
                                </p>
                            </div>
                        ) : (
                            select(
                                'parent',
                                'Parent formation (optional)',
                                parentChoices,
                                'None',
                                form.data.agency === '',
                            )
                        )}
                        {select(
                            'community',
                            'Community (optional)',
                            communities,
                            'None',
                            communities.length === 0,
                        )}
                        {text('address', 'Address')}
                        {text('official_phone', 'Official phone line')}
                        <div className="grid gap-4 sm:grid-cols-3">
                            {text(
                                'commander_title',
                                'Commander title (e.g. DPO)',
                            )}
                            {text('commander_rank', 'Rank')}
                            {text('commander_name', 'Name')}
                        </div>
                        {text(
                            'personnel_strength',
                            'Personnel strength',
                            'number',
                        )}
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_temporary"
                                checked={form.data.is_temporary}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'is_temporary',
                                        checked === true,
                                    )
                                }
                            />
                            <Label htmlFor="is_temporary">
                                Temporary (e.g. a temporary checkpoint)
                            </Label>
                        </div>
                        {form.data.is_temporary && (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {text('active_from', 'Active from', 'date')}
                                {text(
                                    'active_to',
                                    'Active until (optional)',
                                    'date',
                                )}
                            </div>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select('status', 'Status', options.statuses, null)}
                            {select(
                                'classification',
                                'Classification',
                                options.classifications,
                                null,
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                            <InputError message={form.errors.notes} />
                        </div>
                    </section>

                    <section className="space-y-2">
                        <h2 className="text-sm font-medium">Location</h2>
                        <MapPointPicker
                            value={{
                                latitude: form.data.latitude,
                                longitude: form.data.longitude,
                            }}
                            onChange={({ latitude, longitude }) =>
                                form.setData((data) => ({
                                    ...data,
                                    latitude,
                                    longitude,
                                }))
                            }
                            center={map.center ?? undefined}
                            zoom={map.zoom}
                            errors={{
                                latitude: form.errors.latitude,
                                longitude: form.errors.longitude,
                            }}
                        />
                    </section>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Add formation'}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link
                            href={
                                editing
                                    ? FormationController.show(formation.ulid)
                                    : FormationController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

FormationForm.layout = {
    breadcrumbs: [
        { title: 'Agency formations', href: FormationController.index() },
    ],
};
