/**
 * Recent global searches (Phase 7B): kept in this browser only, per user,
 * never sent to or stored on the server. Cleared on sign-out. Storage may
 * be unavailable (private windows): every access is guarded.
 */
const LIMIT = 8;
const PREFIX = 'buffalo.search.recent.';

const key = (userUlid: string) => PREFIX + userUlid;

export function recentSearches(userUlid: string): string[] {
    try {
        const value: unknown = JSON.parse(
            window.localStorage.getItem(key(userUlid)) ?? '[]',
        );

        return Array.isArray(value)
            ? value.filter((v): v is string => typeof v === 'string')
            : [];
    } catch {
        return [];
    }
}

export function rememberSearch(userUlid: string, query: string): void {
    const q = query.trim();

    if (q.length < 2) {
        return;
    }

    try {
        const list = [
            q,
            ...recentSearches(userUlid).filter((v) => v !== q),
        ].slice(0, LIMIT);
        window.localStorage.setItem(key(userUlid), JSON.stringify(list));
    } catch {
        // Storage unavailable: nothing to remember.
    }
}

/** Removes every user's recent searches from this browser (sign-out). */
export function clearRecentSearches(): void {
    try {
        Object.keys(window.localStorage)
            .filter((k) => k.startsWith(PREFIX))
            .forEach((k) => window.localStorage.removeItem(k));
    } catch {
        // Storage unavailable.
    }
}
