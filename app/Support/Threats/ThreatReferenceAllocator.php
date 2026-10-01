<?php

namespace App\Support\Threats;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Threat report references (Phase 6B): THR/<state code>/<year>/<5-digit
 * sequence>, e.g. THR/LA/2026/00042, assigned when the report is created.
 *
 * Gap-free per state and year (Africa/Lagos), as for incidents: one atomic
 * upsert on the counter row, which row-locks it until the creating
 * transaction ends; a rolled-back creation rolls the counter back too.
 */
final class ThreatReferenceAllocator
{
    /** Shared with the concurrency test, which runs it on raw connections. */
    public const NEXT_SQL = <<<'SQL'
        INSERT INTO threat_report_counters (state_id, year, last_value) VALUES (?, ?, 1)
        ON CONFLICT (state_id, year) DO UPDATE SET last_value = threat_report_counters.last_value + 1
        RETURNING last_value
        SQL;

    public function allocate(int $stateId, CarbonImmutable $at): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Threat references are allocated inside the creating transaction.');
        }

        $year = (int) $at->setTimezone((string) config('app.timezone'))->format('Y');
        $sequence = (int) DB::selectOne(self::NEXT_SQL, [$stateId, $year])->last_value;
        $code = DB::table('states')->where('id', $stateId)->value('code');

        if (! is_string($code)) {
            throw new LogicException('The threat report state does not exist.');
        }

        return sprintf('THR/%s/%d/%05d', strtoupper($code), $year, $sequence);
    }
}
