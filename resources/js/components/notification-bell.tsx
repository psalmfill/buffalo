import { router, useHttp, usePage } from '@inertiajs/react';
import { useEchoNotification } from '@laravel/echo-react';
import { Bell } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import NotificationController from '@/actions/App/Http/Controllers/NotificationController';
import NotificationSeverityDot from '@/components/notification-severity-dot';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUser } from '@/hooks/use-auth';
import { timeAgo } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { AppNotification } from '@/types/notifications';

type LivePayload = Omit<AppNotification, 'read_at'> & { id: string };

/**
 * Bell in the app header: unread count (live over Echo on the private
 * channel users.{ulid}), the latest 10 notifications, "mark all as read",
 * and a toast when a critical notification arrives.
 */
export default function NotificationBell() {
    const page = usePage();
    const userUlid = useCurrentUser().ulid;
    const sharedCount = page.props.notifications?.unreadCount ?? 0;
    const { submit } = useHttp();

    const [unread, setUnread] = useState<number>(sharedCount);
    const [items, setItems] = useState<AppNotification[] | null>(null);
    const [seenShared, setSeenShared] = useState(sharedCount);

    // Resync with the server count shared on each page load.
    if (sharedCount !== seenShared) {
        setSeenShared(sharedCount);
        setUnread(sharedCount);
    }

    useEchoNotification<LivePayload>(`users.${userUlid}`, (payload) => {
        const notification: AppNotification = {
            id: payload.id,
            type: payload.type,
            title: payload.title,
            body: payload.body,
            url: payload.url,
            severity: payload.severity,
            read_at: null,
            created_at: payload.created_at,
        };

        setUnread((count) => count + 1);
        setItems((current) =>
            current === null ? null : [notification, ...current].slice(0, 10),
        );

        if (notification.severity === 'critical') {
            toast.error(notification.title ?? 'Critical notification', {
                description: notification.body ?? undefined,
            });
        }
    });

    const load = useCallback(async () => {
        const response = (await submit(NotificationController.recent())) as {
            notifications: AppNotification[];
            unreadCount: number;
        };

        setItems(response.notifications);
        setUnread(response.unreadCount);
    }, [submit]);

    const open = useCallback(
        async (notification: AppNotification) => {
            if (notification.read_at === null) {
                await submit(NotificationController.markRead(notification.id));
                setUnread((count) => Math.max(0, count - 1));
            }

            if (notification.url) {
                router.visit(notification.url);
            } else {
                void load();
            }
        },
        [submit, load],
    );

    const markAll = useCallback(async () => {
        await submit(NotificationController.markAllRead());
        setUnread(0);
        setItems(
            (current) =>
                current?.map((n) => ({
                    ...n,
                    read_at: n.read_at ?? new Date().toISOString(),
                })) ?? null,
        );
    }, [submit]);

    return (
        <DropdownMenu onOpenChange={(isOpen) => isOpen && void load()}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={`Notifications, ${unread} unread`}
                    data-test="notification-bell"
                >
                    <Bell className="size-5" />
                    {unread > 0 && (
                        <span
                            className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-semibold text-white"
                            data-test="notification-count"
                        >
                            {unread > 99 ? '99+' : unread}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <div className="flex items-center justify-between px-2 py-1.5">
                    <DropdownMenuLabel className="p-0">
                        Notifications
                    </DropdownMenuLabel>
                    {unread > 0 && (
                        <button
                            type="button"
                            className="text-xs text-muted-foreground hover:text-foreground"
                            onClick={() => void markAll()}
                        >
                            Mark all as read
                        </button>
                    )}
                </div>
                <DropdownMenuSeparator />
                {items === null && (
                    <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                        Loading…
                    </p>
                )}
                {items !== null && items.length === 0 && (
                    <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                        No notifications
                    </p>
                )}
                {items?.map((notification) => (
                    <DropdownMenuItem
                        key={notification.id}
                        className="flex cursor-pointer items-start gap-2 py-2"
                        onSelect={() => void open(notification)}
                    >
                        <NotificationSeverityDot
                            severity={notification.severity}
                            className="mt-1.5"
                        />
                        <div className="min-w-0 flex-1">
                            <p
                                className={cn(
                                    'truncate text-sm',
                                    notification.read_at === null &&
                                        'font-semibold',
                                )}
                            >
                                {notification.title}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {timeAgo(notification.created_at)}
                            </p>
                        </div>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    className="cursor-pointer justify-center text-sm"
                    onSelect={() =>
                        router.visit(NotificationController.index().url)
                    }
                >
                    View all notifications
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
