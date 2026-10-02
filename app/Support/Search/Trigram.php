<?php

namespace App\Support\Search;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * pg_trgm thresholds for one transaction (CLAUDE.md decisions log: fuzzy
 * searches use the index operators `%` and `<%` with thresholds set per
 * transaction, never bare similarity()/word_similarity() filters).
 */
final class Trigram
{
    /**
     * Runs $callback in a transaction with the similarity (`%`) and word
     * similarity (`<%`) thresholds set.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withThresholds(float $similarity, float $wordSimilarity, Closure $callback): mixed
    {
        return DB::transaction(function () use ($similarity, $wordSimilarity, $callback): mixed {
            DB::select(
                "SELECT set_config('pg_trgm.similarity_threshold', ?, true), set_config('pg_trgm.word_similarity_threshold', ?, true)",
                [number_format($similarity, 2, '.', ''), number_format($wordSimilarity, 2, '.', '')],
            );

            return $callback();
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function withWordSimilarity(float $threshold, Closure $callback): mixed
    {
        return self::withThresholds(0.3, $threshold, $callback);
    }
}
