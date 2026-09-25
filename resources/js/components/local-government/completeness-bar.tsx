import { cn } from '@/lib/utils';

/** Profile completeness as a percentage with a small bar. */
export default function CompletenessBar({
    percent,
    className,
}: {
    percent: number;
    className?: string;
}) {
    return (
        <div
            className={cn('flex items-center gap-2', className)}
            title={`${percent}% complete`}
        >
            <div
                className="h-1.5 w-16 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="Profile completeness"
            >
                <div
                    className={cn(
                        'h-full rounded-full',
                        percent >= 80
                            ? 'bg-emerald-600'
                            : percent >= 50
                              ? 'bg-amber-500'
                              : 'bg-red-600',
                    )}
                    style={{ width: `${percent}%` }}
                />
            </div>
            <span className="text-sm tabular-nums">{percent}%</span>
        </div>
    );
}
