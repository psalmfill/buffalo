<?php

namespace App\Support\Security;

use App\Enums\CoverageReportStatus;
use App\Models\Community;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures computed on a coverage report (Phase 4A), never stored: totals by
 * agency, the LGA's communities no deployment covers, and a comparison with
 * the previous report of the same period type. Everything goes through the
 * viewer's scopes.
 */
final class CoverageAnalysis
{
    /**
     * @return array{personnel: int, vehicles: int, by_agency: list<array{agency: array{ulid: string, name: string, short_name: string}, deployments: int, personnel: int, vehicles: int}>}
     */
    public function totals(CoverageReport $report): array
    {
        /** @var Collection<int, CoverageReportDeployment> $deployments */
        $deployments = CoverageReportDeployment::query()->with('agency')
            ->where('coverage_report_id', $report->id)
            ->get();

        $byAgency = $deployments->groupBy('security_agency_id')->map(function (Collection $rows): array {
            /** @var CoverageReportDeployment $first */
            $first = $rows->first();

            return [
                'agency' => ['ulid' => $first->agency->ulid, 'name' => $first->agency->name, 'short_name' => $first->agency->short_name],
                'deployments' => $rows->count(),
                'personnel' => (int) $rows->sum('personnel_count'),
                'vehicles' => (int) $rows->sum(fn (CoverageReportDeployment $d) => $d->vehicles_count ?? 0),
            ];
        })->sortByDesc('personnel')->values()->all();

        return [
            'personnel' => (int) $deployments->sum('personnel_count'),
            'vehicles' => (int) $deployments->sum(fn (CoverageReportDeployment $d) => $d->vehicles_count ?? 0),
            'by_agency' => array_values($byAgency),
        ];
    }

    /**
     * Communities of the report's LGA not covered by any of its deployments.
     *
     * @return Collection<int, Community>
     */
    public function uncovered(CoverageReport $report): Collection
    {
        return Community::query()->withCoordinates()
            ->where('communities.lga_id', $report->lga_id)
            ->whereNotIn('communities.id', $this->coveredIds($report))
            ->orderBy('communities.name')
            ->get();
    }

    /**
     * Ids of the communities the report's deployments cover.
     *
     * @return list<int>
     */
    public function coveredIds(CoverageReport $report): array
    {
        return array_values(array_map('intval', DB::table('coverage_deployment_communities as c')
            ->join('coverage_report_deployments as d', 'd.id', '=', 'c.coverage_report_deployment_id')
            ->where('d.coverage_report_id', $report->id)
            ->whereNull('d.deleted_at')
            ->distinct()
            ->pluck('c.community_id')
            ->all()));
    }

    /**
     * The latest earlier submitted or reviewed report of the same LGA and
     * period type that the viewer can see.
     */
    public function previous(CoverageReport $report): ?CoverageReport
    {
        return CoverageReport::query()
            ->where('lga_id', $report->lga_id)
            ->where('period_type', $report->period_type->value)
            ->where('period_start', '<', $report->period_start->toDateString())
            ->whereIn('status', [CoverageReportStatus::Submitted->value, CoverageReportStatus::Reviewed->value])
            ->orderByDesc('period_start')
            ->first();
    }

    /**
     * @return array{report: array{ulid: string, period: string}, personnel_change: int, rating: array{previous: string|null, current: string|null, change: string}, newly_uncovered: list<array{ulid: string, name: string}>}|null
     */
    public function comparison(CoverageReport $report): ?array
    {
        $previous = $this->previous($report);

        if ($previous === null) {
            return null;
        }

        $now = $this->uncovered($report)->keyBy('id');
        $before = $this->uncovered($previous)->keyBy('id');
        $newlyUncovered = $now->diffKeys($before)->values();

        $current = $report->overall_rating;
        $earlier = $previous->overall_rating;
        $change = match (true) {
            $current === null || $earlier === null => 'unknown',
            $current->rank() < $earlier->rank() => 'improved',
            $current->rank() > $earlier->rank() => 'worsened',
            default => 'unchanged',
        };

        return [
            'report' => ['ulid' => $previous->ulid, 'period' => $previous->periodLabel()],
            'personnel_change' => $this->totals($report)['personnel'] - $this->totals($previous)['personnel'],
            'rating' => ['previous' => $earlier?->label(), 'current' => $current?->label(), 'change' => $change],
            'newly_uncovered' => array_values($newlyUncovered->map(fn (Community $c) => ['ulid' => $c->ulid, 'name' => $c->name])->all()),
        ];
    }
}
