<?php

namespace App\Support\Persons;

use App\Models\Person;
use App\Support\Search\Trigram;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Person name and alias search with pg_trgm, written so the trigram GIN
 * indexes (persons_search_name_trgm, person_aliases_search_alias_trgm) can
 * be used: 3C runs it nationwide.
 *
 * Matching uses the index operators `%` (similarity) and `<%` (word
 * similarity, for a partial name such as a surname), whose thresholds are
 * set for one transaction only (set_config(..., true) = SET LOCAL). Results
 * are ordered by the best similarity of the name or any alias.
 *
 * Every query here starts from a jurisdiction- and classification-scoped
 * Person query: the id subqueries only narrow it.
 */
final class PersonSearch
{
    /** Search box: pg_trgm similarity threshold. */
    public const SIMILARITY = 0.3;

    /** Search box: word similarity threshold (partial names). */
    public const WORD_SIMILARITY = 0.5;

    /** Possible duplicates on create (prompt: similarity >= 0.6). */
    public const DUPLICATE_SIMILARITY = 0.6;

    /**
     * Runs $callback in a transaction whose trigram thresholds apply to it
     * only. A word similarity of 1 disables partial-name matching.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withThresholds(float $similarity, float $wordSimilarity, Closure $callback): mixed
    {
        return Trigram::withThresholds($similarity, $wordSimilarity, $callback);
    }

    /**
     * Persons whose name or an alias matches $term (use inside
     * withThresholds()). With $partial, a term that matches part of a name
     * (e.g. only the surname) also matches.
     *
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public static function whereNameMatches(Builder $query, string $term, bool $partial = true): Builder
    {
        return $query->whereIn('persons.id', fn (QueryBuilder $ids) => $ids
            ->select('id')
            ->from('persons')
            ->where(fn (QueryBuilder $q) => self::matches($q, 'search_name', $term, $partial))
            ->union(fn (QueryBuilder $aliases) => $aliases
                ->select('person_id')
                ->from('person_aliases')
                ->whereNull('deleted_at')
                ->where(fn (QueryBuilder $q) => self::matches($q, 'search_alias', $term, $partial))));
    }

    /**
     * Adds `name_score` (best similarity of the name or any alias, 0-1).
     *
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public static function selectNameScore(Builder $query, string $term, bool $partial = true): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('persons.*');
        }

        $name = $partial
            ? 'GREATEST(similarity(persons.search_name, t.q), word_similarity(t.q, persons.search_name))'
            : 'similarity(persons.search_name, t.q)';
        $alias = $partial
            ? 'GREATEST(similarity(a.search_alias, t.q), word_similarity(t.q, a.search_alias))'
            : 'similarity(a.search_alias, t.q)';

        return $query->selectRaw(
            "(SELECT GREATEST({$name}, COALESCE((SELECT MAX({$alias}) FROM person_aliases a WHERE a.person_id = persons.id AND a.deleted_at IS NULL), 0))
              FROM (SELECT buffalo_search_text(?) AS q) t) AS name_score",
            [$term],
        );
    }

    /**
     * The search box: name/alias matches, best first (use inside
     * withThresholds(SIMILARITY, WORD_SIMILARITY, ...)).
     *
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public static function byName(Builder $query, string $term): Builder
    {
        return self::selectNameScore(self::whereNameMatches($query, $term), $term)
            ->orderByDesc('name_score')
            ->orderBy('persons.last_name');
    }

    /**
     * NIN and/or phone: blind index, exact match only.
     *
     * @param  Builder<Person>  $query
     * @return Builder<Person>
     */
    public static function byNumber(Builder $query, SearchTerm $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            if ($term->nin !== null) {
                $q->orWhereBlind('nin', 'nin_bidx', $term->nin);
            }

            if ($term->phone !== null) {
                $q->orWhereBlind('phone', 'phone_bidx', $term->phone);
            }
        })->orderBy('persons.last_name');
    }

    /**
     * @param  'search_name'|'search_alias'  $column
     */
    private static function matches(QueryBuilder $query, string $column, string $term, bool $partial): void
    {
        // Literal SQL per column (no interpolation).
        if ($column === 'search_name') {
            $query->whereRaw('search_name % buffalo_search_text(?)', [$term]);
            $partial && $query->orWhereRaw('buffalo_search_text(?) <% search_name', [$term]);

            return;
        }

        $query->whereRaw('search_alias % buffalo_search_text(?)', [$term]);
        $partial && $query->orWhereRaw('buffalo_search_text(?) <% search_alias', [$term]);
    }
}
