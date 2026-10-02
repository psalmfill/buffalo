import { router } from '@inertiajs/react';
import { clearRecentSearches } from '@/lib/recent-searches';

/**
 * Clears everything this browser holds for the signed-in user (Phase 8A):
 * Inertia's prefetch cache, its encrypted history (the key is discarded,
 * so Back cannot show earlier pages), recent searches (7B) and session
 * storage. The appearance preference is kept: it is not per user.
 * Call it before every sign-out request.
 */
export function clearClientState(): void {
    router.flushAll();
    router.clearHistory();
    clearRecentSearches();

    try {
        window.sessionStorage.clear();
    } catch {
        // Storage may be unavailable (private mode); nothing to clear.
    }
}
