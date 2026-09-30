import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import OrganizationContactController from '@/actions/App/Http/Controllers/Organizations/OrganizationContactController';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import OrganizationSiteController from '@/actions/App/Http/Controllers/Organizations/OrganizationSiteController';
import SiteFormationController from '@/actions/App/Http/Controllers/Organizations/SiteFormationController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import AgencyFormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import InputError from '@/components/input-error';
import NativeSelect from '@/components/native-select';
import {
    RelevanceBadge,
    SitesMap,
} from '@/components/organizations/site-parts';
import Pagination from '@/components/pagination';
import PersonPicker from '@/components/persons/person-picker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ActivityTable from '@/components/users/activity-table';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { MapView } from '@/types/dashboard';
import type { NamedRef, Option } from '@/types/local-government';
import type {
    ContactDetail,
    FormationLink,
    OrganizationOptions,
    SiteDetail,
} from '@/types/organizations';
import type { Paginated } from '@/types/pagination';
import type { ActivityEntry } from '@/types/users';

type Tab = 'overview' | 'history';

type Props = {
    site: SiteDetail;
    contacts: ContactDetail[];
    formations: FormationLink[];
    formationChoices: Option[];
    tab: Tab;
    history: Paginated<ActivityEntry> | null;
    options: OrganizationOptions;
    map: MapView;
    can: { update: boolean; delete: boolean };
};

/**
 * An organization site: details, security, government security and
 * contacts. Each contact shown here is recorded in the access log.
 */
