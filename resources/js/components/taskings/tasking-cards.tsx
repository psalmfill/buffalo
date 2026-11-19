import { Link } from '@inertiajs/react';
import { Mail, Plus } from 'lucide-react';
import TaskingController from '@/actions/App/Http/Controllers/Taskings/TaskingController';
import EmptyState from '@/components/empty-state';
import {
    TaskingList,
    useLiveTaskings,
} from '@/components/taskings/tasking-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCurrentUser } from '@/hooks/use-auth';
import type { DirectorTaskingCard, LgaTaskingInbox } from '@/types/taskings';

/**
 * The directors' Tasking card at the top of the state dashboard (Phase
 * 11H): one primary button, New tasking, and the latest sent taskings with
 * their progress lines, kept live over the state's tasking channel.
 */
export function TaskingCard({ card }: { card: DirectorTaskingCard }) {
    const user = useCurrentUser();
    useLiveTaskings(`states.${user.jurisdiction.state?.ulid ?? ''}.taskings`, [
        'taskings',
    ]);

    return (
        <section
            className="space-y-3 rounded-xl border bg-card p-4"
            data-test="tasking-card"
            aria-labelledby="tasking-card-heading"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2
                        id="tasking-card-heading"
                        className="text-base font-semibold"
                    >
                        Tasking
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Instruct one, several or all LGA Security Chiefs of your
                        state.
                    </p>
                </div>
                {card.canCreate && (
                    <Button asChild size="lg">
                        <Link
                            href={TaskingController.create()}
                            data-test="new-tasking"
                        >
                            <Plus /> New tasking
                        </Link>
                    </Button>
                )}
            </div>

            {card.latest.length === 0 ? (
                <EmptyState
                    title="No taskings sent yet"
                    description="Taskings you send appear here with how many LGAs have read, acknowledged and completed them."
                />
            ) : (
                <TaskingList rows={card.latest} />
            )}

            <div className="flex gap-4 text-sm">
                <Link href={TaskingController.index()} className="underline">
                    All taskings
                </Link>
                {card.drafts > 0 && (
                    <Link
                        href={TaskingController.index({
                            query: { box: 'drafts' },
                        })}
                        className="underline"
                    >
                        Drafts ({card.drafts})
                    </Link>
                )}
            </div>
        </section>
    );
}

/**
 * "Messages from the Director" at the top of the LGA chief's dashboard
 * (Phase 11H): the unread count, the latest taskings to the LGA (unread in
 * bold, overdue flagged), kept live over the chief's own channel.
 */
export function TaskingInboxCard({ inbox }: { inbox: LgaTaskingInbox }) {
    const user = useCurrentUser();
    useLiveTaskings(`users.${user.ulid}`, ['taskingInbox']);

    return (
        <section
            className="space-y-3 rounded-xl border bg-card p-4"
            data-test="tasking-inbox"
            aria-labelledby="tasking-inbox-heading"
        >
            <div className="flex items-center justify-between gap-3">
                <h2
                    id="tasking-inbox-heading"
                    className="flex items-center gap-2 text-base font-semibold"
                >
                    <Mail className="size-4" /> Messages from the Director
                    {inbox.unread > 0 && (
                        <Badge data-test="unread-badge">
                            {inbox.unread} unread
                        </Badge>
                    )}
                </h2>
                <Link
                    href={TaskingController.index()}
                    className="text-sm underline"
                >
                    Inbox
                </Link>
            </div>

            {inbox.latest.length === 0 ? (
                <EmptyState
                    title="No taskings"
                    description="Instructions from your State Director appear here."
                />
            ) : (
                <TaskingList rows={inbox.latest} forChief />
            )}
        </section>
    );
}
