<?php

namespace App\Actions\Security;

use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Models\Lga;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates a draft coverage report or saves changes to a draft or returned
 * one (Phase 4A), with its full list of deployments: existing rows are
 * updated, new rows created, and rows left out soft-deleted. Covered
 * communities are replaced per deployment. Each deployment change is in the
 * activity log; the complete version is snapshotted on submit and review.
 */
final class SaveCoverageReport
{
    /**
     * @param  array<string, mixed>  $data  CoverageReportRequest::values()
     */
    public function create(Lga $lga, array $data, User $actor): CoverageReport
    {
        return DB::transaction(function () use ($lga, $data, $actor): CoverageReport {
            $report = new CoverageReport;
            $report->forceFill([
                ...self::header($data),
                'period_type' => $data['period_type'],
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'status' => CoverageReportStatus::Draft,
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->syncDeployments($report, $data['deployments'], $actor);

            return $report;
        });
    }

    /**
     * @param  array<string, mixed>  $data  CoverageReportRequest::values()
     */
    public function update(CoverageReport $report, array $data, User $actor): void
    {
        if (! $report->status->isEditable()) {
            throw new LogicException('Only draft or returned coverage reports can be edited.');
        }

        DB::transaction(function () use ($report, $data, $actor): void {
            $report->forceFill(self::header($data));

            if ($report->isDirty()) {
                $report->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->syncDeployments($report, $data['deployments'], $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function header(array $data): array
    {
        return [
            'overall_rating' => $data['overall_rating'],
            'summary' => $data['summary'],
            'gaps_identified' => $data['gaps_identified'],
            'recommendations' => $data['recommendations'],
            'classification' => $data['classification'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncDeployments(CoverageReport $report, array $rows, User $actor): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $deployment = $row['id'] === null
                ? new CoverageReportDeployment
                : CoverageReportDeployment::query()->where('coverage_report_id', $report->id)->whereKey((int) $row['id'])->firstOrFail();

            $deployment->forceFill([
                'security_agency_id' => $row['security_agency_id'],
                'agency_formation_id' => $row['agency_formation_id'],
                'personnel_count' => $row['personnel_count'],
                'vehicles_count' => $row['vehicles_count'],
                'patrol_frequency' => $row['patrol_frequency'],
                'areas_covered' => $row['areas_covered'],
                'remarks' => $row['remarks'],
            ]);

            if (! $deployment->exists) {
                $deployment->forceFill([
                    'coverage_report_id' => $report->id,
                    'state_id' => $report->state_id,
                    'lga_id' => $report->lga_id,
                    'created_by' => $actor->id,
                ]);
            }

            if ($deployment->isDirty()) {
                $deployment->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->syncCommunities($deployment, $row['community_ids']);
            $keep[] = $deployment->id;
        }

        CoverageReportDeployment::query()
            ->where('coverage_report_id', $report->id)
            ->whereNotIn('id', $keep)
            ->get()
            ->each(fn (CoverageReportDeployment $removed) => $removed->forceFill(['updated_by' => $actor->id])->delete());
    }

    /**
     * @param  list<int>  $communityIds
     */
    private function syncCommunities(CoverageReportDeployment $deployment, array $communityIds): void
    {
        $current = array_map('intval', DB::table('coverage_deployment_communities')
            ->where('coverage_report_deployment_id', $deployment->id)->pluck('community_id')->all());

        $removed = array_values(array_diff($current, $communityIds));
        $added = array_values(array_diff($communityIds, $current));

        if ($removed !== []) {
            DB::table('coverage_deployment_communities')
                ->where('coverage_report_deployment_id', $deployment->id)
                ->whereIn('community_id', $removed)
                ->delete();
        }

        if ($added !== []) {
            DB::table('coverage_deployment_communities')->insert(array_map(fn (int $id) => [
                'coverage_report_deployment_id' => $deployment->id,
                'community_id' => $id,
                'lga_id' => $deployment->lga_id,
            ], $added));
        }
    }
}
