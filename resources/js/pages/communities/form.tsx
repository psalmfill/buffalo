import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, X } from 'lucide-react';
import type { FormEvent } from 'react';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import LinkedPersonField from '@/components/persons/linked-person-field';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { MapView } from '@/types/dashboard';
import type {
    CommunityDetail,
    LgaFacts,
    NamedRef,
    Option,
} from '@/types/local-government';

type Props = {
    lga: LgaFacts;
    community: CommunityDetail | null;
    types: Option[];
    wards: NamedRef[];
    map: MapView;
};

/** Add a community to an LGA, or edit one. The LGA cannot change. */
export default function CommunityForm({
    lga,
    community,
    types,
    wards,
    map,
}: Props) {
    const editing = community !== null;
    const form = useForm({
        name: community?.name ?? '',
        other_names: community?.other_names ?? [],
        type: community?.type.value ?? 'village',
        ward: community?.ward_ulid ?? '',
        latitude: community?.latitude?.toString() ?? '',
        longitude: community?.longitude?.toString() ?? '',
        population_estimate: community?.population_estimate?.toString() ?? '',
        notes: community?.notes ?? '',
    });
    // undefined: unchanged (a ruler the user cannot see is kept).
    const [ruler, setRuler] = useState<NamedRef | null | undefined>(
        community?.traditional_ruler.hidden
            ? undefined
            : (community?.traditional_ruler.person ?? null),
    );

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

        form.transform((data) => ({
            ...data,
            ward: data.ward === '' ? null : data.ward,
            other_names: data.other_names.filter((name) => name.trim() !== ''),
            ...(ruler !== undefined
                ? { traditional_ruler: ruler?.ulid ?? null }
                : {}),
        }));

        if (editing) {
            form.put(CommunityController.update(community.ulid).url);
        } else {
            form.post(CommunityController.store(lga.ulid).url);
        }
    };

    const title = editing ? `Edit ${community.name}` : 'Add community';
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-5xl space-y-8 p-4">
                <Heading
                    title={title}
                    description={`${lga.name} LGA, ${lga.state.name} State`}
                />

                <div className="grid gap-8 lg:grid-cols-2">
                    <section className="grid content-start gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(event) =>
                                    form.setData('name', event.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                Other names
                            </legend>
                            <p className="text-xs text-muted-foreground">
                                Alternative spellings and former names; the
                                search matches them too.
                            </p>
                            {form.data.other_names.map((name, index) => (
                                <div key={index} className="flex gap-2">
                                    <Input
                                        aria-label={`Other name ${index + 1}`}
                                        value={name}
                                        onChange={(event) =>
                                            form.setData(
                                                'other_names',
                                                form.data.other_names.map(
                                                    (item, i) =>
                                                        i === index
                                                            ? event.target.value
                                                            : item,
                                                ),
                                            )
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove this name"
                                        onClick={() =>
                                            form.setData(
                                                'other_names',
                                                form.data.other_names.filter(
                                                    (_, i) => i !== index,
                                                ),
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        form.setData('other_names', [
                                            ...form.data.other_names,
                                            '',
                                        ])
                                    }
                                >
                                    <Plus /> Add a name
                                </Button>
                            </div>
                            <InputError
                                message={
                                    errors.other_names ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('other_names.'),
                                    )?.[1]
                                }
                            />
                        </fieldset>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="type">Type</Label>
                                <NativeSelect
                                    id="type"
                                    value={form.data.type}
                                    onChange={(event) =>
                                        form.setData('type', event.target.value)
                                    }
                                >
                                    {types.map((type) => (
                                        <option
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={form.errors.type} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="ward">Ward</Label>
                                <NativeSelect
                                    id="ward"
                                    value={form.data.ward}
                                    onChange={(event) =>
                                        form.setData('ward', event.target.value)
                                    }
                                    disabled={wards.length === 0}
                                >
                                    <option value="">
                                        {wards.length === 0
                                            ? 'No wards recorded'
                                            : 'Not specified'}
                                    </option>
                                    {wards.map((ward) => (
                                        <option
                                            key={ward.ulid}
                                            value={ward.ulid}
                                        >
                                            {ward.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={form.errors.ward} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="population_estimate">
                                Population (estimate)
                            </Label>
                            <Input
                                id="population_estimate"
                                inputMode="numeric"
                                value={form.data.population_estimate}
                                onChange={(event) =>
                                    form.setData(
                                        'population_estimate',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.population_estimate}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Notes</Label>
                            <Textarea
                                id="notes"
                                rows={4}
                                maxLength={5000}
                                value={form.data.notes}
                                onChange={(event) =>
                                    form.setData('notes', event.target.value)
                                }
                            />
                            <InputError message={form.errors.notes} />
                        </div>

                        <LinkedPersonField
                            id="traditional_ruler"
                            label="Traditional ruler"
                            role="traditional_ruler"
                            roleLabel="traditional ruler"
                            current={community?.traditional_ruler ?? null}
                            value={ruler}
                            onChange={setRuler}
                            error={errors.traditional_ruler}
                        />
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
                            outline={map.outline}
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
                        {editing ? 'Save' : 'Add community'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                editing
                                    ? CommunityController.show(community.ulid)
                                    : LgaController.show(lga.ulid, {
                                          query: { tab: 'communities' },
                                      })
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

CommunityForm.layout = {
    breadcrumbs: [{ title: 'Communities', href: CommunityController.index() }],
};
