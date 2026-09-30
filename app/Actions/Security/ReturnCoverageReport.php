<?php

namespace App\Actions\Security;

use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Notifications\CoverageReportReturned;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Returns a submitted coverage report for changes, with a reason (Phase
 * 4A). It becomes editable again; the submitter is notified.
 */
final class ReturnCoverageReport
{
    public function __invoke(CoverageReport $report, User $actor, string $reason): void
    {
        $reason = trim($reason);

        if ($report->status !== CoverageReportStatus::Submitted) {
            throw new InvalidArgumentException('Only submitted coverage reports can be returned.');
        }

        if ($reason === '') {
            throw new InvalidArgumentException('A reason is required to return a report.');
        }

        DB::transaction(function () use ($report, $actor, $reason): void {
            $report->forceFill([
                'status' => CoverageReportStatus::Returned,
                'returned_by' => $actor->id,
                'returned_at' => now(),
                'return_reason' => $reason,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event('coverage_report_returned', 'Coverage report returned for changes', $report, ['reason' => $reason], $actor);
        });

        $report->loadMissing(['lga', 'submitter']);
        $report->submitter?->notify(new CoverageReportReturned($report->ulid, $report->lga->name, $report->periodLabel()));
    }
}
