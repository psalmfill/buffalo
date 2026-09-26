import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Upload } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonAliasController from '@/actions/App/Http/Controllers/Persons/PersonAliasController';
import PersonAssociationController from '@/actions/App/Http/Controllers/Persons/PersonAssociationController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonPhotoController from '@/actions/App/Http/Controllers/Persons/PersonPhotoController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import Pagination from '@/components/pagination';
import PersonAvatar from '@/components/persons/person-avatar';
import PersonPicker from '@/components/persons/person-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { NamedRef } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type {
    PersonAlias,
    PersonAssociationRow,
    PersonDetail,
    PersonOptions,
    PersonPhoto,
} from '@/types/persons';
import type { ActivityEntry } from '@/types/users';

type PersonTab = 'profile' | 'aliases' | 'associations' | 'photos' | 'history';

type Props = {
    person: PersonDetail;
    photo: PersonPhoto | null;
    tab: PersonTab;
    tabs: PersonTab[];
    options: PersonOptions;
    aliases: PersonAlias[] | null;
    associations: PersonAssociationRow[] | null;
    photos: PersonPhoto[] | null;
    history: Paginated<ActivityEntry> | null;
    can: { update: boolean; delete: boolean };
};

// 3B adds political offices, POI details and group memberships; Phase 5
// linked incidents.
const TAB_LABELS: Record<PersonTab, string> = {
    profile: 'Profile',
    aliases: 'Aliases',
    associations: 'Associations',
    photos: 'Photos',
    history: 'History',
};

function Fact({
    label,
    value,
    protectedField = false,
}: {
    label: string;
    value: string | null;
    protectedField?: boolean;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">
                {label}
                {protectedField && (
                    <span className="ml-1 text-[10px] tracking-wide uppercase">
                        (encrypted)
                    </span>
                )}
            </dt>
            <dd className="text-sm whitespace-pre-line">{value ?? '—'}</dd>
        </div>
    );
}

