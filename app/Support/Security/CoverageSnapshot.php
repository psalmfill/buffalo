<?php

namespace App\Support\Security;

use App\Models\CoverageReport;
use Illuminate\Support\Facades\DB;

/**
 * A complete copy of a coverage report as submitted or reviewed (Phase 4A),
 * stored in the activity log with the transition so every submitted and
 * reviewed version can be reconstructed. ULIDs and values only; no ids.
 *
 * Read with the query builder (ids resolved to ULIDs) so it is complete
 * whatever the actor's view of individual formations.
 */
final class CoverageSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(CoverageReport $report): array
    {
        $deployments = DB::table('coverage_report_deployments as d')
            ->join('security_agencies as a', 'a.id', '=', 'd.security_agency_id')
            ->leftJoin('agency_formations as f', 'f.id', '=', 'd.agency_formation_id')
            ->where('d.coverage_report_id', $report->id)
            ->whereNull('d.deleted_at')
            ->orderBy('d.id')
            ->get(['d.id', 'd.ulid', 'a.ulid as agency', 'f.ulid as formation', 'd.personnel_count', 'd.vehicles_count', 'd.patrol_frequency', 'd.areas_covered', 'd.remarks']);

        $communities = DB::table('coverage_deployment_communities as c')
            ->join('communities as m', 'm.id', '=', 'c.community_id')
            ->whereIn('c.coverage_report_deployment_id', $deployments->pluck('id'))
            ->orderBy('m.ulid')
            ->get(['c.coverage_report_deployment_id', 'm.ulid'])
            ->groupBy('coverage_report_deployment_id');

        return [
            'period_type' => $report->period_type->value,
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'overall_rating' => $report->overall_rating?->value,
            'summary' => $report->summary,
            'gaps_identified' => $report->gaps_identified,
            'recommendations' => $report->recommendations,
            'classification' => $report->classificationLevel()->value,
            'deployments' => $deployments->map(fn (object $d) => [
                'ulid' => $d->ulid,
                'agency' => $d->agency,
                'formation' => $d->formation,
                'personnel_count' => (int) $d->personnel_count,
                'vehicles_count' => $d->vehicles_count === null ? null : (int) $d->vehicles_count,
                'patrol_frequency' => $d->patrol_frequency,
                'areas_covered' => $d->areas_covered,
                'remarks' => $d->remarks,
                'communities' => ($communities->get($d->id) ?? collect())->pluck('ulid')->values()->all(),
            ])->values()->all(),
        ];
    }
}
