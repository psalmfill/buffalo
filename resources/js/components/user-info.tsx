import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

/**
 * Initials avatar plus name. `compact` (header button) hides the name on
 * small screens; `showEmail` adds the email and role.
 */
export function UserInfo({
    user,
    showEmail = false,
    compact = false,
}: {
    user: User;
    showEmail?: boolean;
    compact?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar className="h-8 w-8 overflow-hidden rounded-full">
                <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div
                className={
                    compact
                        ? 'hidden max-w-40 text-left text-sm leading-tight md:grid'
                        : 'grid flex-1 text-left text-sm leading-tight'
                }
            >
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <>
                        <span className="truncate text-xs text-muted-foreground">
                            {user.email}
                        </span>
                        {user.role && (
                            <span className="truncate text-xs text-muted-foreground">
                                {user.role.label}
                            </span>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
