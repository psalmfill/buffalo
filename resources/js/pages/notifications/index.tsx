import { Head, Link, router } from '@inertiajs/react';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import Heading from '@/components/heading';
import NotificationSeverityDot from '@/components/notification-severity-dot';
import Pagination from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { formatDateTime, timeAgo } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types/pagination';
import type { AppNotification } from '@/types/notifications';
import EmptyState from '@/components/empty-state';

type Filter = 'all' | 'unread' | 'read';

type Props = {
    notifications: Paginated<AppNotification>;
    filter: Filter;
    unreadCount: number;
};

const filters: { value: Filter; label: string }[] = [
    { value: 'all', label: 'All' },
    { value: 'unread', label: 'Unread' },
    { value: 'read', label: 'Read' },
];

export default function NotificationsIndex({
    notifications,
    filter,
    unreadCount,
}: Props) {
    const open = (notification: AppNotification) => {
        router.post(
            NotificationController.markRead(notification.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (notification.url) {
                        router.visit(notification.url);
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title="Notifications" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading
                        title="Notifications"
                        description={`${unreadCount} unread`}
                    />
                    <Button
                        variant="secondary"
                        disabled={unreadCount === 0}
                        onClick={() =>
                            router.post(
                                NotificationController.markAllRead().url,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Mark all as read
                    </Button>
                </div>

                <div className="flex gap-2">
                    {filters.map((option) => (
                        <Link
                            key={option.value}
                            href={NotificationController.index({
                                query: { filter: option.value },
                            })}
                            className={cn(
                                'rounded-md border px-3 py-1 text-sm',
                                filter === option.value &&
                                    'bg-primary text-primary-foreground',
                            )}
                            preserveScroll
                        >
                            {option.label}
                        </Link>
                    ))}
                </div>

                <ul className="divide-y rounded-lg border">
                    {notifications.data.length === 0 && (
                        <li>
                            <EmptyState
                                title="No notifications"
                                description="You are all caught up."
                            />
                        </li>
                    )}
                    {notifications.data.map((notification) => (
                        <li key={notification.id}>
                            <button
                                type="button"
                                onClick={() => open(notification)}
                                className="flex w-full items-start gap-3 p-4 text-left hover:bg-muted/50"
                            >
                                <NotificationSeverityDot
                                    severity={notification.severity}
                                    className="mt-1.5"
                                />
                                <div className="min-w-0 flex-1">
                                    <p
                                        className={cn(
                                            'text-sm',
                                            notification.read_at === null &&
                                                'font-semibold',
                                        )}
                                    >
                                        {notification.title}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {notification.body}
                                    </p>
                                </div>
                                <time
                                    className="shrink-0 text-xs text-muted-foreground"
                                    dateTime={notification.created_at ?? ''}
                                    title={formatDateTime(
                                        notification.created_at,
                                    )}
                                >
                                    {timeAgo(notification.created_at)}
                                </time>
                            </button>
                        </li>
                    ))}
                </ul>

                <Pagination page={notifications} />
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Notifications',
            href: NotificationController.index(),
        },
    ],
};
