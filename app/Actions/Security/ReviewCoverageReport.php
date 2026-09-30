<?php

namespace App\Actions\Security;

use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Notifications\CoverageReportReviewed;
use App\Support\Audit\AuditLog;
use App\Support\Security\CoverageSnapshot;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Marks a submitted coverage report reviewed (Phase 4A), with an optional
 * comment. The report is then locked for everyone (policies and database
 * triggers). The reviewed version is snapshotted; the submitter is told.
 */
final class ReviewCoverageReport
{
    public function __invoke(CoverageReport $report, User $actor, ?string $comment): void
    {
        if ($report->status !== CoverageReportStatus::Submitted) {
            throw new InvalidArgumentException('Only submitted coverage reports can be marked reviewed.');
        }

        $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

        DB::transaction(function () use ($report, $actor, $comment): void {
            // Snapshot first: after this update the report is locked.
            AuditLog::event('coverage_report_reviewed', 'Coverage report marked reviewed', $report, [
                'comment' => $comment,
                'version' => CoverageSnapshot::of($report),
            ], $actor);

            $report->forceFill([
                'status' => CoverageReportStatus::Reviewed,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
                'updated_by' => $actor->id,
            ])->save();
        });

        $report->loadMissing(['lga', 'submitter']);
        $report->submitter?->notify(new CoverageReportReviewed($report->ulid, $report->lga->name, $report->periodLabel()));
    }
}
