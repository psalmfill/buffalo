import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeftRight, GitMerge, Lock } from 'lucide-react';
import type { FormEvent } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonDuplicateController from '@/actions/App/Http/Controllers/Persons/PersonDuplicateController';
import PersonMergeController from '@/actions/App/Http/Controllers/Persons/PersonMergeController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PersonAvatar from '@/components/persons/person-avatar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    MergeField,
    MergeReview,
    MergeReviewSide,
    MergeSide,
} from '@/types/persons';

type Form = {
    survivor: string;
    source: string;
    request: string | null;
    fields: Record<string, MergeSide>;
    place: MergeSide;
    photo: MergeSide | null;
    poi_status: MergeSide;
    confirm_dob: boolean;
    confirm_deceased: boolean;
};

const COUNT_LABELS: Record<keyof MergeReview['counts'], string> = {
    aliases: 'Aliases',
    photos: 'Photos',
    roles: 'Roles',
    associations: 'Associations',
    offices: 'Political offices',
    memberships: 'Group memberships',
    incidents: 'Incidents',
    poi: 'Person of interest record',
    community_links: 'Community, religious and organization links',
};

function SideHeader({ side, label }: { side: MergeReviewSide; label: string }) {
    return (
        <div className="flex items-center gap-3">
            <PersonAvatar src={side.thumbnail} name={side.name} />
            <div>
                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                    {label}
                </p>
                <Link
                    href={PersonController.show(side.ulid)}
                    className="font-medium hover:underline"
                >
                    {side.name}
                </Link>
                <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                    {side.lga.name} LGA
                    <ClassificationBadge level={side.classification.value} />
                </div>
            </div>
        </div>
    );
}

function Choice({
    name,
    value,
    checked,
    disabled = false,
    onChange,
    children,
}: {
    name: string;
    value: MergeSide;
    checked: boolean;
    disabled?: boolean;
    onChange: (value: MergeSide) => void;
    children: React.ReactNode;
}) {
    return (
        <label
            className={cn(
                'flex items-start gap-2 rounded-md border px-3 py-2 text-sm',
                checked && 'border-primary bg-primary/5',
                disabled && 'opacity-50',
            )}
        >
            <input
                type="radio"
                className="mt-1"
                name={name}
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={() => onChange(value)}
            />
            <span className="break-words whitespace-pre-line">{children}</span>
        </label>
    );
}

/**
 * Review and merge (Phase 10A.7): both records side by side, a choice for
 * every conflicting field, what moves, what ends, and the visibility
 * side-effect. Confirming asks for a TOTP code (step-up).
 */
