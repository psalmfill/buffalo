import { Head, Link, router } from '@inertiajs/react';
import { Search, UserSearch } from 'lucide-react';
import { useEffect, useState } from 'react';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import SearchController from '@/actions/App/Http/Controllers/Search/SearchController';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import SearchHitRow from '@/components/search/search-hit-row';
import { SearchIcon } from '@/components/search/search-icons';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useCurrentUser } from '@/hooks/use-auth';
import { rememberSearch } from '@/lib/recent-searches';
import { cn } from '@/lib/utils';
import type { FullResults } from '@/types/search';

type Props = {
    results: FullResults;
    can: { crossJurisdiction: boolean };
};

const KIND_NOTES: Record<string, string> = {
    reference: 'Exact reference lookup.',
    nin: 'Exact NIN (or phone) lookup.',
    phone: 'Exact phone lookup.',
};

/**
 * Global search results (Phase 7B): one tab per type with the viewer's own
 * scoped counts, 25 results per page. Records in other jurisdictions are
 * never listed here; the persons search checks other states' records.
 */
export default function SearchIndex({ results, can }: Props) {
    const user = useCurrentUser();
    const [q, setQ] = useState(results.query);
    const searched = results.query.length >= 2;

    useEffect(() => {
        if (searched) {
            rememberSearch(user.ulid, results.query);
        }
    }, [results.query, searched, user.ulid]);

    const visit = (query: Record<string, string | number>) =>
        router.get(SearchController.index.url(), query, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title="Search" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Search"
                    description="Everything you are allowed to see: references, names, places and organizations."
                />

                <form
                    className="flex max-w-2xl gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        visit({ q });
                    }}
                >
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Reference, name, NIN, phone, place or organization"
                        aria-label="Search"
                    />
                    <Button type="submit">
                        <Search /> Search
                    </Button>
                </form>

                {searched && results.type && KIND_NOTES[results.type] && (
                    <p className="text-sm text-muted-foreground">
                        {KIND_NOTES[results.type]}
                    </p>
                )}

                {searched &&
                    can.crossJurisdiction &&
                    (results.type === 'text' ||
                        results.type === 'nin' ||
                        results.type === 'phone') && (
                        <p className="text-sm">
                            {/* The persons search is a POST: the query stays out of the URL. */}
                            <button
                                type="button"
                                onClick={() =>
                                    router.post(PersonController.search.url(), {
                                        q: results.query.slice(0, 100),
                                    })
                                }
                                className="inline-flex items-center gap-1 font-medium hover:underline"
                            >
                                <UserSearch className="size-4" /> Check other
                                states' person records
                            </button>
                        </p>
                    )}

                {!searched ? (
                    <EmptyState
                        title="Type at least two characters"
                        description="Search by reference number, name, NIN, phone number, place or organization."
                    />
                ) : results.tabs.length === 0 ? (
                    <EmptyState
                        title="Nothing to search"
                        description="None of the modules you can search handle this kind of query."
                    />
                ) : (
                    <div className="space-y-4">
                        <nav
                            className="flex flex-wrap gap-1 border-b"
                            aria-label="Result types"
                        >
                            {results.tabs.map((t) => (
                                <Link
                                    key={t.key}
                                    href={SearchController.index.url({
                                        query: {
                                            q: results.query,
                                            type: t.key,
                                        },
                                    })}
                                    preserveScroll
                                    className={cn(
                                        '-mb-px inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm',
                                        results.active === t.key
                                            ? 'border-primary font-medium'
                                            : 'border-transparent text-muted-foreground hover:text-foreground',
                                    )}
                                    aria-current={
                                        results.active === t.key
                                            ? 'page'
                                            : undefined
                                    }
                                >
                                    <SearchIcon
                                        type={t.key}
                                        className="size-4"
                                    />
                                    {t.label}
                                    <span className="rounded bg-muted px-1.5 text-xs">
                                        {t.timedOut ? '…' : t.count}
                                    </span>
                                </Link>
                            ))}
                        </nav>

                        {results.tabs.find((t) => t.key === results.active)
                            ?.timedOut && (
                            <p className="text-sm text-muted-foreground">
                                This search took too long to count: more results
                                may exist.
                            </p>
                        )}

                        {(results.tabs.find((t) => t.key === results.active)
                            ?.count ?? 0) > results.limit && (
                            <p className="text-sm text-muted-foreground">
                                Showing the best{' '}
                                {results.limit.toLocaleString('en-NG')} results.
                                Refine your search to see others.
                            </p>
                        )}

                        {results.hits.length === 0 ? (
                            <EmptyState
                                title="No results"
                                description={`Nothing you can see matches “${results.query}” here.`}
                            />
                        ) : (
                            <ul
                                className="divide-y rounded-lg border"
                                aria-label="Results"
                            >
                                {results.hits.map((hit) => (
                                    <li key={`${hit.type}:${hit.ulid}`}>
                                        <Link
                                            href={hit.href}
                                            className="block px-3 py-2 text-sm hover:bg-accent"
                                        >
                                            <SearchHitRow hit={hit} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {results.pages > 1 && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Page {results.page} of {results.pages}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={results.page <= 1}
                                        onClick={() =>
                                            visit({
                                                q: results.query,
                                                type: results.active ?? '',
                                                page: results.page - 1,
                                            })
                                        }
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={results.page >= results.pages}
                                        onClick={() =>
                                            visit({
                                                q: results.query,
                                                type: results.active ?? '',
                                                page: results.page + 1,
                                            })
                                        }
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

SearchIndex.layout = {
    breadcrumbs: [{ title: 'Search', href: SearchController.index() }],
};
