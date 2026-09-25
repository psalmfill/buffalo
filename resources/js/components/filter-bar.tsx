import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

/**
 * Keeps filter values in local state and applies them as query-string
 * parameters (server-side filtering).
 */
export function useFilters<T extends Record<string, string>>(
    url: string,
    initial: T,
) {
    const [values, setValues] = useState<T>(initial);

    const apply = (next: Partial<T> = {}) => {
        const merged = { ...values, ...next };
        setValues(merged);
        router.get(
            url,
            Object.fromEntries(
                Object.entries(merged).filter(([, value]) => value !== ''),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const reset = () => {
        const cleared = Object.fromEntries(
            Object.keys(values).map((key) => [key, '']),
        ) as T;
        setValues(cleared);
        router.get(url, {}, { preserveState: true, replace: true });
    };

    return { values, setValues, apply, reset };
}

export function FilterBar({
    children,
    onApply,
    onReset,
}: {
    children: ReactNode;
    onApply: () => void;
    onReset: () => void;
}) {
    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                onApply();
            }}
            className="grid items-end gap-3 md:grid-cols-3 lg:grid-cols-6"
        >
            {children}
            <div className="flex gap-2">
                <Button type="submit">Filter</Button>
                <Button type="button" variant="outline" onClick={onReset}>
                    Reset
                </Button>
            </div>
        </form>
    );
}
