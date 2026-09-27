import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import HitsPanel from '@/components/access-requests/hits-panel';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import PersonAvatar from '@/components/persons/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type {
    AccessRequestOptions,
    CrossJurisdiction,
} from '@/types/access-requests';
import type { NamedRef } from '@/types/local-government';
import type {
    PersonOptions,
    PersonRow,
    PersonSearchFilters,
} from '@/types/persons';

type Props = {
    results: PersonRow[];
    truncated: boolean;
    mode: 'recent' | 'filtered' | 'number' | 'name';
    kind: 'none' | 'name' | 'nin' | 'phone' | 'nin_or_phone';
    filters: PersonSearchFilters;
    options: PersonOptions;
    states: NamedRef[];
    lgas: NamedRef[];
    /** null: this user never gets cross-jurisdiction hits. */
    crossJurisdiction:
        (CrossJurisdiction & { options: AccessRequestOptions }) | null;
    can: { create: boolean };
};

const KIND_LABELS: Record<Props['kind'], string> = {
    none: '',
    name: 'Searching names and aliases (tolerates misspellings).',
    nin: 'Searching by NIN (exact match).',
    phone: 'Searching by phone number (exact match, any format).',
    nin_or_phone: 'Searching by NIN or phone number (exact match).',
};

/**
 * Persons search within the user's jurisdiction. Searches are POSTed so
 * names, NINs and phone numbers never appear in URLs or browser history.
 * Not a browse-everything list: an empty search shows the 20 most recently
 * updated persons.
 */
export default function PersonsIndex({
    results,
    truncated,
    mode,
    kind,
    filters,
    options,
    states,
    lgas,
    crossJurisdiction,
    can,
}: Props) {
    const [values, setValues] = useState<PersonSearchFilters>(filters);
    const [processing, setProcessing] = useState(false);

    const run = (next: Partial<PersonSearchFilters> = {}) => {
        const merged = { ...values, ...next };
        setValues(merged);
        router.post(PersonController.search().url, merged, {
            preserveState: true,
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        run();
    };

    const reset = () => router.get(PersonController.index().url);

    const select = (
        field: keyof PersonSearchFilters,
        label: string,
        items: { value: string; label: string }[],
        all: string,
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`filter-${field}`}>{label}</Label>
            <NativeSelect
                id={`filter-${field}`}
                value={values[field]}
                onChange={(event) =>
                    run(
                        field === 'state'
                            ? { state: event.target.value, lga: '' }
                            : { [field]: event.target.value },
                    )
                }
            >
                <option value="">{all}</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>
                        {item.label}
                    </option>
                ))}
            </NativeSelect>
        </div>
    );

    return (
        <>
            <Head title="Persons" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Persons"
                        description="Search persons within your jurisdiction by name, NIN or phone number."
                    />
                    {can.create && (
                        <Button asChild>
                            <Link href={PersonController.create()}>
                                <Plus /> Add person
                            </Link>
                        </Button>
                    )}
                </div>

                <form
                    onSubmit={submit}
                    className="space-y-4 rounded-lg border p-4"
                    role="search"
                >
                    <div className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                aria-label="Name, NIN or phone number"
                                className="pl-8"
                                placeholder="Name, NIN (11 digits) or phone number"
                                autoComplete="off"
                                value={values.q}
                                onChange={(event) =>
                                    setValues({
                                        ...values,
                                        q: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Search
                        </Button>
                        <Button type="button" variant="ghost" onClick={reset}>
                            Reset
                        </Button>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                        {select('role', 'Role', options.allRoles, 'All roles')}
                        {states.length > 0 &&
                            select(
                                'state',
                                'State',
                                states.map((s) => ({
                                    value: s.ulid,
                                    label: s.name,
                                })),
                                'All states',
                            )}
                        {lgas.length > 0 &&
                            select(
                                'lga',
                                'LGA',
                                lgas.map((l) => ({
                                    value: l.ulid,
                                    label: l.name,
                                })),
                                'All LGAs',
                            )}
                        {select('gender', 'Gender', options.genders, 'Any')}
                        {select(
                            'classification',
                            'Classification',
                            options.classifications,
                            'Any',
                        )}
                    </div>
                    {kind !== 'none' && (
                        <p className="text-xs text-muted-foreground">
                            {KIND_LABELS[kind]}
                        </p>
                    )}
                </form>

                <section className="space-y-2" aria-label="Results">
                    <h2 className="text-sm font-semibold">
                        {mode === 'recent'
                            ? 'Recently updated'
                            : `${results.length}${truncated ? '+' : ''} result${results.length === 1 ? '' : 's'}`}
                    </h2>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Person
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Roles
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        LGA
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Classification
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {results.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="p-0">
                                            <EmptyState
                                                title="No persons found"
                                                description={
                                                    mode === 'recent'
                                                        ? 'No persons are recorded in your jurisdiction yet.'
                                                        : 'Try another spelling, the full NIN, or fewer filters.'
                                                }
                                            />
                                        </td>
                                    </tr>
                                )}
                                {results.map((person) => (
                                    <tr key={person.ulid}>
                                        <td className="px-3 py-2">
                                            <Link
                                                href={PersonController.show(
                                                    person.ulid,
                                                )}
                                                className="flex items-center gap-3 font-medium hover:underline"
                                            >
                                                <PersonAvatar
                                                    src={person.thumbnail}
                                                    name={person.name}
                                                />
                                                <span>
                                                    {person.name}
                                                    {person.is_deceased && (
                                                        <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                            (deceased)
                                                        </span>
                                                    )}
                                                </span>
                                            </Link>
                                        </td>
                                        <td className="px-3 py-2">
                                            {person.roles
                                                .map((role) => role.label)
                                                .join(', ') || '—'}
                                        </td>
                                        <td className="px-3 py-2">
                                            {person.lga?.name ?? '—'}
                                            {person.state && (
                                                <div className="text-xs text-muted-foreground">
                                                    {person.state.name}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <ClassificationBadge
                                                level={
                                                    person.classification.value
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {truncated && (
                        <p className="text-xs text-muted-foreground">
                            Only the best {results.length} matches are shown.
                            Refine the search to narrow them down.
                        </p>
                    )}
                </section>

                {crossJurisdiction &&
                    (mode === 'name' || mode === 'number') && (
                        <HitsPanel
                            hits={crossJurisdiction.hits}
                            limitReached={crossJurisdiction.limitReached}
                            options={crossJurisdiction.options}
                            emptyText={
                                kind === 'name'
                                    ? 'Name searches match other jurisdictions only together with a date of birth (when adding a person); search by NIN or phone number to find records elsewhere.'
                                    : 'No matching records in other jurisdictions.'
                            }
                        />
                    )}
            </div>
        </>
    );
}

PersonsIndex.layout = {
    breadcrumbs: [{ title: 'Persons', href: PersonController.index() }],
};
