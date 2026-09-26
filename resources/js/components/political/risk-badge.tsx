import { cn } from '@/lib/utils';
import type { Option } from '@/types/local-government';

const STYLES: Record<string, string> = {
    low: 'border-slate-400/50 bg-slate-50 text-slate-700 dark:bg-slate-900 dark:text-slate-300',
    medium: 'border-amber-500/50 bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    high: 'border-orange-600/50 bg-orange-50 text-orange-800 dark:bg-orange-950 dark:text-orange-300',
    critical:
        'border-red-600/60 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-300',
};

/** A person of interest's risk level. */
export default function RiskBadge({
    risk,
    className,
}: {
    risk: Option;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded border px-1.5 py-0.5 text-xs font-semibold',
                STYLES[risk.value],
                className,
            )}
            data-risk={risk.value}
        >
            {risk.label} risk
        </span>
    );
}
