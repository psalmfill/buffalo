import { router } from '@inertiajs/react';
import { Eye, X } from 'lucide-react';
import { useLens } from '@/hooks/use-lens';
import LensController from '@/actions/App/Http/Controllers/Dashboard/LensController';

/**
 * The state lens picker in the header (Phase 11A.2), national users only:
 * "Viewing: All of Nigeria / [Zone] / [State]". The lens pre-sets filters;
 * it never changes what the user may see.
 */
export function LensPicker() {
    const lens = useLens();

    if (lens === null) {
        return null;
    }

    const value = lens.current
        ? `${lens.current.type}:${lens.current.ulid}`
        : '';

    const choose = (next: string) => {
        if (next === '') {
            router.delete(LensController.destroy.url());

            return;
        }

        const [type, ulid] = next.split(':');
        router.put(LensController.update.url(), { type, ulid });
    };

    return (
        <label className="hidden items-center gap-1 text-xs text-muted-foreground md:inline-flex">
            <Eye className="size-3 shrink-0" aria-hidden />
            <span>Viewing:</span>
            <select
                className="h-7 max-w-44 rounded-md border bg-background px-1 text-xs text-foreground"
                value={value}
                onChange={(e) => choose(e.target.value)}
                data-test="lens-picker"
                aria-label="State lens"
            >
                <option value="">All of Nigeria</option>
                {lens.zones.length > 0 && (
                    <optgroup label="Zones">
                        {lens.zones.map((zone) => (
                            <option key={zone.ulid} value={`zone:${zone.ulid}`}>
                                {zone.name}
                            </option>
                        ))}
                    </optgroup>
                )}
                <optgroup label="States">
                    {lens.states.map((state) => (
                        <option key={state.ulid} value={`state:${state.ulid}`}>
                            {state.name}
                        </option>
                    ))}
                </optgroup>
            </select>
        </label>
    );
}

/**
 * A clearly visible chip showing the lens on every page, with one click to
 * clear it (Phase 11A.2).
 */
export function LensChip() {
    const lens = useLens();

    if (!lens?.current) {
        return null;
    }

    return (
        <div
            className="flex items-center justify-between gap-2 border-b-2 border-brand-accent bg-muted px-4 py-1 text-xs"
            data-test="lens-chip"
        >
            <span>
                Viewing <strong>{lens.current.name}</strong>
                {lens.current.type === 'zone' ? ' zone' : ''}. Lists, the map
                and analytics open on it; your access is unchanged.
            </span>
            <button
                type="button"
                className="inline-flex items-center gap-1 rounded-md border bg-background px-2 py-0.5 hover:bg-accent"
                onClick={() => router.delete(LensController.destroy.url())}
            >
                <X className="size-3" aria-hidden />
                Clear lens
            </button>
        </div>
    );
}
