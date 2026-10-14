import { router } from '@inertiajs/react';
import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useLens } from '@/hooks/use-lens';

const PLACE_KEYS = ['state', 'zone', 'lga'];

/**
 * Query parameters for a filter change (Phase 11A.2, the state lens): a
 * `zone` filter in the URL is kept unless the page sets it, and when a
 * lens user leaves no place filter `lens=off` is sent, so the server does
 * not put the lens back.
 */
export function placeAwareQuery(
    params: Record<string, string>,
    lensActive: boolean,
): Record<string, string> {
    const query = { ...params };
    const current = new URLSearchParams(window.location.search);

    if (!('zone' in query) && current.get('zone')) {
        query.zone = current.get('zone') ?? '';
    }

    const filled = Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== ''),
    );

    if (lensActive && !PLACE_KEYS.some((key) => key in filled)) {
        filled.lens = 'off';
    }

    return filled;
}

/**
 * Keeps filter values in local state and applies them as query-string
 * parameters (server-side filtering).
 */
export function useFilters<T extends Record<string, string>>(
    url: string,
    initial: T,
) {
    const [values, setValues] = useState<T>(initial);
    const lensActive = useLens()?.current != null;

    const apply = (next: Partial<T> = {}) => {
        const merged = { ...values, ...next };
        setValues(merged);
        router.get(url, placeAwareQuery(merged, lensActive), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const reset = () => {
        const cleared = Object.fromEntries(
            Object.keys(values).map((key) => [key, '']),
        ) as T;
        setValues(cleared);
        router.get(url, lensActive ? { lens: 'off' } : {}, {
            preserveState: true,
            replace: true,
        });
    };

    return { values, setValues, apply, reset };
}

/**
 * The place a list is narrowed to by a `state` or `zone` URL filter (as
 * the state lens sets it), with one click to remove it.
 */
export function PlaceFilterChip() {
    const lens = useLens();

    if (lens === null) {
        return null;
    }

    const query = new URLSearchParams(window.location.search);
    const zone = lens.zones.find((z) => z.ulid === query.get('zone'));
    const state = lens.states.find((s) => s.ulid === query.get('state'));
    const label = state ? state.name : zone ? `${zone.name} zone` : null;

    if (label === null) {
        return null;
    }

    const remove = () => {
        query.delete('zone');
        query.delete('state');
        query.delete('lga');
        query.delete('page');
        query.set('lens', 'off');
        router.get(window.location.pathname, Object.fromEntries(query), {
            preserveScroll: true,
        });
    };

    return (
        <span
            className="inline-flex items-center gap-1 rounded-full border bg-primary/10 px-2 py-0.5 text-xs"
            data-test="place-filter-chip"
        >
            Place: {label}
            <button
                type="button"
                onClick={remove}
                aria-label={`Remove the ${label} filter`}
                className="rounded-full hover:bg-accent"
            >
                <X className="size-3" aria-hidden />
            </button>
        </span>
    );
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
        <div className="space-y-2">
            <PlaceFilterChip />
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
        </div>
    );
}
