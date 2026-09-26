import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import ReligiousInstitutionController from '@/actions/App/Http/Controllers/CommunityInformation/ReligiousInstitutionController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import MapPointPicker, {
    coordinatesProblem,
} from '@/components/map/MapPointPicker';
import NativeSelect from '@/components/native-select';
import LinkedPersonField from '@/components/persons/linked-person-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import type { InstitutionRow } from '@/types/community-information';
import type { MapView } from '@/types/dashboard';
import type { LgaFacts, NamedRef, Option } from '@/types/local-government';

type Props = {
    lga: LgaFacts;
    institution: InstitutionRow | null;
    communities: Option[];
    selectedCommunity: string;
    religions: (Option & { denominations: Option[] })[];
    types: Option[];
    map: MapView;
};

/** Add or edit a religious institution in one of the LGA's communities. */
export default function ReligiousInstitutionForm({
    lga,
    institution,
    communities,
    selectedCommunity,
    religions,
    types,
    map,
}: Props) {
    const editing = institution !== null;
    const form = useForm({
        community: institution?.community.ulid ?? selectedCommunity,
        religion: institution?.religion.ulid ?? '',
        denomination: institution?.denomination?.ulid ?? '',
        name: institution?.name ?? '',
        type: institution?.type.value ?? '',
        estimated_congregation:
            institution?.estimated_congregation?.toString() ?? '',
        latitude: institution?.latitude?.toString() ?? '',
        longitude: institution?.longitude?.toString() ?? '',
        notes: institution?.notes ?? '',
    });
    // undefined: unchanged (a leader the user cannot see is kept).
    const [leader, setLeader] = useState<NamedRef | null | undefined>(
        institution?.leader.hidden
            ? undefined
            : (institution?.leader.person ?? null),
    );

    const denominations =
        religions.find((religion) => religion.value === form.data.religion)
            ?.denominations ?? [];

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
            denomination: data.denomination === '' ? null : data.denomination,
            ...(leader !== undefined ? { leader: leader?.ulid ?? null } : {}),
        }));

        if (editing) {
            form.put(
                ReligiousInstitutionController.update(institution.ulid).url,
            );
        } else {
            form.post(ReligiousInstitutionController.store(lga.ulid).url);
        }
    };

    const select = (
        field: 'community' | 'religion' | 'denomination' | 'type',
        label: string,
        options: Option[],
        placeholder: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                onChange={(event) => {
                    form.setData(field, event.target.value);

                    if (field === 'religion') {
                        form.setData((data) => ({
                            ...data,
                            religion: event.target.value,
                            denomination: '',
                        }));
                    }
                }}
            >
                <option value="">{placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </NativeSelect>
            <InputError message={form.errors[field]} />
        </div>
    );

    const title = editing
        ? `Edit ${institution.name}`
        : 'Add religious institution';

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
                        <div className="grid gap-4 sm:grid-cols-2">
                            {select('type', 'Type', types, 'Select…')}
                            {select(
                                'community',
                                'Community',
                                communities,
                                'Select…',
                            )}
                            {select(
                                'religion',
                                'Religion',
                                religions,
                                'Select…',
                            )}
                            {select(
                                'denomination',
                                'Denomination',
                                denominations,
                                denominations.length === 0
                                    ? 'None listed'
                                    : 'Not specified',
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="estimated_congregation">
                                Estimated congregation
                            </Label>
                            <Input
                                id="estimated_congregation"
                                inputMode="numeric"
                                value={form.data.estimated_congregation}
                                onChange={(event) =>
                                    form.setData(
                                        'estimated_congregation',
                                        event.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.estimated_congregation}
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
                            id="leader"
                            label="Leader"
                            role="religious_leader"
                            roleLabel="religious leader"
                            current={institution?.leader ?? null}
                            value={leader}
                            onChange={setLeader}
                            error={
                                (form.errors as Record<string, string>).leader
                            }
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
                        {editing ? 'Save' : 'Add institution'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={LgaController.show(lga.ulid, {
                                query: { tab: 'religious_institutions' },
                            })}
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

ReligiousInstitutionForm.layout = {
    breadcrumbs: [{ title: 'LGAs', href: LgaController.index() }],
};
