<?php

namespace App\Actions\Security;

use App\Enums\CoverageReportStatus;
use App\Models\CoverageReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Discards a draft coverage report (Phase 4A): a soft delete. Submitted
 * reports are never deleted.
 */
final class DiscardCoverageReport
{
    public function __invoke(CoverageReport $report, User $actor): void
    {
        if ($report->status !== CoverageReportStatus::Draft) {
            throw new InvalidArgumentException('Only draft coverage reports can be discarded.');
        }

        DB::transaction(function () use ($report, $actor): void {
            AuditLog::event('coverage_report_discarded', 'Draft coverage report discarded', $report, [], $actor);
            $report->forceFill(['updated_by' => $actor->id])->save();
            $report->delete();
        });
    }
}
