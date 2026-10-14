<?php

namespace App\Services\Search\Providers;

use App\Models\User;
use App\Services\Search\SearchProvider;
use App\Services\Search\SearchQuery;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Shared helpers for global search providers (Phase 7B).
 */
abstract class BaseProvider implements SearchProvider
{
    abstract protected function permission(): string;

    public function allows(User $user): bool
    {
        return $user->checkPermissionTo($this->permission());
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText();
    }

    /** "Kidnapping · Ikeja LGA · 3 days ago" (empty parts dropped). */
    protected static function subtitle(?string ...$parts): string
    {
        return implode(' · ', array_values(array_filter($parts, fn (?string $p) => $p !== null && $p !== '')));
    }

    protected static function ago(?CarbonInterface $at): ?string
    {
        return $at?->diffForHumans();
    }

    /**
     * Ranks results in the state lens's states first (Phase 11A.2); the
     * others follow in their usual order. Ordering only: nothing is added
     * or removed.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $builder
     * @return Builder<TModel>
     */
    protected static function preferStates(Builder $builder, SearchQuery $query, string $stateColumn): Builder
    {
        if ($query->preferredStates === []) {
            return $builder;
        }

        $ids = implode(',', array_map('intval', $query->preferredStates));
        // Prepended, so it leads whatever order the query already has.
        $base = $builder->getQuery();
        $base->orders = [['type' => 'Raw', 'sql' => "CASE WHEN {$stateColumn} IN ({$ids}) THEN 0 ELSE 1 END"], ...($base->orders ?? [])];

        return $builder;
    }

    protected static function score(mixed $value): float
    {
        return max(0.0, min(1.0, (float) $value));
    }
}
