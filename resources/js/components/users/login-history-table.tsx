import { Link } from '@inertiajs/react';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { LoginEntry } from '@/types/users';

const failureEvents = ['login_failed', 'two_factor_failed', 'locked'];

const labels: Record<string, string> = {
    login_success: 'Signed in',
    login_failed: 'Failed sign-in',
    two_factor_failed: 'Failed 2FA code',
    locked: 'Account locked',
    logout: 'Signed out',
    session_expired: 'Session expired',
    signed_out_elsewhere: 'Signed out (signed in elsewhere)',
    setup_completed: 'Account setup completed',
};

/**
 * Read-only login history. Rows flagged `repeated_failures` (several
 * failures from the same IP address) are highlighted.
 */
export default function LoginHistoryTable({
    entries,
    showUser = false,
}: {
    entries: LoginEntry[];
    showUser?: boolean;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Time</th>
                        <th className="px-3 py-2 font-medium">Event</th>
                        {showUser && (
                            <th className="px-3 py-2 font-medium">User</th>
                        )}
                        <th className="px-3 py-2 font-medium">Email entered</th>
                        <th className="px-3 py-2 font-medium">IP address</th>
                        <th className="px-3 py-2 font-medium">Browser</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {entries.length === 0 && (
                        <tr>
                            <td
                                colSpan={showUser ? 6 : 5}
                                className="px-3 py-6 text-center text-muted-foreground"
                            >
                                No entries
                            </td>
                        </tr>
                    )}
                    {entries.map((entry, index) => (
                        <tr
                            key={`${entry.created_at}-${index}`}
                            className={cn(
                                entry.repeated_failures && 'bg-red-500/10',
                            )}
                        >
                            <td className="px-3 py-2 whitespace-nowrap">
                                {formatDateTime(entry.created_at)}
                            </td>
                            <td className="px-3 py-2">
                                <Badge
                                    variant={
                                        failureEvents.includes(entry.event)
                                            ? 'destructive'
                                            : 'secondary'
                                    }
                                >
                                    {labels[entry.event] ?? entry.event}
                                </Badge>
                            </td>
                            {showUser && (
                                <td className="px-3 py-2">
                                    {entry.user ? (
                                        <Link
                                            href={UserController.show(
                                                entry.user.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {entry.user.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </td>
                            )}
                            <td className="px-3 py-2">
                                {entry.email_attempted ?? '—'}
                            </td>
                            <td className="px-3 py-2">
                                {entry.ip_address ?? '—'}
                                {entry.repeated_failures && (
                                    <span className="ml-2 text-xs font-medium text-red-600">
                                        repeated failures
                                    </span>
                                )}
                            </td>
                            <td
                                className="max-w-64 truncate px-3 py-2 text-muted-foreground"
                                title={entry.user_agent ?? ''}
                            >
                                {entry.user_agent ?? '—'}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
