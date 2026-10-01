import { Badge } from '@/components/ui/badge';
import type { DisseminationStatus } from '@/types/disseminations';

const LABELS: Record<DisseminationStatus, string> = {
    unacknowledged: 'Not acknowledged',
    acknowledged: 'Acknowledged',
    expired: 'Expired',
    revoked: 'Revoked',
};

export default function DisseminationStatusBadge({
    status,
}: {
    status: DisseminationStatus;
}) {
    return (
        <Badge
            variant={
                status === 'unacknowledged'
                    ? 'default'
                    : status === 'acknowledged'
                      ? 'secondary'
                      : 'outline'
            }
        >
            {LABELS[status]}
        </Badge>
    );
}