export default function PersonMerge(review: MergeReview) {
    const {
        survivor,
        source,
        fields,
        blockers,
        dobConflict,
        deceasedDiffers,
        classification,
        counts,
        endings,
        visibility,
        photos,
        poi,
        communities,
        request,
    } = review;

    const form = useForm<Form>({
        survivor: survivor.ulid,
        source: source.ulid,
        request: request?.ulid ?? null,
        fields: Object.fromEntries(
            fields
                .filter((field) => field.conflict)
                .map((field) => [field.field, 'survivor' as MergeSide]),
        ),
        place: 'survivor',
        photo: photos.survivor ? 'survivor' : photos.source ? 'source' : null,
        poi_status: 'survivor',
        confirm_dob: false,
        confirm_deceased: false,
    });

    const differentLgas = survivor.lga.ulid !== source.lga.ulid;
    const ownerLga = form.data.place === 'source' ? source.lga : survivor.lga;
    const losing = visibility[form.data.place];
    const conflicts = fields.filter((field) => field.conflict);
    const ninBlocked = blockers.length > 0;

    const communityAllowed = (side: MergeSide): boolean => {
        const community = communities[side];

        return community === null || community.lga === ownerLga.ulid;
    };

    const setField = (field: string, side: MergeSide) =>
        form.setData('fields', { ...form.data.fields, [field]: side });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(PersonMergeController.store().url, { preserveScroll: true });
    };

    const swap = PersonMergeController.review({
        query: {
            a: source.ulid,
            b: survivor.ulid,
            ...(request ? { request: request.ulid } : {}),
        },
    });

    const renderValue = (field: MergeField, side: MergeSide) =>
        field[side] ?? <span className="text-muted-foreground">—</span>;

    return (
        <>
            <Head title="Review and merge" />
            <form onSubmit={submit} className="space-y-6 p-4">
                <Heading
                    title="Review and merge"
                    description="The record on the left is kept (the survivor). The record on the right is merged into it and removed from searches; its history is kept and the merge can be undone for 30 days."
                    help={{ topic: 'guides/merging-duplicates' }}
                />

                {request && (
                    <Alert>
                        <AlertTitle>
                            Requested by {request.requestedBy}
                        </AlertTitle>
                        <AlertDescription className="whitespace-pre-line">
                            {request.reason}
                        </AlertDescription>
                    </Alert>
                )}

                {blockers.length > 0 && (
                    <Alert variant="destructive">
                        <Lock />
                        <AlertTitle>These records cannot be merged</AlertTitle>
                        <AlertDescription>
                            <ul className="list-disc pl-4">
                                {blockers.map((blocker) => (
                                    <li key={blocker}>{blocker}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-4 rounded-lg border p-4 md:grid-cols-[1fr_auto_1fr] md:items-center">
                    <SideHeader side={survivor} label="Keep (survivor)" />
                    <Button variant="outline" size="sm" asChild>
                        <Link href={swap} preserveScroll>
                            <ArrowLeftRight /> Swap
                        </Link>
                    </Button>
                    <SideHeader side={source} label="Merge into it (source)" />
                </div>

                <section className="space-y-2">
                    <h2 className="text-lg font-medium">Fields</h2>
                    <p className="text-sm text-muted-foreground">
                        Where only one record has a value, it is kept.
                        {conflicts.length > 0
                            ? ` Choose a value for the ${conflicts.length} highlighted field${conflicts.length === 1 ? '' : 's'}. Names not chosen are kept as aliases.`
                            : ' No fields conflict.'}
                    </p>
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="w-40 px-3 py-2 font-medium">
                                        Field
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Survivor
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Source
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {fields.map((field) => (
                                    <tr
                                        key={field.field}
                                        className={cn(
                                            field.conflict &&
                                                'bg-amber-50 dark:bg-amber-950/30',
                                        )}
                                    >
                                        <td className="px-3 py-2 align-top font-medium">
                                            {field.label}
                                            {field.protected && (
                                                <span className="ml-1 text-[10px] tracking-wide text-muted-foreground uppercase">
                                                    (encrypted)
                                                </span>
                                            )}
                                            {field.field === 'nin' &&
                                                ninBlocked &&
                                                field.conflict && (
                                                    <p className="text-xs text-destructive">
                                                        Different NINs: not the
                                                        same person.
                                                    </p>
                                                )}
                                        </td>
                                        {(['survivor', 'source'] as const).map(
                                            (side) => (
                                                <td
                                                    key={side}
                                                    className="px-3 py-2 align-top"
                                                >
                                                    {field.conflict &&
                                                    field.field !== 'nin' ? (
                                                        <Choice
                                                            name={`field-${field.field}`}
                                                            value={side}
                                                            checked={
                                                                form.data
                                                                    .fields[
                                                                    field.field
                                                                ] === side
                                                            }
                                                            disabled={
                                                                field.field ===
                                                                    'community' &&
                                                                !communityAllowed(
                                                                    side,
                                                                )
                                                            }
                                                            onChange={(value) =>
                                                                setField(
                                                                    field.field,
                                                                    value,
                                                                )
                                                            }
                                                        >
                                                            {renderValue(
                                                                field,
                                                                side,
                                                            )}
                                                        </Choice>
                                                    ) : (
                                                        <span
                                                            className={cn(
                                                                'block whitespace-pre-line',
                                                                !field.conflict &&
                                                                    field.automatic !==
                                                                        side &&
                                                                    'text-muted-foreground',
                                                            )}
                                                        >
                                                            {renderValue(
                                                                field,
                                                                side,
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                            ),
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <InputError
                        message={form.errors['fields.community' as keyof Form]}
                    />
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    {differentLgas && (
                        <div className="space-y-2 rounded-lg border p-4">
                            <h2 className="font-medium">Owning LGA</h2>
                            <div className="grid gap-2">
                                {(['survivor', 'source'] as const).map(
                                    (side) => (
                                        <Choice
                                            key={side}
                                            name="place"
                                            value={side}
                                            checked={form.data.place === side}
                                            onChange={(value) =>
                                                form.setData('place', value)
                                            }
                                        >
                                            {
                                                (side === 'survivor'
                                                    ? survivor
                                                    : source
                                                ).lga.name
                                            }{' '}
                                            LGA
                                        </Choice>
                                    ),
                                )}
                            </div>
                            {losing && (
                                <Alert variant="destructive">
                                    <AlertTriangle />
                                    <AlertTitle>
                                        {losing.lga} LGA will no longer see this
                                        person
                                    </AlertTitle>
                                    <AlertDescription>
                                        Officers of {losing.lga} LGA will see
                                        "Person on record (not visible to you)"
                                        on {losing.incidents} of their incident
                                        {losing.incidents === 1 ? '' : 's'}{' '}
                                        involving this person.
                                    </AlertDescription>
                                </Alert>
                            )}
                        </div>
                    )}

                    {photos.survivor && photos.source && (
                        <div className="space-y-2 rounded-lg border p-4">
                            <h2 className="font-medium">Current photo</h2>
                            <p className="text-sm text-muted-foreground">
                                All photos move to the survivor.
                            </p>
                            <div className="flex gap-4">
                                {(['survivor', 'source'] as const).map(
                                    (side) => (
                                        <Choice
                                            key={side}
                                            name="photo"
                                            value={side}
                                            checked={form.data.photo === side}
                                            onChange={(value) =>
                                                form.setData('photo', value)
                                            }
                                        >
                                            <img
                                                src={photos[side] ?? ''}
                                                alt={`Current photo of the ${side}`}
                                                className="h-24 w-24 rounded object-cover"
                                            />
                                        </Choice>
                                    ),
                                )}
                            </div>
                        </div>
                    )}

                    {poi && (
                        <div className="space-y-2 rounded-lg border p-4">
                            <h2 className="font-medium">
                                Person of interest status
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                Both are persons of interest: the records are
                                combined (higher risk level, all offence
                                categories, offences and sightings).
                            </p>
                            {(['survivor', 'source'] as const).map((side) => (
                                <Choice
                                    key={side}
                                    name="poi_status"
                                    value={side}
                                    checked={form.data.poi_status === side}
                                    onChange={(value) =>
                                        form.setData('poi_status', value)
                                    }
                                >
                                    {poi[side]}
                                </Choice>
                            ))}
                        </div>
                    )}

                    <div className="space-y-2 rounded-lg border p-4">
                        <h2 className="font-medium">
                            What moves to the survivor
                        </h2>
                        <ul className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            {(
                                Object.keys(
                                    COUNT_LABELS,
                                ) as (keyof MergeReview['counts'])[]
                            ).map((key) => (
                                <li
                                    key={key}
                                    className="flex justify-between gap-2"
                                >
                                    <span className="text-muted-foreground">
                                        {COUNT_LABELS[key]}
                                    </span>
                                    <span>{counts[key]}</span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-sm">
                            Resulting classification:{' '}
                            <ClassificationBadge level={classification.value} />
                        </p>
                    </div>

                    <div className="space-y-2 rounded-lg border p-4">
                        <h2 className="font-medium">What ends</h2>
                        <p className="text-sm">
                            {endings.grants} temporary access grant
                            {endings.grants === 1 ? '' : 's'} on the source will
                            be revoked, and {endings.requests} open access
                            request{endings.requests === 1 ? '' : 's'} cancelled
                            ("record merged"). They are never transferred to the
                            survivor, and an undo does not restore them.
                        </p>
                    </div>
                </section>

                {(dobConflict || deceasedDiffers) && (
                    <section className="space-y-3 rounded-lg border border-amber-300 p-4">
                        {dobConflict && (
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id="confirm-dob"
                                    checked={form.data.confirm_dob}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'confirm_dob',
                                            checked === true,
                                        )
                                    }
                                />
                                <div>
                                    <Label htmlFor="confirm-dob">
                                        The dates of birth differ. I confirm
                                        that these are the same person.
                                    </Label>
                                    <InputError
                                        message={form.errors.confirm_dob}
                                    />
                                </div>
                            </div>
                        )}
                        {deceasedDiffers && (
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id="confirm-deceased"
                                    checked={form.data.confirm_deceased}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'confirm_deceased',
                                            checked === true,
                                        )
                                    }
                                />
                                <div>
                                    <Label htmlFor="confirm-deceased">
                                        One record is marked deceased. I confirm
                                        that the merged person is deceased.
                                    </Label>
                                    <InputError
                                        message={form.errors.confirm_deceased}
                                    />
                                </div>
                            </div>
                        )}
                    </section>
                )}

                <InputError
                    message={
                        (form.errors as Record<string, string | undefined>)
                            .merge
                    }
                />

                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={PersonDuplicateController.index()}>
                            Cancel
                        </Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            ninBlocked ||
                            (dobConflict && !form.data.confirm_dob) ||
                            (deceasedDiffers && !form.data.confirm_deceased)
                        }
                    >
                        <GitMerge /> Merge records
                    </Button>
                </div>
            </form>
        </>
    );
}

PersonMerge.layout = {
    breadcrumbs: [
        { title: 'Persons', href: PersonController.index() },
        { title: 'Duplicate persons', href: PersonDuplicateController.index() },
        { title: 'Review and merge', href: PersonDuplicateController.index() },
    ],
};
