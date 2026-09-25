import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

/**
 * Styled native <select>, matching the shadcn input.
 */
export default function NativeSelect({
    className,
    ...props
}: ComponentProps<'select'>) {
    return (
        <select
            className={cn(
                'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                className,
            )}
            {...props}
        />
    );
}
