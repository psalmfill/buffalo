<?php

namespace App\Support\Incidents;

use App\Models\Incident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Incident reference numbers (Phase 5C): BUF/<state code>/<LGA code>/<year>/
 * <6-digit sequence>, e.g. BUF/LA/IKJ/2026/000123.
 *
 * The sequence is per state and per year of submission (Africa/Lagos),
 * gap-free: one atomic upsert on the counter row, which row-locks it until
 * the submission transaction ends. A rolled-back submission rolls the
 * counter back with it, so no number is skipped. Call only inside the
 * submission transaction.
 */
final class IncidentReferenceAllocator
{
    /** Shared with the concurrency test, which runs it on raw connections. */
    public const NEXT_SQL = <<<'SQL'
        INSERT INTO incident_reference_counters (state_id, year, last_value) VALUES (?, ?, 1)
        ON CONFLICT (state_id, year) DO UPDATE SET last_value = incident_reference_counters.last_value + 1
        RETURNING last_value
        SQL;

    public function allocate(Incident $incident, CarbonImmutable $submittedAt): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reference numbers are allocated inside the submission transaction.');
        }

        $year = (int) $submittedAt->setTimezone((string) config('app.timezone'))->format('Y');
        $row = DB::selectOne(self::NEXT_SQL, [$incident->state_id, $year]);
        $sequence = (int) $row->last_value;

        $codes = DB::table('lgas')->join('states', 'states.id', '=', 'lgas.state_id')
            ->where('lgas.id', $incident->lga_id)
            ->first(['states.code as state', 'lgas.code as lga']);

        if ($codes === null) {
            throw new LogicException('The incident LGA does not exist.');
        }

        return sprintf('BUF/%s/%s/%d/%06d', strtoupper((string) $codes->state), strtoupper((string) $codes->lga), $year, $sequence);
    }
}