function AliasesPanel({
    person,
    aliases,
    canUpdate,
}: {
    person: PersonDetail;
    aliases: PersonAlias[];
    canUpdate: boolean;
}) {
    const form = useForm({ alias: '' });
    const [removing, setRemoving] = useState<PersonAlias | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(PersonAliasController.store(person.ulid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="max-w-2xl space-y-4">
            {canUpdate && (
                <form onSubmit={submit} className="flex items-start gap-2">
                    <div className="flex-1 space-y-1">
                        <Input
                            aria-label="New alias"
                            placeholder="Another name this person is known by"
                            value={form.data.alias}
                            maxLength={150}
                            onChange={(event) =>
                                form.setData('alias', event.target.value)
                            }
                        />
                        <InputError message={form.errors.alias} />
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        <Plus /> Add alias
                    </Button>
                </form>
            )}
            {aliases.length === 0 ? (
                <div className="rounded-lg border">
                    <EmptyState
                        title="No aliases"
                        description="Aliases are searched like names."
                    />
                </div>
            ) : (
                <ul className="divide-y rounded-lg border">
                    {aliases.map((alias) => (
                        <li
                            key={alias.ulid}
                            className="flex items-center justify-between px-3 py-2 text-sm"
                        >
                            <span>{alias.alias}</span>
                            {canUpdate && (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove alias ${alias.alias}`}
                                    onClick={() => setRemoving(alias)}
                                >
                                    <Trash2 />
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {removing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setRemoving(null)}
                    title={`Remove the alias "${removing.alias}"?`}
                    description="The alias is no longer searched. The change is recorded in the history."
                    confirmLabel="Remove"
                    destructive
                    method="delete"
                    url={
                        PersonAliasController.destroy({
                            person: person.ulid,
                            alias: removing.ulid,
                        }).url
                    }
                />
            )}
        </div>
    );
}

function AssociationsPanel({
    person,
    associations,
    relationships,
    canUpdate,
}: {
    person: PersonDetail;
    associations: PersonAssociationRow[];
    relationships: PersonOptions['relationships'];
    canUpdate: boolean;
}) {
    const [other, setOther] = useState<NamedRef | null>(null);
    const form = useForm({ person: '', relationship: '', notes: '' });
    const [removing, setRemoving] = useState<PersonAssociationRow | null>(null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, person: other?.ulid ?? '' }));
        form.post(PersonAssociationController.store(person.ulid).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOther(null);
            },
        });
    };

    return (
        <div className="space-y-4">
            {canUpdate && (
                <form
                    onSubmit={submit}
                    className="grid gap-4 rounded-lg border p-4 md:grid-cols-[2fr_1fr_2fr_auto] md:items-end"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="associated-person">Person</Label>
                        <PersonPicker
                            id="associated-person"
                            value={other}
                            onChange={setOther}
                            except={person.ulid}
                        />
                        <InputError message={form.errors.person} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="relationship">
                            Is {person.first_name}'s
                        </Label>
                        <NativeSelect
                            id="relationship"
                            value={form.data.relationship}
                            onChange={(event) =>
                                form.setData('relationship', event.target.value)
                            }
                            required
                        >
                            <option value="">Select…</option>
                            {relationships.map((relationship) => (
                                <option
                                    key={relationship.value}
                                    value={relationship.value}
                                >
                                    {relationship.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={form.errors.relationship} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="association-notes">Notes</Label>
                        <Input
                            id="association-notes"
                            value={form.data.notes}
                            maxLength={1000}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || other === null}
                    >
                        <Plus /> Link
                    </Button>
                </form>
            )}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Person</th>
                            <th className="px-3 py-2 font-medium">
                                Relationship to {person.first_name}
                            </th>
                            <th className="px-3 py-2 font-medium">Notes</th>
                            <th className="px-3 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {associations.length === 0 && (
                            <tr>
                                <td colSpan={4} className="p-0">
                                    <EmptyState
                                        title="No associations"
                                        description="Links to persons you can view appear here, from both sides."
                                    />
                                </td>
                            </tr>
                        )}
                        {associations.map((association) => (
                            <tr key={association.ulid}>
                                <td className="px-3 py-2">
                                    <Link
                                        href={PersonController.show(
                                            association.person.ulid,
                                        )}
                                        className="flex items-center gap-3 font-medium hover:underline"
                                    >
                                        <PersonAvatar
                                            src={association.person.thumbnail}
                                            name={association.person.name}
                                            className="size-8"
                                        />
                                        {association.person.name}
                                    </Link>
                                </td>
                                <td className="px-3 py-2">
                                    {association.relationship.label}
                                </td>
                                <td className="px-3 py-2 text-muted-foreground">
                                    {association.notes ?? '—'}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    {canUpdate && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove the link with ${association.person.name}`}
                                            onClick={() =>
                                                setRemoving(association)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {removing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setRemoving(null)}
                    title={`Remove the link with ${removing.person.name}?`}
                    description="The link is removed from both persons. The change is recorded in both histories."
                    confirmLabel="Remove"
                    destructive
                    method="delete"
                    url={
                        PersonAssociationController.destroy({
                            person: person.ulid,
                            association: removing.ulid,
                        }).url
                    }
                />
            )}
        </div>
    );
}

function PhotosPanel({
    person,
    photos,
    canUpdate,
}: {
    person: PersonDetail;
    photos: PersonPhoto[];
    canUpdate: boolean;
}) {
    const form = useForm({ photo: null as File | null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(PersonPhotoController.store(person.ulid).url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <div className="space-y-4">
            {canUpdate && (
                <form
                    onSubmit={submit}
                    className="flex flex-wrap items-start gap-2 rounded-lg border p-4"
                >
                    <div className="space-y-1">
                        <Input
                            type="file"
                            aria-label="New photo"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={(event) =>
                                form.setData(
                                    'photo',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            JPEG, PNG or WebP, up to 10 MB. Becomes the current
                            photo; location and camera data are removed.
                        </p>
                        <InputError message={form.errors.photo} />
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || form.data.photo === null}
                    >
                        {form.processing ? <Spinner /> : <Upload />} Upload
                    </Button>
                </form>
            )}
            {photos.length === 0 ? (
                <div className="rounded-lg border">
                    <EmptyState
                        title="No photos"
                        description="Photos are kept: a new photo becomes the current one."
                    />
                </div>
            ) : (
                <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    {photos.map((photo) => (
                        <li key={photo.ulid} className="space-y-1">
                            <a
                                href={photo.original}
                                target="_blank"
                                rel="noreferrer"
                                className="block overflow-hidden rounded-md border"
                            >
                                <img
                                    src={photo.thumbnail}
                                    alt={`Photo of ${person.name}`}
                                    className="aspect-square w-full object-cover"
                                    loading="lazy"
                                />
                            </a>
                            <div className="flex items-center justify-between text-xs text-muted-foreground">
                                <span>
                                    {photo.added_at
                                        ? new Date(
                                              photo.added_at,
                                          ).toLocaleDateString('en-NG')
                                        : ''}
                                </span>
                                {photo.current && (
                                    <Badge variant="secondary">Current</Badge>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * The person page. Every visit is recorded in the access log. Protected
 * fields are decrypted only here, for users the policy allows.
 */
export default function PersonShow({
    person,
    photo,
    tab,
    tabs,
    options,
    aliases,
    associations,
    photos,
    history,
    can,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const activeRoles = person.all_roles.filter((role) => role.is_active);

    return (
        <>
            <Head title={person.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <PersonAvatar
                            src={photo?.preview ?? null}
                            name={person.name}
                            className="size-20"
                        />
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-semibold">
                                    {person.name}
                                </h1>
                                <ClassificationBadge
                                    level={person.classification.value}
                                />
                                {person.is_deceased && (
                                    <Badge variant="secondary">Deceased</Badge>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {person.lga.name} LGA, {person.state.name} State
                                {person.community &&
                                    ` · ${person.community.name}`}
                            </p>
                            {activeRoles.length > 0 && (
                                <div className="flex flex-wrap gap-1">
                                    {activeRoles.map((role) => (
                                        <Badge
                                            key={role.role.value}
                                            variant="outline"
                                        >
                                            {role.role.label}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link href={PersonController.edit(person.ulid)}>
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="destructive"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav
                    className="flex gap-1 overflow-x-auto border-b"
                    aria-label="Person sections"
                >
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={PersonController.show(person.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {TAB_LABELS[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'profile' && (
                    <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
                        <dl className="grid content-start gap-4 rounded-lg border p-4 sm:grid-cols-3">
                            <Fact
                                label="First name"
                                value={person.first_name}
                            />
                            <Fact
                                label="Other names"
                                value={person.other_names}
                            />
                            <Fact label="Last name" value={person.last_name} />
                            <Fact label="Gender" value={person.gender.label} />
                            <Fact
                                label="Date of birth"
                                value={person.date_of_birth}
                                protectedField
                            />
                            <Fact
                                label="Approximate age"
                                value={
                                    person.approximate_age?.toString() ?? null
                                }
                            />
                            <Fact
                                label="NIN"
                                value={person.nin}
                                protectedField
                            />
                            <Fact
                                label="Phone number"
                                value={person.phone}
                                protectedField
                            />
                            <Fact
                                label="Occupation"
                                value={person.occupation}
                            />
                            <Fact
                                label="Nationality"
                                value={person.nationality}
                            />
                            <div className="sm:col-span-2">
                                <Fact
                                    label="Address"
                                    value={person.address}
                                    protectedField
                                />
                            </div>
                            <div className="sm:col-span-3">
                                <Fact
                                    label="Description"
                                    value={person.description}
                                />
                            </div>
                        </dl>
                        <div className="space-y-4">
                            <section className="rounded-lg border p-4">
                                <h2 className="mb-2 text-sm font-medium">
                                    Roles
                                </h2>
                                {person.all_roles.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        None recorded.
                                    </p>
                                ) : (
                                    <ul className="space-y-2 text-sm">
                                        {person.all_roles.map((role) => (
                                            <li key={role.role.value}>
                                                <span
                                                    className={cn(
                                                        !role.is_active &&
                                                            'text-muted-foreground line-through',
                                                    )}
                                                >
                                                    {role.role.label}
                                                </span>
                                                {!role.is_active && (
                                                    <span className="ml-1 text-xs text-muted-foreground">
                                                        (former)
                                                    </span>
                                                )}
                                                {role.notes && (
                                                    <div className="text-xs text-muted-foreground">
                                                        {role.notes}
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                            <section className="rounded-lg border p-4">
                                <h2 className="mb-2 text-sm font-medium">
                                    Also known as
                                </h2>
                                <p className="text-sm">
                                    {aliases && aliases.length > 0
                                        ? aliases.map((a) => a.alias).join(', ')
                                        : '—'}
                                </p>
                            </section>
                        </div>
                    </div>
                )}

                {tab === 'aliases' && aliases && (
                    <AliasesPanel
                        person={person}
                        aliases={aliases}
                        canUpdate={can.update}
                    />
                )}
                {tab === 'associations' && associations && (
                    <AssociationsPanel
                        person={person}
                        associations={associations}
                        relationships={options.relationships}
                        canUpdate={can.update}
                    />
                )}
                {tab === 'photos' && photos && (
                    <PhotosPanel
                        person={person}
                        photos={photos}
                        canUpdate={can.update}
                    />
                )}
                {tab === 'history' && history && (
                    <div className="space-y-3">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete ${person.name}?`}
                    description="The person is removed from searches and lists. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    url={PersonController.destroy(person.ulid).url}
                    method="delete"
                />
            )}
        </>
    );
}

PersonShow.layout = {
    breadcrumbs: [{ title: 'Persons', href: PersonController.index() }],
};
