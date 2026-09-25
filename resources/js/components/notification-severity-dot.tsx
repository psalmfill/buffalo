import { cn } from '@/lib/utils';
import type { NotificationSeverity } from '@/types/notifications';

const colours: Record<NotificationSeverity, string> = {
    info: 'bg-sky-500',
    warning: 'bg-amber-500',
    critical: 'bg-red-600',
};

export default function NotificationSeverityDot({
    severity,
    className,
}: {
    severity: NotificationSeverity;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-block size-2 shrink-0 rounded-full',
                colours[severity],
                className,
            )}
            aria-label={`${severity} severity`}
            role="img"
        />
    );
}
