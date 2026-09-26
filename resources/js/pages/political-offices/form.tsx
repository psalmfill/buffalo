import { Head, Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PoliticalOfficeController from '@/actions/App/Http/Controllers/Political/PoliticalOfficeController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { NamedRef, Option } from '@/types/local-government';
import type { OfficeTypeOption, PoliticalOffice } from '@/types/political';

type Props = {
    person: NamedRef;
    office: PoliticalOffice | null;
    officeTypes: OfficeTypeOption[];
    states: Option[];
    selectedState: string | null;
    districts: Option[];
    lgas: Option[];
    selectedLga: string | null;
    wards: Option[];
};

/**
 * Record or edit a political office. The fields shown follow the office
 * type: a district for a senator, an LGA for chairmen and councillors, a
 * ward for councillors. Overlapping holders of a seat are warned about
 * after saving, never refused.
 */
export default function PoliticalOfficeForm({
    person,
    office,
    officeTypes,
    states,
    selectedState,
    districts,
    lgas,
    selectedLga,
    wards,
}: Props) {
    const editing = office !== null;
    const form = useForm({
        office: office?.office.value ?? '',
        office_title: office?.office_title ?? '',
        party: office?.party ?? '',
        state: selectedState ?? '',
        senatorial_district: office?.senatorial_district?.ulid ?? '',
        lga: selectedLga ?? '',
        ward: office?.ward?.ulid ?? '',
        constituency_name: office?.constituency_name ?? '',
        started_on: office?.started_on ?? '',
        ended_on: office?.ended_on ?? '',
    });

    const type = officeTypes.find((t) => t.value === form.data.office);
    const showDistrict =
        type?.value === 'senator' || type === undefined || !type.requiresLga;
    const showWard = type?.requiresWard === true;
    const showConstituency =
        type?.value === 'house_of_reps_member' ||
        type?.value === 'state_assembly_member';

    const reload = (data: Record<string, string>) =>
        router.reload({
            only: [
                'districts',
                'lgas',
                'selectedLga',
                'wards',
                'selectedState',
            ],
            data,
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            senatorial_district: data.senatorial_district || null,
            lga: data.lga || null,
            ward: data.ward || null,
            ended_on: data.ended_on || null,
        }));

        if (editing) {
            form.put(PoliticalOfficeController.update(office.ulid).url);
        } else {
            form.post(PoliticalOfficeController.store(person.ulid).url);
        }
    };

    const select = (
        field: 'office' | 'state' | 'senatorial_district' | 'lga' | 'ward',
        label: string,
        options: Option[],
        placeholder: string,
        onChange: (value: string) => void,
        required = false,
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={field}>{label}</Label>
            <NativeSelect
                id={field}
                value={form.data[field]}
                required={required}
                onChange={(event) => onChange(event.target.value)}
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

    const title = editing ? `Edit ${office.title}` : 'Record political office';

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-3xl space-y-6 p-4">
                <Heading title={title} description={person.name} />

                <div className="grid gap-4 sm:grid-cols-2">
                    {select(
                        'office',
                        'Office',
                        officeTypes,
                        'Select…',
                        (v) => form.setData('office', v),
                        true,
                    )}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="office_title">
                            Title {type?.value === 'other' ? '' : '(optional)'}
                        </Label>
                        <Input
                            id="office_title"
                            placeholder="e.g. Commissioner for Works"
                            value={form.data.office_title}
                            onChange={(e) =>
                                form.setData('office_title', e.target.value)
                            }
                        />
                        <InputError message={form.errors.office_title} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="party">Party</Label>
                        <Input
                            id="party"
                            value={form.data.party}
                            onChange={(e) =>
                                form.setData('party', e.target.value)
                            }
                        />
                        <InputError message={form.errors.party} />
                    </div>
                    {select(
                        'state',
                        'State',
                        states,
                        'Select…',
                        (v) => {
                            form.setData((d) => ({
                                ...d,
                                state: v,
                                senatorial_district: '',
                                lga: '',
                                ward: '',
                            }));
                            reload({ state: v });
                        },
                        true,
                    )}
                    {showDistrict &&
                        select(
                            'senatorial_district',
                            type?.requiresDistrict
                                ? 'Senatorial district'
                                : 'Senatorial district (optional)',
                            districts,
                            'Select…',
                            (v) => form.setData('senatorial_district', v),
                            type?.requiresDistrict === true,
                        )}
                    {select(
                        'lga',
                        type?.requiresLga ? 'LGA' : 'LGA (optional)',
                        lgas,
                        'Select…',
                        (v) => {
                            form.setData((d) => ({ ...d, lga: v, ward: '' }));
                            reload({ state: form.data.state, lga: v });
                        },
                        type?.requiresLga === true,
                    )}
                    {showWard &&
                        select(
                            'ward',
                            'Ward',
                            wards,
                            'Select…',
                            (v) => form.setData('ward', v),
                            true,
                        )}
                    {showConstituency && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="constituency_name">
                                Constituency
                            </Label>
                            <Input
                                id="constituency_name"
                                value={form.data.constituency_name}
                                onChange={(e) =>
                                    form.setData(
                                        'constituency_name',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Choose the LGA the constituency is recorded
                                against, so the office appears on its district
                                page.
                            </p>
                            <InputError
                                message={form.errors.constituency_name}
                            />
                        </div>
                    )}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="started_on">Started</Label>
                        <Input
                            id="started_on"
                            type="date"
                            required
                            value={form.data.started_on}
                            onChange={(e) =>
                                form.setData('started_on', e.target.value)
                            }
                        />
                        <InputError message={form.errors.started_on} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="ended_on">
                            Ended (leave empty if current)
                        </Label>
                        <Input
                            id="ended_on"
                            type="date"
                            value={form.data.ended_on}
                            onChange={(e) =>
                                form.setData('ended_on', e.target.value)
                            }
                        />
                        <InputError message={form.errors.ended_on} />
                    </div>
                </div>

                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Save' : 'Record office'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={PersonController.show(person.ulid, {
                                query: { tab: 'political_offices' },
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

PoliticalOfficeForm.layout = {
    breadcrumbs: [{ title: 'Persons', href: PersonController.index() }],
};
