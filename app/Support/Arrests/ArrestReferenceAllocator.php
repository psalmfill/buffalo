<?php

namespace App\Support\Arrests;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Arrest references (Phase 11C): ARR/<state code>/<year>/<5-digit
 * sequence>, e.g. ARR/LA/2026/00042, assigned when the arrest is recorded.
 *
 * Gap-free per state and year (Africa/Lagos), as for threat reports: one
 * atomic upsert on the counter row, which row-locks it until the creating
 * transaction ends; a rolled-back creation rolls the counter back too.
 */
final class ArrestReferenceAllocator
{
    /** Shared with the concurrency test, which runs it on raw connections. */
    public const NEXT_SQL = <<<'SQL'
        INSERT INTO arrest_counters (state_id, year, last_value) VALUES (?, ?, 1)
        ON CONFLICT (state_id, year) DO UPDATE SET last_value = arrest_counters.last_value + 1
        RETURNING last_value
        SQL;

    public function allocate(int $stateId, CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Arrest references are allocated inside the creating transaction.');
        }

        $year = (int) $at->setTimezone((string) config('app.timezone'))->format('Y');
        $sequence = (int) DB::selectOne(self::NEXT_SQL, [$stateId, $year])->last_value;
        $code = DB::table('states')->where('id', $stateId)->value('code');

        if (! is_string($code)) {
            throw new LogicException('The arrest state does not exist.');
        }

        return sprintf('ARR/%s/%d/%05d', strtoupper($code), $year, $sequence);
    }
}
