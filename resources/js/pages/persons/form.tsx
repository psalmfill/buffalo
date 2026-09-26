import { Head, Link, router, useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import ClassificationSelect from '@/components/community-information/classification-select';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import PersonAvatar from '@/components/persons/person-avatar';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { postJson } from '@/lib/http';
import type { ClassificationLevel } from '@/types/auth';
import type { Option } from '@/types/local-government';
import type {
    PersonDetail,
    PersonOptions,
    SimilarPerson,
} from '@/types/persons';

type Props = {
    person: PersonDetail | null;
    lgas: { ulid: string; name: string; state: string }[];
    selectedLga: string | null;
    communities: Option[];
    options: PersonOptions;
};

type RoleState = Record<string, { checked: boolean; notes: string }>;

/**
 * Create or edit a person. On create, possible duplicates within the
 * user's jurisdiction are shown before saving.
 */
export default function PersonForm({
    person,
    lgas,
    selectedLga,
    communities,
    options,
}: Props) {
    const editing = person !== null;
    const initialRoles: RoleState = Object.fromEntries(
        options.roles.map((role) => {
            const existing = person?.all_roles.find(
                (entry) => entry.role.value === role.value,
            );

            return [
                role.value,
                {
                    checked: existing?.is_active ?? false,
                    notes: existing?.notes ?? '',
                },
            ];
        }),
    );

    const form = useForm({
        lga: selectedLga ?? '',
        community: person?.community?.ulid ?? '',
        first_name: person?.first_name ?? '',
        last_name: person?.last_name ?? '',
        other_names: person?.other_names ?? '',
        gender: person?.gender.value ?? 'unknown',
        date_of_birth: person?.date_of_birth ?? '',
        approximate_age: person?.approximate_age?.toString() ?? '',
        nin: person?.nin ?? '',
        phone: person?.phone ?? '',
        address: person?.address ?? '',
        occupation: person?.occupation ?? '',
        nationality: person?.nationality ?? 'Nigerian',
        description: person?.description ?? '',
        classification: (person?.classification.value ??
            'restricted') as ClassificationLevel,
        is_deceased: person?.is_deceased ?? false,
        roles: initialRoles,
        photo: null as File | null,
        confirm_new: false,
    });

    const [similar, setSimilar] = useState<SimilarPerson[] | null>(null);
    const [checking, setChecking] = useState(false);

    const save = (confirmNew: boolean) => {
        form.transform((data) => {
            const roles = Object.entries(data.roles)
                .filter(([, state]) => state.checked)
                .map(([role, state]) => ({ role, notes: state.notes }));
            const { lga, photo, confirm_new, ...rest } = data;
            void confirm_new;

            return editing
                ? { ...rest, roles }
                : {
                      ...rest,
                      lga,
                      roles,
                      confirm_new: confirmNew,
                      ...(photo ? { photo } : {}),
                  };
        });

        if (editing) {
            form.put(PersonController.update(person.ulid).url);
        } else {
            form.post(PersonController.store().url, {
                onSuccess: () => setSimilar(null),
            });
        }
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();

        if (editing) {
            save(false);

            return;
        }

        setChecking(true);

        try {
            const { matches } = await postJson<{ matches: SimilarPerson[] }>(
                PersonController.similar().url,
                {
                    first_name: form.data.first_name,
                    last_name: form.data.last_name,
                    other_names: form.data.other_names || null,
                    nin: form.data.nin || null,
                    phone: form.data.phone || null,
                    date_of_birth: form.data.date_of_birth || null,
                },
            );

            if (matches.length > 0) {
                setSimilar(matches);
            } else {
                save(false);
            }
        } catch {
            // Validation (e.g. a missing name) is reported by the save.
            save(false);
        } finally {
            setChecking(false);
        }
    };

    const chooseLga = (ulid: string) => {
        form.setData((data) => ({ ...data, lga: ulid, community: '' }));
        router.reload({
            only: ['communities', 'selectedLga'],
            data: { lga: ulid },
        });
    };

    const text = (
        field:
            | 'first_name'
            | 'last_name'
            | 'other_names'
            | 'nin'
            | 'phone'
            | 'occupation'
            | 'nationality',
        label: string,
        extra: {
            required?: boolean;
            hint?: string;
            inputMode?: 'numeric' | 'tel';
        } = {},
    ) => (
        <div className="grid content-start gap-2">
            <Label htmlFor={field}>{label}</Label>
            <Input
                id={field}
                value={form.data[field]}
                required={extra.required}
                inputMode={extra.inputMode}
                autoComplete="off"
                onChange={(event) => form.setData(field, event.target.value)}
            />
            {extra.hint && (
                <p className="text-xs text-muted-foreground">{extra.hint}</p>
            )}
            <InputError message={form.errors[field]} />
        </div>
    );

    const title = editing ? `Edit ${person.name}` : 'Add person';
    const busy = form.processing || checking;

    return (
        <>
            <Head title={title} />
            <form onSubmit={submit} className="max-w-4xl space-y-8 p-4">
                <Heading
                    title={title}
                    description={
                        editing
                            ? `${person.lga.name} LGA, ${person.state.name} State`
                            : 'The record belongs to the LGA you choose; it cannot be moved later.'
                    }
                />

                <Alert>
                    <ShieldAlert />
                    <AlertDescription>
                        NIN, phone number, date of birth and address are
                        encrypted. Every view of this person is recorded.
                    </AlertDescription>
                </Alert>

                <section className="grid gap-4 sm:grid-cols-2">
                    {!editing && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="lga">LGA</Label>
                            <NativeSelect
                                id="lga"
                                value={form.data.lga}
                                onChange={(event) =>
                                    chooseLga(event.target.value)
                                }
                                required
                            >
                                <option value="">Select…</option>
                                {lgas.map((lga) => (
                                    <option key={lga.ulid} value={lga.ulid}>
                                        {lga.name} ({lga.state})
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.lga} />
                        </div>
                    )}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="community">Community</Label>
                        <NativeSelect
                            id="community"
                            value={form.data.community}
                            onChange={(event) =>
                                form.setData('community', event.target.value)
                            }
                        >
                            <option value="">Not recorded</option>
                            {communities.map((community) => (
                                <option
                                    key={community.value}
                                    value={community.value}
                                >
                                    {community.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.community} />
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-3">
                    {text('first_name', 'First name', { required: true })}
                    {text('other_names', 'Other names')}
                    {text('last_name', 'Last name', { required: true })}
                    <div className="grid content-start gap-2">
                        <Label htmlFor="gender">Gender</Label>
                        <NativeSelect
                            id="gender"
                            value={form.data.gender}
                            onChange={(event) =>
                                form.setData('gender', event.target.value)
                            }
                        >
                            {options.genders.map((gender) => (
                                <option key={gender.value} value={gender.value}>
                                    {gender.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.gender} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="date_of_birth">Date of birth</Label>
                        <Input
                            id="date_of_birth"
                            type="date"
                            value={form.data.date_of_birth}
                            onChange={(event) =>
                                form.setData(
                                    'date_of_birth',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.date_of_birth} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="approximate_age">Approximate age</Label>
                        <Input
                            id="approximate_age"
                            inputMode="numeric"
                            disabled={form.data.date_of_birth !== ''}
                            value={form.data.approximate_age}
                            onChange={(event) =>
                                form.setData(
                                    'approximate_age',
                                    event.target.value,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            When the date of birth is unknown.
                        </p>
                        <InputError message={form.errors.approximate_age} />
                    </div>
                    {text('nin', 'NIN', {
                        inputMode: 'numeric',
                        hint: '11 digits.',
                    })}
                    {text('phone', 'Phone number', {
                        inputMode: 'tel',
                        hint: 'e.g. 0803 123 4567 or +234 803 123 4567.',
                    })}
                    {text('occupation', 'Occupation')}
                    {text('nationality', 'Nationality')}
                    <div className="flex items-center gap-2 self-center">
                        <Checkbox
                            id="is_deceased"
                            checked={form.data.is_deceased}
                            onCheckedChange={(checked) =>
                                form.setData('is_deceased', checked === true)
                            }
                        />
                        <Label htmlFor="is_deceased">Deceased</Label>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="address">Address</Label>
                        <Textarea
                            id="address"
                            rows={3}
                            maxLength={1000}
                            value={form.data.address}
                            onChange={(event) =>
                                form.setData('address', event.target.value)
                            }
                        />
                        <InputError message={form.errors.address} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="description">
                            Description (physical description, distinguishing
                            marks)
                        </Label>
                        <Textarea
                            id="description"
                            rows={3}
                            maxLength={5000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                        <InputError message={form.errors.description} />
                    </div>
                </section>

                <fieldset className="space-y-3">
                    <legend className="text-sm font-medium">Roles</legend>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {options.roles.map((role) => {
                            const state = form.data.roles[role.value];

                            return (
                                <div
                                    key={role.value}
                                    className="space-y-2 rounded-md border p-3"
                                >
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`role-${role.value}`}
                                            checked={state.checked}
                                            onCheckedChange={(checked) =>
                                                form.setData('roles', {
                                                    ...form.data.roles,
                                                    [role.value]: {
                                                        ...state,
                                                        checked:
                                                            checked === true,
                                                    },
                                                })
                                            }
                                        />
                                        <Label htmlFor={`role-${role.value}`}>
                                            {role.label}
                                        </Label>
                                    </div>
                                    {state.checked && (
                                        <Input
                                            aria-label={`${role.label} notes`}
                                            placeholder="Notes (optional)"
                                            value={state.notes}
                                            onChange={(event) =>
                                                form.setData('roles', {
                                                    ...form.data.roles,
                                                    [role.value]: {
                                                        ...state,
                                                        notes: event.target
                                                            .value,
                                                    },
                                                })
                                            }
                                        />
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Persons of interest are recorded in the persons of
                        interest module.
                    </p>
                    <InputError
                        message={
                            form.errors.roles ??
                            Object.entries(form.errors).find(([key]) =>
                                key.startsWith('roles.'),
                            )?.[1]
                        }
                    />
                </fieldset>

                <section className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="classification">Classification</Label>
                        <ClassificationSelect
                            value={form.data.classification}
                            onChange={(level) =>
                                form.setData('classification', level)
                            }
                        />
                        <InputError message={form.errors.classification} />
                    </div>
                    {!editing && (
                        <div className="grid content-start gap-2">
                            <Label htmlFor="photo">Photo</Label>
                            <Input
                                id="photo"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(event) =>
                                    form.setData(
                                        'photo',
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                JPEG, PNG or WebP, up to 10 MB. Location and
                                camera data are removed.
                            </p>
                            <InputError message={form.errors.photo} />
                        </div>
                    )}
                </section>

                <InputError message={form.errors.confirm_new} />

                <div className="flex gap-2">
                    <Button type="submit" disabled={busy}>
                        {busy && <Spinner />}
                        {editing ? 'Save' : 'Add person'}
                    </Button>
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                editing
                                    ? PersonController.show(person.ulid)
                                    : PersonController.index()
                            }
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>

            <Dialog
                open={similar !== null}
                onOpenChange={(open) => !open && setSimilar(null)}
            >
                <DialogContent className="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Possible duplicates</DialogTitle>
                        <DialogDescription>
                            These persons in your jurisdiction may be the same
                            person. Open the existing record if so.
                        </DialogDescription>
                    </DialogHeader>
                    <ul className="max-h-96 divide-y overflow-y-auto rounded-md border">
                        {similar?.map((match) => (
                            <li
                                key={match.ulid}
                                className="flex items-center justify-between gap-3 p-3"
                            >
                                <div className="flex items-center gap-3">
                                    <PersonAvatar
                                        src={match.thumbnail}
                                        name={match.name}
                                    />
                                    <div className="text-sm">
                                        <div className="font-medium">
                                            {match.name}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {match.lga?.name} LGA ·{' '}
                                            {match.reasons.join(', ')}
                                        </div>
                                    </div>
                                    <ClassificationBadge
                                        level={match.classification.value}
                                    />
                                </div>
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={PersonController.show(match.ulid)}
                                    >
                                        This is the same person — open existing
                                        record
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setSimilar(null)}
                        >
                            Back to the form
                        </Button>
                        <Button
                            onClick={() => save(true)}
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            Create new record anyway
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

PersonForm.layout = {
    breadcrumbs: [{ title: 'Persons', href: PersonController.index() }],
};
