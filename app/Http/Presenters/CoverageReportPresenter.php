<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\CoveragePeriodType;
use App\Enums\CoverageRating;
use App\Enums\CoverageReportStatus;
use App\Enums\PatrolFrequency;
use App\Models\Community;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Support\Jurisdiction\OfficerNames;

/**
 * Coverage reports for the browser (Phase 4A): ULIDs and labels, never ids.
 */
final class CoverageReportPresenter
{
    /**
     * @return array{value: string, label: string}
     */
    public static function option(CoveragePeriodType|CoverageRating|CoverageReportStatus|PatrolFrequency|Classification $value): array
    {
        return ['value' => $value->value, 'label' => $value->label()];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(CoverageReport $report): array
    {
        return [
            'ulid' => $report->ulid,
            'lga' => ['ulid' => $report->lga->ulid, 'name' => $report->lga->name],
            'state' => ['ulid' => $report->state->ulid, 'name' => $report->state->name],
            'period_type' => self::option($report->period_type),
            'period_start' => $report->period_start->toDateString(),
            'period_end' => $report->period_end->toDateString(),
            'period' => $report->periodLabel(),
            'overall_rating' => $report->overall_rating === null ? null : self::option($report->overall_rating),
            'status' => self::option($report->status),
            'classification' => ['value' => $report->classificationLevel()->value, 'label' => $report->classificationLevel()->label()],
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(CoverageReport $report): array
    {
        // Officers at any level of the hierarchy (names only).
        $names = OfficerNames::for([$report->created_by, $report->submitted_by, $report->reviewed_by, $report->returned_by]);
        $person = fn (?int $id) => $id === null || ! $names->has($id) ? null : ['name' => $names[$id]];

        return [
            ...self::row($report),
            'summary' => $report->summary,
            'gaps_identified' => $report->gaps_identified,
            'recommendations' => $report->recommendations,
            'created_by' => $person($report->created_by),
            'submitted_by' => $person($report->submitted_by),
            'reviewed_by' => $person($report->reviewed_by),
            'reviewed_at' => $report->reviewed_at?->toIso8601String(),
            'review_comment' => $report->review_comment,
            'returned_by' => $person($report->returned_by),
            'returned_at' => $report->returned_at?->toIso8601String(),
            'return_reason' => $report->return_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function deployment(CoverageReportDeployment $deployment): array
    {
        return [
            'ulid' => $deployment->ulid,
            'agency' => AgencyFormationPresenter::agency($deployment->agency),
            // Null when none is recorded, or the viewer cannot see it.
            'formation' => $deployment->formation === null ? null : ['ulid' => $deployment->formation->ulid, 'name' => $deployment->formation->name],
            'formation_hidden' => $deployment->agency_formation_id !== null && $deployment->formation === null,
            'personnel_count' => $deployment->personnel_count,
            'vehicles_count' => $deployment->vehicles_count,
            'patrol_frequency' => self::option($deployment->patrol_frequency),
            'areas_covered' => $deployment->areas_covered,
            'remarks' => $deployment->remarks,
            'communities' => $deployment->communities->sortBy('name')->map(fn (Community $c) => ['ulid' => $c->ulid, 'name' => $c->name])->values()->all(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'periodTypes' => array_map(self::option(...), CoveragePeriodType::cases()),
            'ratings' => array_map(self::option(...), CoverageRating::cases()),
            'statuses' => array_map(self::option(...), CoverageReportStatus::cases()),
            'patrolFrequencies' => array_map(self::option(...), PatrolFrequency::cases()),
            'classifications' => array_map(self::option(...), Classification::cases()),
        ];
    }
}