export default function OrganizationSiteShow({
    site,
    contacts,
    formations,
    formationChoices,
    tab,
    history,
    options,
    map,
    can,
}: Props) {
    const [deleting, setDeleting] = useState(false);
    const [editingContact, setEditingContact] = useState<
        ContactDetail | 'new' | null
    >(null);
    const [deletingContact, setDeletingContact] =
        useState<ContactDetail | null>(null);
    const [unlinking, setUnlinking] = useState<FormationLink | null>(null);

    return (
        <>
            <Head title={site.name} />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {site.name}
                            </h1>
                            <ClassificationBadge
                                level={site.classification.value}
                            />
                            <RelevanceBadge
                                relevance={site.security_relevance}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            <Link
                                href={OrganizationController.show(
                                    site.organization.ulid,
                                )}
                                className="hover:underline"
                            >
                                {site.organization.name}
                            </Link>{' '}
                            · {site.site_type.label} · {site.status.label} ·{' '}
                            {site.lga.name} LGA, {site.state.name} State
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={OrganizationSiteController.edit(
                                        site.ulid,
                                    )}
                                >
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="outline"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 /> Delete
                            </Button>
                        )}
                    </div>
                </div>

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {(['overview', 'history'] as Tab[]).map((name) => (
                        <Link
                            key={name}
                            href={OrganizationSiteController.show(site.ulid, {
                                query: { tab: name },
                            })}
                            preserveScroll
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === name ? 'page' : undefined}
                        >
                            {name === 'overview' ? 'Overview' : 'History'}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                        <div className="space-y-6">
                            <dl className="grid gap-4 rounded-lg border p-4 sm:grid-cols-2">
                                <Fact
                                    label="Community"
                                    value={site.community?.name ?? null}
                                />
                                <Fact
                                    label="Employees (estimate)"
                                    value={
                                        site.employees_estimate?.toLocaleString(
                                            'en-NG',
                                        ) ?? null
                                    }
                                />
                                <div className="sm:col-span-2">
                                    <Fact
                                        label="Address"
                                        value={site.address}
                                    />
                                </div>
                                <Fact
                                    label="Private security"
                                    value={
                                        site.has_private_security ? 'Yes' : 'No'
                                    }
                                />
                                <div>
                                    <dt className="text-xs text-muted-foreground">
                                        Provider
                                    </dt>
                                    <dd className="text-sm">
                                        {site.security_provider ? (
                                            <>
                                                <Link
                                                    href={OrganizationController.show(
                                                        site.security_provider
                                                            .ulid,
                                                    )}
                                                    className="hover:underline"
                                                >
                                                    {
                                                        site.security_provider
                                                            .name
                                                    }
                                                </Link>
                                                {!site.security_provider
                                                    .is_private_security && (
                                                    <div className="text-xs text-amber-700 dark:text-amber-300">
                                                        Not in the private
                                                        security sector
                                                    </div>
                                                )}
                                            </>
                                        ) : (
                                            '—'
                                        )}
                                    </dd>
                                </div>
                                <div className="sm:col-span-2">
                                    <Fact
                                        label="Security notes"
                                        value={site.security_notes}
                                    />
                                </div>
                            </dl>

                            <section className="space-y-2">
                                <h2 className="text-sm font-semibold">
                                    Government security
                                </h2>
                                {formations.length === 0 ? (
                                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                                        No formation recorded as protecting this
                                        site.
                                    </p>
                                ) : (
                                    <ul className="divide-y rounded-lg border">
                                        {formations.map((link) => (
                                            <li
                                                key={link.ulid}
                                                className="flex items-start justify-between gap-2 p-3 text-sm"
                                            >
                                                <div>
                                                    <Link
                                                        href={AgencyFormationController.show(
                                                            link.formation.ulid,
                                                        )}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {link.formation.name}
                                                    </Link>{' '}
                                                    <span className="text-muted-foreground">
                                                        ·{' '}
                                                        {link.formation.agency
                                                            .short_name ||
                                                            link.formation
                                                                .agency
                                                                .name}{' '}
                                                        ·{' '}
                                                        {link.arrangement.label}
                                                    </span>
                                                    {link.notes && (
                                                        <div className="text-xs">
                                                            {link.notes}
                                                        </div>
                                                    )}
                                                </div>
                                                {can.update && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setUnlinking(link)
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {can.update && (
                                    <LinkFormationForm
                                        siteUlid={site.ulid}
                                        choices={formationChoices}
                                        arrangements={options.arrangements}
                                    />
                                )}
                            </section>

                            <section
                                className="space-y-2"
                                aria-label="Contacts"
                            >
                                <div className="flex items-center justify-between">
                                    <h2 className="text-sm font-semibold">
                                        Contacts
                                    </h2>
                                    {can.update && editingContact === null && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setEditingContact('new')
                                            }
                                        >
                                            <Plus /> Add contact
                                        </Button>
                                    )}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    Viewing contacts is recorded in the access
                                    log.
                                </p>
                                {editingContact !== null && (
                                    <ContactForm
                                        siteUlid={site.ulid}
                                        contact={
                                            editingContact === 'new'
                                                ? null
                                                : editingContact
                                        }
                                        onDone={() => setEditingContact(null)}
                                    />
                                )}
                                {contacts.length === 0 ? (
                                    <p className="rounded-lg border p-3 text-sm text-muted-foreground">
                                        No contacts recorded.
                                    </p>
                                ) : (
                                    <ul
                                        className="divide-y rounded-lg border"
                                        data-test="contacts"
                                    >
                                        {contacts.map((c) => (
                                            <li
                                                key={c.ulid}
                                                className="flex items-start justify-between gap-2 p-3 text-sm"
                                            >
                                                <div className="space-y-0.5">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <span className="font-medium">
                                                            {c.name}
                                                        </span>
                                                        <span className="text-muted-foreground">
                                                            · {c.position}
                                                        </span>
                                                        {c.is_primary && (
                                                            <Badge>
                                                                Primary
                                                            </Badge>
                                                        )}
                                                        {c.is_management && (
                                                            <Badge variant="outline">
                                                                Management
                                                            </Badge>
                                                        )}
                                                        {c.is_security_contact && (
                                                            <Badge variant="outline">
                                                                Security
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    <div>
                                                        {[c.phone, c.email]
                                                            .filter(Boolean)
                                                            .join(' · ') || '—'}
                                                    </div>
                                                    {c.person && (
                                                        <div className="text-xs">
                                                            Person on record:{' '}
                                                            <Link
                                                                href={PersonController.show(
                                                                    c.person
                                                                        .ulid,
                                                                )}
                                                                className="hover:underline"
                                                            >
                                                                {c.person.name}
                                                            </Link>
                                                        </div>
                                                    )}
                                                    {c.notes && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {c.notes}
                                                        </div>
                                                    )}
                                                </div>
                                                {can.update && (
                                                    <div className="flex gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setEditingContact(
                                                                    c,
                                                                )
                                                            }
                                                        >
                                                            Edit
                                                        </Button>
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                setDeletingContact(
                                                                    c,
                                                                )
                                                            }
                                                        >
                                                            Delete
                                                        </Button>
                                                    </div>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </div>

                        <SitesMap
                            sites={[site]}
                            center={map.center}
                            zoom={map.zoom}
                        />
                    </div>
                )}

                {tab === 'history' && history && (
                    <div className="space-y-2">
                        <ActivityTable entries={history.data} />
                        <Pagination page={history} />
                    </div>
                )}
            </div>

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDeleting}
                    title={`Delete "${site.name}"?`}
                    description="The site is removed from lists and maps. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={OrganizationSiteController.destroy(site.ulid).url}
                />
            )}
            {deletingContact && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDeletingContact(null)}
                    title={`Delete contact ${deletingContact.name}?`}
                    description="The contact is removed. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={
                        OrganizationContactController.destroy(
                            deletingContact.ulid,
                        ).url
                    }
                />
            )}
            {unlinking && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setUnlinking(null)}
                    title={`Remove ${unlinking.formation.name}?`}
                    description="The formation will no longer be recorded as protecting this site."
                    confirmLabel="Remove"
                    method="delete"
                    url={
                        SiteFormationController.destroy({
                            site: site.ulid,
                            link: unlinking.ulid,
                        }).url
                    }
                />
            )}
        </>
    );
}

