<?php

namespace App\Services\Search;

use App\Models\User;
use App\Support\Classification\ClassificationGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Global search (Phase 7B): one search box across every module the user
 * may see.
 *
 * - Each provider runs as its own query, through its module's scopes, in a
 *   SearchBudget (time limit; a timed-out provider is reported as "more
 *   results may exist").
 * - Never the system context, never cross-jurisdiction person matching
 *   (that stays in /persons/search), and listing is not viewing: nothing
 *   here writes record_access_logs.
 * - Every search request is logged in search_logs with the query's SHA-256
 *   hash only.
 */
final class GlobalSearch
{
    /**
     * @param  iterable<SearchProvider>  $providers  in section order
     */
    public function __construct(private readonly iterable $providers) {}

    /** Intelligence roles only: super admin (and no role) get nothing. */
    public static function canSearch(User $user): bool
    {
        return app(ClassificationGate::class)->maxLevelFor($user) !== null;
    }

    /**
     * The header view: up to `quick_limit` results per type, plus the
     * overall best results (exact first, then score, then recency).
     *
     * @param  list<int>  $preferredStates  ranked first (the state lens)
     * @return array{query: string, type: string|null, sections: list<array<string, mixed>>, top: list<array<string, mixed>>}
     */
    public function quick(User $user, string $input, array $preferredStates = []): array
    {
        $query = SearchQuery::parse($input)->preferring($preferredStates);

        if (! $query->isSearchable() || ! self::canSearch($user)) {
            return ['query' => $query->raw, 'type' => null, 'sections' => [], 'top' => []];
        }

        $limit = (int) config('buffalo.search.quick_limit');
        $sections = [];
        $all = [];

        foreach ($this->available($query, $user) as $provider) {
            $started = hrtime(true);
            $hits = SearchBudget::run(fn () => $provider->hits($query, $user, $limit + 1));
            $took = (int) round((hrtime(true) - $started) / 1e6);

            if ($hits === []) {
                continue;
            }

            $shown = $hits === null ? [] : array_slice($hits, 0, $limit);
            $all = [...$all, ...$shown];
            $sections[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'hits' => array_map(fn (SearchHit $h) => $h->toArray(), $shown),
                'more' => $hits === null || count($hits) > $limit,
                'timedOut' => $hits === null,
                'tookMs' => $took,
            ];
        }

        $this->log($user, $query, count($all));

        return [
            'query' => $query->raw,
            'type' => $query->type,
            'sections' => $sections,
            'top' => array_map(fn (SearchHit $h) => $h->toArray(), array_slice(SearchHit::rank($all), 0, $limit)),
        ];
    }

    /**
     * The results page: the viewer's own scoped count per type, and one
     * page of the chosen type (default: the first type with results).
     *
     * @param  list<int>  $preferredStates  ranked first (the state lens)
     * @return array{query: string, type: string|null, tabs: list<array<string, mixed>>, active: string|null, hits: list<array<string, mixed>>, page: int, pages: int, limit: int, timedOut: bool}
     */
    public function full(User $user, string $input, ?string $type, int $page, array $preferredStates = []): array
    {
        $query = SearchQuery::parse($input)->preferring($preferredStates);
        $empty = ['query' => $query->raw, 'type' => null, 'tabs' => [], 'active' => null, 'hits' => [], 'page' => 1, 'pages' => 0, 'limit' => (int) config('buffalo.search.max_results'), 'timedOut' => false];

        if (! $query->isSearchable() || ! self::canSearch($user)) {
            return $empty;
        }

        $tabs = [];
        $providers = [];

        foreach ($this->available($query, $user) as $provider) {
            $started = hrtime(true);
            $count = SearchBudget::run(fn () => $provider->count($query, $user));
            $providers[$provider->key()] = $provider;
            $tabs[] = [
                'key' => $provider->key(),
                'label' => $provider->label(),
                'count' => $count,
                'timedOut' => $count === null,
                'tookMs' => (int) round((hrtime(true) - $started) / 1e6),
            ];
        }

        $active = $type !== null && isset($providers[$type])
            ? $type
            : (collect($tabs)->first(fn (array $t) => ($t['count'] ?? 0) > 0)['key'] ?? ($tabs[0]['key'] ?? null));
        $size = (int) config('buffalo.search.page_size');
        $count = (int) (collect($tabs)->firstWhere('key', $active)['count'] ?? 0);
        // At most max_results are listed per type; counts stay exact.
        $pages = (int) max(1, ceil(min($count, (int) config('buffalo.search.max_results')) / $size));
        $page = max(1, min($page, $pages));
        $hits = $active === null ? [] : SearchBudget::run(fn () => $providers[$active]->hits($query, $user, $size, ($page - 1) * $size));

        $this->log($user, $query, (int) collect($tabs)->sum(fn (array $t) => $t['count'] ?? 0));

        return [
            'query' => $query->raw,
            'type' => $query->type,
            'tabs' => $tabs,
            'active' => $active,
            'hits' => array_map(fn (SearchHit $h) => $h->toArray(), $hits ?? []),
            'page' => $page,
            'pages' => $pages,
            'limit' => (int) config('buffalo.search.max_results'),
            'timedOut' => $hits === null,
        ];
    }

    /**
     * @return list<SearchProvider>
     */
    private function available(SearchQuery $query, User $user): array
    {
        $list = [];

        foreach ($this->providers as $provider) {
            if ($provider->supports($query) && $provider->allows($user)) {
                $list[] = $provider;
            }
        }

        return $list;
    }

    private function log(User $user, SearchQuery $query, int $results): void
    {
        DB::table('search_logs')->insert([
            'user_id' => $user->id,
            'query_hash' => $query->hash(),
            'query_type' => $query->type,
            'result_count' => $results,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
