import { cn } from '@/lib/utils';
import type { CoverageRating, CoverageStatus } from '@/types/coverage';

const RATING_STYLES: Record<CoverageRating, string> = {
    adequate:
        'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    partial:
        'border-amber-600/40 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    inadequate:
        'border-orange-600/50 bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    critical:
        'border-red-600/50 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-300',
};

const STATUS_STYLES: Record<CoverageStatus | 'missing', string> = {
    missing:
        'border-red-600/40 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-300',
    draft: 'border-slate-400/50 bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-300',
    submitted:
        'border-blue-600/40 bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    returned:
        'border-amber-600/40 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    reviewed:
        'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
};

const BASE =
    'inline-flex items-center rounded border px-1.5 py-0.5 text-xs font-medium';

/** A coverage rating; the text always carries the meaning, not the colour. */
export function RatingBadge({
    rating,
}: {
    rating: { value: CoverageRating; label: string } | null;
}) {
    if (rating === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <span className={cn(BASE, RATING_STYLES[rating.value])}>
            {rating.label}
        </span>
    );
}

export function CoverageStatusBadge({
    status,
}: {
    status: { value: CoverageStatus | 'missing'; label: string };
}) {
    return (
        <span className={cn(BASE, STATUS_STYLES[status.value])}>
            {status.label}
        </span>
    );
}
