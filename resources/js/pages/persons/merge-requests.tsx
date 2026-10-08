import { Head, Link } from '@inertiajs/react';
import { GitMerge } from 'lucide-react';
import { useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonDuplicateController from '@/actions/App/Http/Controllers/Persons/PersonDuplicateController';
import PersonMergeController from '@/actions/App/Http/Controllers/Persons/PersonMergeController';
import PersonMergeRequestController from '@/actions/App/Http/Controllers/Persons/PersonMergeRequestController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import { cn } from '@/lib/utils';
import type { Option } from '@/types/local-government';
import type { Paginated } from '@/types/pagination';
import type { MergeRequestRow, PersonRow } from '@/types/persons';

type Props = {
    requests: Paginated<MergeRequestRow>;
    status: string;
    statuses: Option[];
};

function PersonName({
    person,
    suggested,
}: {
    person: PersonRow | null;
    suggested: boolean;
}) {
    if (person === null) {
        return (
            <span className="text-muted-foreground">
                A record no longer available
            </span>
        );
    }

    return (
        <span>
            <Link
                href={PersonController.show(person.ulid)}
                className="font-medium hover:underline"
            >
                {person.name}
            </Link>{' '}
            <span className="text-xs text-muted-foreground">
                ({person.lga?.name} LGA)
            </span>
            {suggested && (
                <span className="ml-1 text-xs">· suggested to keep</span>
            )}
        </span>
    );
}

/**
 * Merge requests (Phase 10A.7): raised by LGA chiefs and Deputy State
 * Directors, executed through the review page or declined with a note.
 */
export default function PersonMergeRequests({
    requests,
    status,
    statuses,
}: Props) {
    const [declining, setDeclining] = useState<MergeRequestRow | null>(null);

    return (
        <>
            <Head title="Merge requests" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Merge requests"
                    description="Requests to merge two records of the same person."
                    help={{ topic: 'guides/merging-duplicates' }}
                />

                <nav className="flex gap-1 border-b" aria-label="Status">
                    {statuses.map((option) => (
                        <Link
                            key={option.value}
                            href={PersonMergeRequestController.index({
                                query: { status: option.value },
                            })}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                status === option.value
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option.label}
                        </Link>
                    ))}
                </nav>

                {requests.data.length === 0 ? (
                    <div className="rounded-lg border">
                        <EmptyState
                            title="No merge requests"
                            description="Officers request merges from the duplicate persons list or a person's page."
                        />
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {requests.data.map((request) => (
                            <li key={request.ulid} className="space-y-2 p-4">
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="space-y-1 text-sm">
                                        <PersonName
                                            person={request.a}
                                            suggested={
                                                request.a !== null &&
                                                request.suggestedSurvivor ===
                                                    request.a.ulid
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            may be the same person as
                                        </p>
                                        <PersonName
                                            person={request.b}
                                            suggested={
                                                request.b !== null &&
                                                request.suggestedSurvivor ===
                                                    request.b.ulid
                                            }
                                        />
                                    </div>
                                    {request.can.decide &&
                                        request.a &&
                                        request.b && (
                                            <div className="flex gap-2">
                                                <Button size="sm" asChild>
                                                    <Link
                                                        href={PersonMergeController.review(
                                                            {
                                                                query: {
                                                                    a:
                                                                        request.suggestedSurvivor ===
                                                                        request
                                                                            .b
                                                                            .ulid
                                                                            ? request
                                                                                  .b
                                                                                  .ulid
                                                                            : request
                                                                                  .a
                                                                                  .ulid,
                                                                    b:
                                                                        request.suggestedSurvivor ===
                                                                        request
                                                                            .b
                                                                            .ulid
                                                                            ? request
                                                                                  .a
                                                                                  .ulid
                                                                            : request
                                                                                  .b
                                                                                  .ulid,
                                                                    request:
                                                                        request.ulid,
                                                                },
                                                            },
                                                        )}
                                                    >
                                                        <GitMerge /> Review and
                                                        merge
                                                    </Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setDeclining(request)
                                                    }
                                                >
                                                    Decline
                                                </Button>
                                            </div>
                                        )}
                                </div>
                                <p className="text-sm whitespace-pre-line">
                                    {request.reason}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Requested by {request.requestedBy}
                                    {request.lga && ` (${request.lga} LGA)`}
                                    {request.createdAt &&
                                        ` on ${new Date(request.createdAt).toLocaleString()}`}
                                    {request.decidedBy &&
                                        ` · ${request.status.label} by ${request.decidedBy}`}
                                </p>
                                {request.decisionNote && (
                                    <p className="text-sm text-muted-foreground">
                                        Note: {request.decisionNote}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={requests} />
            </div>

            {declining && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDeclining(null)}
                    title="Decline this merge request?"
                    description="The requester is told that the request was declined. Give the reason."
                    confirmLabel="Decline"
                    requireReason
                    url={
                        PersonMergeRequestController.decline(declining.ulid).url
                    }
                />
            )}
        </>
    );
}

PersonMergeRequests.layout = {
    breadcrumbs: [
        { title: 'Persons', href: PersonController.index() },
        { title: 'Duplicate persons', href: PersonDuplicateController.index() },
        { title: 'Merge requests', href: PersonMergeRequestController.index() },
    ],
};
