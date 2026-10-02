<?php

namespace App\Services\Search;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Runs one provider query with its own time budget (Phase 7B): a
 * transaction with `statement_timeout` and the trigram thresholds set for
 * it only (set_config(..., true) = SET LOCAL). A query cancelled by the
 * timeout returns null ("more results may exist") instead of failing the
 * whole search. The previous timeout is restored, so an enclosing
 * transaction is not left with a short one.
 */
final class SearchBudget
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T|null null when the budget ran out
     */
    public static function run(Closure $callback, ?int $milliseconds = null): mixed
    {
        $milliseconds ??= (int) config('buffalo.search.provider_timeout_ms');

        try {
            return DB::transaction(function () use ($callback, $milliseconds): mixed {
                $previous = (string) DB::selectOne("SELECT current_setting('statement_timeout') AS t")->t;
                DB::select(
                    "SELECT set_config('statement_timeout', ?, true), set_config('pg_trgm.similarity_threshold', ?, true), set_config('pg_trgm.word_similarity_threshold', ?, true)",
                    [$milliseconds.'ms', self::number('similarity'), self::number('word_similarity')],
                );

                $result = $callback();
                DB::select("SELECT set_config('statement_timeout', ?, true)", [$previous]);

                return $result;
            });
        } catch (QueryException $e) {
            // 57014 = query_canceled (statement_timeout).
            if ($e->getCode() === '57014') {
                return null;
            }

            throw $e;
        }
    }

    private static function number(string $key): string
    {
        return number_format((float) config("buffalo.search.{$key}"), 2, '.', '');
    }
}
