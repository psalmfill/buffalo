import { router } from '@inertiajs/react';
import { Clock, Loader2, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import SearchHitRow from '@/components/search/search-hit-row';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useCurrentUser } from '@/hooks/use-auth';
import { rememberSearch, recentSearches } from '@/lib/recent-searches';
import { cn } from '@/lib/utils';
import type { QuickResults, SearchHit } from '@/types/search';

const DEBOUNCE_MS = 250;

/** Whether keyboard focus is in a text field (then "/" types a slash). */
function typing(target: EventTarget | null): boolean {
    return (
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))
    );
}

/**
 * Header quick search (Phase 7B): a command palette opened with "/" or
 * Ctrl/Cmd+K. Debounced; up to 5 results per type; Enter opens the
 * highlighted result (the best match by default), arrow keys move. Recent
 * searches stay in this browser only.
 */
export default function GlobalSearch() {
    const user = useCurrentUser();
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<QuickResults | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [active, setActive] = useState(0);
    const [recent, setRecent] = useState<string[]>([]);
    const request = useRef<AbortController | null>(null);

    const openPalette = useCallback(() => {
        setRecent(recentSearches(user.ulid));
        setOpen(true);
    }, [user.ulid]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (
                (e.key === 'k' && (e.metaKey || e.ctrlKey)) ||
                (e.key === '/' && !typing(e.target))
            ) {
                e.preventDefault();
                openPalette();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [openPalette]);

    const changeQuery = (value: string) => {
        setQuery(value);

        if (value.trim().length < 2) {
            request.current?.abort();
            setResults(null);
            setLoading(false);
            setError(null);
        }
    };

    // Debounced fetch for queries of two characters or more.
    useEffect(() => {
        const q = query.trim();

        if (q.length < 2) {
            return;
        }

        const timer = window.setTimeout(() => {
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            setLoading(true);
            fetch(SearchController.quick.url({ query: { q } }), {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: controller.signal,
            })
                .then(async (response) => {
                    const body: unknown = await response.json();

                    if (!response.ok) {
                        const message = (body as { message?: string }).message;
                        throw new Error(message ?? 'Search failed.');
                    }

                    setResults(body as QuickResults);
                    setActive(0);
                    setError(null);
                })
                .catch((e: unknown) => {
                    if (!(
                        e instanceof DOMException && e.name === 'AbortError'
                    )) {
                        setError(
                            e instanceof Error ? e.message : 'Search failed.',
                        );
                    }
                })
                .finally(() => setLoading(false));
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [query]);

    // Best match first, then each section in order (without repeating it);
    // every row gets its position for keyboard navigation.
    const groups = useMemo(() => {
        if (!results) {
            return [];
        }

        const best = results.top[0];
        const same = (h: SearchHit) =>
            best !== undefined && h.type === best.type && h.ulid === best.ulid;
        const list: {
            key: string;
            label: string;
            note: string | null;
            hits: SearchHit[];
        }[] = [
            ...(best
                ? [
                      {
                          key: 'best',
                          label: 'Best match',
                          note: null,
                          hits: [best],
                      },
                  ]
                : []),
            ...results.sections.map((s) => ({
                key: s.key,
                label: s.label,
                note: s.timedOut
                    ? 'More results may exist'
                    : s.more
                      ? 'More…'
                      : null,
                hits: s.hits.filter((h) => !same(h)),
            })),
        ];
        let position = 0;

        return list
            .filter((g) => g.hits.length > 0)
            .map((g) => ({
                ...g,
                rows: g.hits.map((hit) => ({ hit, id: position++ })),
            }));
    }, [results]);

    const items = useMemo(
        () => groups.flatMap((g) => g.rows.map((r) => r.hit)),
        [groups],
    );

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setResults(null);
    }, []);

    const go = (href: string) => {
        rememberSearch(user.ulid, query);
        close();
        router.visit(href);
    };

    const showAll = () =>
        go(SearchController.index.url({ query: { q: query.trim() } }));

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => Math.min(i + 1, Math.max(items.length - 1, 0)));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const hit = items[active];

            if (hit) {
                go(hit.href);
            } else if (query.trim().length >= 2) {
                showAll();
            }
        }
    };

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                className="hidden w-56 justify-start gap-2 text-muted-foreground md:inline-flex"
                onClick={openPalette}
                aria-label="Search (press / or Ctrl+K)"
            >
                <Search className="size-4" /> Search…
                <kbd className="ml-auto rounded border px-1.5 text-[10px]">
                    /
                </kbd>
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="md:hidden"
                onClick={openPalette}
                aria-label="Search"
            >
                <Search className="size-4" />
            </Button>

            <Dialog
                open={open}
                onOpenChange={(o) => (o ? openPalette() : close())}
            >
                <DialogContent className="top-[15%] translate-y-0 gap-0 p-0 sm:max-w-2xl">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Search everything you are allowed to see: references,
                        names, places and organizations.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-3">
                        <Search className="size-4 text-muted-foreground" />
                        <input
                            autoFocus
                            value={query}
                            onChange={(e) => changeQuery(e.target.value)}
                            onKeyDown={onKeyDown}
                            placeholder="Reference, name, NIN, phone, place or organization"
                            className="h-12 flex-1 bg-transparent text-sm outline-none"
                            aria-label="Search"
                            role="combobox"
                            aria-expanded={items.length > 0}
                            aria-controls="global-search-results"
                            aria-activedescendant={
                                items[active]
                                    ? `search-hit-${active}`
                                    : undefined
                            }
                        />
                        {loading && (
                            <Loader2 className="size-4 animate-spin text-muted-foreground" />
                        )}
                    </div>

                    <div
                        id="global-search-results"
                        role="listbox"
                        className="max-h-[60vh] overflow-y-auto p-2"
                    >
                        {query.trim().length < 2 && recent.length > 0 && (
                            <div className="space-y-1">
                                <p className="px-2 py-1 text-xs font-medium text-muted-foreground">
                                    Recent searches
                                </p>
                                {recent.map((r) => (
                                    <button
                                        key={r}
                                        type="button"
                                        className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-sm hover:bg-accent"
                                        onClick={() => changeQuery(r)}
                                    >
                                        <Clock className="size-3.5 text-muted-foreground" />{' '}
                                        {r}
                                    </button>
                                ))}
                            </div>
                        )}

                        {error && (
                            <p className="px-2 py-3 text-sm text-destructive">
                                {error}
                            </p>
                        )}

                        {results &&
                            items.length === 0 &&
                            !loading &&
                            !error && (
                                <p className="px-2 py-3 text-sm text-muted-foreground">
                                    Nothing you can see matches “{results.query}
                                    ”.
                                </p>
                            )}

                        {groups.map((group) => (
                            <section key={group.key} className="mb-2">
                                <p className="flex items-center justify-between px-2 py-1 text-xs font-medium text-muted-foreground">
                                    {group.label}
                                    {group.note && <span>{group.note}</span>}
                                </p>
                                {group.rows.map(({ hit, id }) => (
                                    <Option
                                        key={`${hit.type}:${hit.ulid}`}
                                        hit={hit}
                                        id={id}
                                        active={active === id}
                                        onSelect={go}
                                        onHover={setActive}
                                    />
                                ))}
                            </section>
                        ))}
                    </div>

                    {query.trim().length >= 2 && (
                        <div className="flex items-center justify-between border-t px-3 py-2 text-xs text-muted-foreground">
                            <span>
                                ↑↓ to move · Enter to open · Esc to close
                            </span>
                            <button
                                type="button"
                                className="font-medium text-foreground hover:underline"
                                onClick={showAll}
                            >
                                See all results
                            </button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function Option({
    hit,
    id,
    active,
    onSelect,
    onHover,
}: {
    hit: SearchHit;
    id: number;
    active: boolean;
    onSelect: (href: string) => void;
    onHover: (id: number) => void;
}) {
    return (
        <button
            type="button"
            id={`search-hit-${id}`}
            role="option"
            aria-selected={active}
            className={cn(
                'w-full rounded px-2 py-2 text-left text-sm',
                active ? 'bg-accent' : 'hover:bg-accent/60',
            )}
            onMouseEnter={() => onHover(id)}
            onClick={() => onSelect(hit.href)}
        >
            <SearchHitRow hit={hit} />
        </button>
    );
}
