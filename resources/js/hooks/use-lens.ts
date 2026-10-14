import { usePage } from '@inertiajs/react';
import type { Lens } from '@/types/lens';

/** The state lens, or null for users without one (Phase 11A.2). */
export function useLens(): Lens | null {
    return usePage().props.lens ?? null;
}
