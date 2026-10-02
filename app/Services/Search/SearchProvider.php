<?php

namespace App\Services\Search;

use App\Models\User;

/**
 * One module in global search (Phase 7B). Every query starts from the
 * module's own scoped Eloquent query (jurisdiction, classification, grants,
 * disseminations): providers never run in the system context.
 *
 * GlobalSearch runs each call inside a SearchBudget (statement timeout and
 * trigram thresholds for that transaction only).
 */
interface SearchProvider
{
    /** Section key, e.g. "incidents". */
    public function key(): string;

    public function label(): string;

    /** Whether the user may search this module (permission). */
    public function allows(User $user): bool;

    public function supports(SearchQuery $query): bool;

    /**
     * @return list<SearchHit> best first
     */
    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array;

    /** The viewer's own scoped number of matches. */
    public function count(SearchQuery $query, User $user): int;
}