function LinkFormationForm({
    siteUlid,
    choices,
    arrangements,
}: {
    siteUlid: string;
    choices: Option[];
    arrangements: Option[];
}) {
    const form = useForm({
        formation: '',
        arrangement: 'stationed_on_site',
        notes: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(SiteFormationController.store(siteUlid).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    if (choices.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                No other formations you can view in this state.
            </p>
        );
    }

    return (
        <form
            onSubmit={submit}
            className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[2fr_1fr_auto] sm:items-end"
        >
            <div className="grid gap-1">
                <Label htmlFor="link-formation">Formation</Label>
                <NativeSelect
                    id="link-formation"
                    value={form.data.formation}
                    onChange={(e) => form.setData('formation', e.target.value)}
                >
                    <option value="">Choose a formation</option>
                    {choices.map((c) => (
                        <option key={c.value} value={c.value}>
                            {c.label}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={form.errors.formation} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="link-arrangement">Arrangement</Label>
                <NativeSelect
                    id="link-arrangement"
                    value={form.data.arrangement}
                    onChange={(e) =>
                        form.setData('arrangement', e.target.value)
                    }
                >
                    {arrangements.map((a) => (
                        <option key={a.value} value={a.value}>
                            {a.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            <Button
                type="submit"
                disabled={form.processing || form.data.formation === ''}
            >
                Add
            </Button>
        </form>
    );
}

function ContactForm({
    siteUlid,
    contact,
    onDone,
}: {
    siteUlid: string;
    contact: ContactDetail | null;
    onDone: () => void;
}) {
    const form = useForm({
        name: contact?.name ?? '',
        position: contact?.position ?? '',
        phone: contact?.phone ?? '',
        email: contact?.email ?? '',
        is_management: contact?.is_management ?? false,
        is_security_contact: contact?.is_security_contact ?? false,
        is_primary: contact?.is_primary ?? false,
        notes: contact?.notes ?? '',
    });
    // undefined: unchanged (a person the user cannot see is kept).
    const [person, setPerson] = useState<NamedRef | null | undefined>(
        contact?.person_hidden ? undefined : (contact?.person ?? null),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            ...(person !== undefined ? { person: person?.ulid ?? null } : {}),
        }));

        const options = { preserveScroll: true, onSuccess: onDone };

        if (contact) {
            form.put(
                OrganizationContactController.update(contact.ulid).url,
                options,
            );
        } else {
            form.post(
                OrganizationContactController.store(siteUlid).url,
                options,
            );
        }
    };

    const errors = form.errors as Record<string, string | undefined>;
    const text = (
        field: 'name' | 'position' | 'phone' | 'email',
        label: string,
    ) => (
        <div className="grid gap-1">
            <Label htmlFor={`contact-${field}`}>{label}</Label>
            <Input
                id={`contact-${field}`}
                type={field === 'email' ? 'email' : 'text'}
                value={form.data[field]}
                onChange={(e) => form.setData(field, e.target.value)}
            />
            <InputError message={errors[field]} />
        </div>
    );
    const flag = (
        field: 'is_management' | 'is_security_contact' | 'is_primary',
        label: string,
    ) => (
        <div className="flex items-center gap-2">
            <Checkbox
                id={`contact-${field}`}
                checked={form.data[field]}
                onCheckedChange={(checked) =>
                    form.setData(field, checked === true)
                }
            />
            <Label htmlFor={`contact-${field}`}>{label}</Label>
        </div>
    );

    return (
        <form onSubmit={submit} className="space-y-3 rounded-lg border p-3">
            <div className="grid gap-3 sm:grid-cols-2">
                {text('name', 'Name')}
                {text('position', 'Position (e.g. Security Manager)')}
                {text('phone', 'Phone')}
                {text('email', 'Email')}
            </div>
            <div className="flex flex-wrap gap-4">
                {flag('is_primary', 'Primary contact')}
                {flag('is_management', 'Management')}
                {flag('is_security_contact', 'Security contact')}
            </div>
            <InputError message={errors.is_primary} />
            <div className="grid gap-1">
                <Label htmlFor="contact-person">
                    Person on record (optional)
                </Label>
                {person === undefined ? (
                    <p className="text-sm text-muted-foreground">
                        A person you cannot view is linked.{' '}
                        <button
                            type="button"
                            className="underline"
                            onClick={() => setPerson(null)}
                        >
                            Replace
                        </button>
                    </p>
                ) : (
                    <PersonPicker
                        id="contact-person"
                        value={person}
                        onChange={setPerson}
                    />
                )}
                <InputError message={errors.person} />
            </div>
            <div className="grid gap-1">
                <Label htmlFor="contact-notes">Notes</Label>
                <Textarea
                    id="contact-notes"
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                />
            </div>
            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    {contact ? 'Save contact' : 'Add contact'}
                </Button>
                <Button type="button" variant="ghost" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm whitespace-pre-line">{value ?? '—'}</dd>
        </div>
    );
}

OrganizationSiteShow.layout = {
    breadcrumbs: [
        { title: 'Organizations', href: OrganizationController.index() },
    ],
};
