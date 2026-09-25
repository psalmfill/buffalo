import { Badge } from '@/components/ui/badge';
import type { UserStatus } from '@/types/users';

const labels: Record<UserStatus, string> = {
    active: 'Active',
    inactive: 'Inactive',
    locked: 'Locked',
    setup_pending: 'Setup pending',
    no_2fa: '2FA not enrolled',
};

const variants: Record<
    UserStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    active: 'default',
    inactive: 'secondary',
    locked: 'destructive',
    setup_pending: 'outline',
    no_2fa: 'outline',
};

export default function UserStatusBadges({
    statuses,
}: {
    statuses: UserStatus[];
}) {
    return (
        <div className="flex flex-wrap gap-1">
            {statuses.map((status) => (
                <Badge key={status} variant={variants[status]}>
                    {labels[status]}
                </Badge>
            ))}
        </div>
    );
}

export const statusOptions = Object.entries(labels).map(([value, label]) => ({
    value,
    label,
}));
