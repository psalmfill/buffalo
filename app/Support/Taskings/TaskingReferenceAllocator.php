<?php

namespace App\Support\Taskings;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Tasking references (Phase 11H): TSK/<state code>/<year>/<5-digit
 * sequence>, e.g. TSK/AK/2026/00042, assigned when the tasking is sent.
 *
 * Gap-free per state and year (Africa/Lagos), as for threat reports: one
 * atomic upsert on the counter row, which row-locks it until the sending
 * transaction ends; a rolled-back send rolls the counter back too.
 */
final class TaskingReferenceAllocator
{
    /** Shared with the concurrency test, which runs it on raw connections. */
    public const NEXT_SQL = <<<'SQL'
        INSERT INTO tasking_counters (state_id, year, last_value) VALUES (?, ?, 1)
        ON CONFLICT (state_id, year) DO UPDATE SET last_value = tasking_counters.last_value + 1
        RETURNING last_value
        SQL;

    public function allocate(int $stateId, CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Tasking references are allocated inside the sending transaction.');
        }

        $year = (int) $at->setTimezone((string) config('app.timezone'))->format('Y');
        $sequence = (int) DB::selectOne(self::NEXT_SQL, [$stateId, $year])->last_value;
        $code = DB::table('states')->where('id', $stateId)->value('code');

        if (! is_string($code)) {
            throw new LogicException('The tasking state does not exist.');
        }

        return sprintf('TSK/%s/%d/%05d', strtoupper($code), $year, $sequence);
    }
}
