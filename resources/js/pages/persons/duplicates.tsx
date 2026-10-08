import { Head, Link, router } from '@inertiajs/react';
import { GitMerge, ListChecks } from 'lucide-react';
import { useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonDuplicateController from '@/actions/App/Http/Controllers/Persons/PersonDuplicateController';
import PersonMergeController from '@/actions/App/Http/Controllers/Persons/PersonMergeController';
import PersonMergeRequestController from '@/actions/App/Http/Controllers/Persons/PersonMergeRequestController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import PersonAvatar from '@/components/persons/person-avatar';
import RequestMergeDialog from '@/components/persons/request-merge-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type { NamedRef } from '@/types/local-government';
import type { DuplicatePair, PersonRow } from '@/types/persons';

type Props = {
    pairs: DuplicatePair[];
    limit: number;
    states: NamedRef[];
    state: NamedRef | null;
    nightly: {
        certain: number;
        strong: number;
        possible: number;
        total: number;
        at: string;
    } | null;
    can: { request: boolean; merge: boolean };
};

const STRENGTH_VARIANT: Record<
    string,
    'destructive' | 'default' | 'secondary'
> = {
    certain: 'destructive',
    strong: 'default',
    possible: 'secondary',
};

function PersonCell({ person }: { person: PersonRow }) {
    return (
        <div className="flex items-center gap-3">
            <PersonAvatar src={person.thumbnail} name={person.name} />
            <div className="min-w-0">
                <Link
                    href={PersonController.show(person.ulid)}
                    className="font-medium hover:underline"
                >
                    {person.name}
                </Link>
                <div className="flex flex-wrap items-center gap-1 text-xs text-muted-foreground">
                    {person.lga?.name} LGA
                    <ClassificationBadge level={person.classification.value} />
                    {person.is_deceased && <span>· Deceased</span>}
                </div>
            </div>
        </div>
    );
}

/**
 * Duplicate person candidates (Phase 10A.3): pairs of the user's OWN
 * records that may be the same person, strongest first.
 */
export default function PersonDuplicates({
    pairs,
    limit,
    states,
    state,
    nightly,
    can,
}: Props) {
    const [requesting, setRequesting] = useState<DuplicatePair | null>(null);
    const [dismissing, setDismissing] = useState<DuplicatePair | null>(null);

    return (
        <>
            <Head title="Duplicate persons" />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Duplicate persons"
                        description="Pairs of records in your jurisdiction that may be the same person. Records are merged only within one state."
                        help={{ topic: 'guides/merging-duplicates' }}
                    />
                    <Button variant="outline" asChild>
                        <Link href={PersonMergeRequestController.index()}>
                            <ListChecks /> Merge requests
                        </Link>
                    </Button>
                </div>

                {states.length > 0 && (
                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor="duplicates-state">State</Label>
                        <NativeSelect
                            id="duplicates-state"
                            value={state?.ulid ?? ''}
                            onChange={(event) =>
                                router.get(
                                    PersonDuplicateController.index().url,
                                    { state: event.target.value },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {states.map((option) => (
                                <option key={option.ulid} value={option.ulid}>
                                    {option.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                )}

                {nightly && can.merge && (
                    <p className="text-sm text-muted-foreground">
                        Last night's count for {state?.name}: {nightly.total}{' '}
                        pair{nightly.total === 1 ? '' : 's'} ({nightly.certain}{' '}
                        certain, {nightly.strong} strong, {nightly.possible}{' '}
                        possible).
                    </p>
                )}

                {pairs.length === 0 ? (
                    <div className="rounded-lg border">
                        <EmptyState
                            title="No possible duplicates"
                            description="No two records in your jurisdiction share a NIN, phone number, or a date of birth with a similar name, and no names in one LGA are nearly identical."
                        />
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Record
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        May be the same as
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Why
                                    </th>
                                    <th className="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {pairs.map((pair) => (
                                    <tr key={`${pair.a.ulid}-${pair.b.ulid}`}>
                                        <td className="px-3 py-2">
                                            <PersonCell person={pair.a} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <PersonCell person={pair.b} />
                                        </td>
                                        <td className="px-3 py-2">
                                            <Badge
                                                variant={
                                                    STRENGTH_VARIANT[
                                                        pair.strength.value
                                                    ] ?? 'secondary'
                                                }
                                            >
                                                {pair.strength.label}
                                            </Badge>
                                            <ul className="mt-1 text-xs text-muted-foreground">
                                                {pair.signals.map((signal) => (
                                                    <li key={signal}>
                                                        {signal}
                                                    </li>
                                                ))}
                                            </ul>
                                            {pair.pendingRequest && (
                                                <p className="mt-1 text-xs">
                                                    Merge requested
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {pair.can.request && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            setRequesting(pair)
                                                        }
                                                    >
                                                        Request merge
                                                    </Button>
                                                )}
                                                {pair.can.merge && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            asChild
                                                        >
                                                            <Link
                                                                href={PersonMergeController.review(
                                                                    {
                                                                        query: {
                                                                            a: pair
                                                                                .a
                                                                                .ulid,
                                                                            b: pair
                                                                                .b
                                                                                .ulid,
                                                                            ...(pair.pendingRequest
                                                                                ? {
                                                                                      request:
                                                                                          pair.pendingRequest,
                                                                                  }
                                                                                : {}),
                                                                        },
                                                                    },
                                                                )}
                                                            >
                                                                <GitMerge />{' '}
                                                                Review and merge
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setDismissing(
                                                                    pair,
                                                                )
                                                            }
                                                        >
                                                            Not a duplicate
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {pairs.length >= limit && (
                    <p className="text-sm text-muted-foreground">
                        Showing the {limit} strongest pairs. Merge or dismiss
                        some to see more.
                    </p>
                )}
            </div>

            {requesting && (
                <RequestMergeDialog
                    open
                    onOpenChange={(open) => !open && setRequesting(null)}
                    person={requesting.a}
                    other={requesting.b}
                />
            )}
            {dismissing && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDismissing(null)}
                    title="Not the same person?"
                    description={`${dismissing.a.name} and ${dismissing.b.name} will not be suggested as duplicates again. Give the reason (it is recorded on both records).`}
                    confirmLabel="Not a duplicate"
                    requireReason
                    url={PersonDuplicateController.dismiss().url}
                    data={{ a: dismissing.a.ulid, b: dismissing.b.ulid }}
                />
            )}
        </>
    );
}

PersonDuplicates.layout = {
    breadcrumbs: [
        { title: 'Persons', href: PersonController.index() },
        { title: 'Duplicate persons', href: PersonDuplicateController.index() },
    ],
};
