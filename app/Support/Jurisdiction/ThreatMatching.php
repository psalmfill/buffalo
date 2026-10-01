<?php

namespace App\Support\Jurisdiction;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Finds active threat reports that a newly submitted incident may be the
 * realisation of (Phase 6B), whatever the submitter may see.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). The match is
 * recorded (ids only) in `threat_report_incident_matches`; pages show it
 * only to viewers who can see the threat report, through its scopes.
 *
 * A threat matches when it is active, of the same category as the
 * incident's type, its area covers the incident, and the incident occurred
 * in its time frame:
 * - area: the point and radius when it has a point (the incident needs a
 *   location); else its community; else its LGA;
 * - time: inside the expected window, or at any time from when the report
 *   was recorded until its expiry: from the earlier of the expected start
 *   and the recording, until the later of the expected end and the expiry.
 *   Incidents before both (old news) never match.
 */
final class ThreatMatching
{
    /**
     * Records the matches for an incident and returns the threat report ids.
     *
     * @return list<int>
     */
    public function recordFor(int $incidentId): array
    {
        $ids = array_values(array_map('intval', DB::table('threat_reports AS t')
            ->join('threat_types AS tt', 'tt.id', '=', 't.threat_type_id')
            ->join('incidents AS i', fn ($join) => $join->where('i.id', '=', $incidentId))
            ->join('incident_types AS it', 'it.id', '=', 'i.incident_type_id')
            ->whereNull('t.deleted_at')
            ->where('t.status', 'active')
            ->whereColumn('tt.category', 'it.category')
            ->whereRaw(<<<'SQL'
                CASE
                    WHEN t.location IS NOT NULL THEN i.location IS NOT NULL AND ST_DWithin(t.location, i.location, COALESCE(t.radius_meters, 1000))
                    WHEN t.community_id IS NOT NULL THEN i.community_id IS NOT DISTINCT FROM t.community_id
                    ELSE i.lga_id = t.lga_id
                END
                SQL)
            ->whereRaw('i.occurred_at >= LEAST(COALESCE(t.expected_from, t.created_at), t.created_at)')
            ->whereRaw('i.occurred_at <= GREATEST(t.expires_at, COALESCE(t.expected_until, t.expires_at))')
            ->orderBy('t.id')
            ->pluck('t.id')
            ->all()));

        $now = CarbonImmutable::now();
        DB::table('threat_report_incident_matches')->insertOrIgnore(array_map(fn (int $id) => [
            'threat_report_id' => $id,
            'incident_id' => $incidentId,
            'matched_at' => $now,
        ], $ids));

        return $ids;
    }
}
