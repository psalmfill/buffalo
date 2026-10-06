<?php

namespace App\Actions\Reports;

use App\Enums\SchedulePauseReason;
use App\Models\ReportSchedule;
use App\Models\User;

/**
 * Pauses every active report schedule of a user (Phase 8B.5): after a
 * posting change (role or jurisdiction), so nothing is generated for a
 * place or role they no longer hold until they review it, and on
 * deactivation. The owner resumes each with one click.
 *
 * @return int the number of schedules paused
 */
final class PauseReportSchedules
{
    public function __invoke(User $user, SchedulePauseReason $reason): int
    {
        return ReportSchedule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'paused_reason' => $reason->value, 'paused_at' => now(), 'updated_at' => now()]);
    }
}
