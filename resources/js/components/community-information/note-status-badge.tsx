import { Badge } from '@/components/ui/badge';

/** A security note's status: active, monitoring or resolved. */
export default function NoteStatusBadge({
    status,
    label,
}: {
    status: string;
    label: string;
}) {
    return (
        <Badge
            variant={
                status === 'active'
                    ? 'destructive'
                    : status === 'monitoring'
                      ? 'default'
                      : 'secondary'
            }
        >
            {label}
        </Badge>
    );
}
