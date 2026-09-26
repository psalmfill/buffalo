import { Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ClassificationLevel } from '@/types/auth';

const STYLES: Record<ClassificationLevel, string> = {
    restricted:
        'border-emerald-600/40 bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    confidential:
        'border-blue-600/40 bg-blue-50 text-blue-800 dark:bg-blue-950 dark:text-blue-300',
    secret: 'border-red-600/50 bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-300',
};

const LABELS: Record<ClassificationLevel, string> = {
    restricted: 'Restricted',
    confidential: 'Confidential',
    secret: 'Secret',
};

/** A record's classification (CLAUDE.md section 4a). */
export default function ClassificationBadge({
    level,
    className,
}: {
    level: ClassificationLevel;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-xs font-semibold tracking-wide uppercase',
                STYLES[level],
                className,
            )}
            data-classification={level}
        >
            {level !== 'restricted' && <Lock className="size-3" />}
            {LABELS[level]}
        </span>
    );
}
