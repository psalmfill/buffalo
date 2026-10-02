import { beforeEach, describe, expect, it, vi } from 'vitest';

const router = vi.hoisted(() => ({
    flushAll: vi.fn(),
    clearHistory: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router }));

/** A minimal Storage for the Node test environment. */
function memoryStorage(): Storage {
    const items = new Map<string, string>();

    return {
        get length() {
            return items.size;
        },
        clear: () => items.clear(),
        getItem: (key) => items.get(key) ?? null,
        key: (index) => [...items.keys()][index] ?? null,
        removeItem: (key) => void items.delete(key),
        setItem: (key, value) => void items.set(key, value),
    };
}

describe('clearClientState', () => {
    beforeEach(() => {
        const localStorage = memoryStorage();
        const sessionStorage = memoryStorage();
        localStorage.setItem('appearance', 'dark');
        sessionStorage.setItem('inertia-history-key', 'secret');
        vi.stubGlobal('window', { localStorage, sessionStorage });
        router.flushAll.mockClear();
        router.clearHistory.mockClear();
    });

    it('flushes the prefetch cache, clears encrypted history and session storage', async () => {
        const { clearClientState } = await import('@/lib/sign-out');

        clearClientState();

        expect(router.flushAll).toHaveBeenCalledOnce();
        expect(router.clearHistory).toHaveBeenCalledOnce();
        expect(window.sessionStorage.length).toBe(0);
        expect(window.localStorage.getItem('appearance')).toBe('dark');
    });
});
