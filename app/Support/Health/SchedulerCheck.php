<?php

namespace App\Support\Health;

use Carbon\CarbonImmutable;

/**
 * The scheduler is alive (Phase 8C.4): some scheduled command ran within
 * buffalo.health.scheduler_minutes (10). Run by buffalo:health:monitor and
 * by SchedulerWatchdogJob on the queue, so a dead scheduler (which also
 * stops the monitor) is still reported while Horizon runs.
 */
final class SchedulerCheck implements HealthCheck
{
    public function prefix(): string
    {
        return 'scheduler.';
    }

    public function conditions(): array
    {
        $last = Checks::lastScheduledRun();
        $limit = (int) config('buffalo.health.scheduler_minutes');

        // Nothing recorded yet (a new installation's first minute): not an
        // alert; the first scheduled run records itself.
        if ($last === null || $last->gte(CarbonImmutable::now()->subMinutes($limit))) {
            return [];
        }

        return [Condition::critical('scheduler.stale', 'Scheduler not running', 'No scheduled command has run since '.$last->setTimezone((string) config('app.timezone'))->format('j M Y H:i').'. Backup monitoring, reminders and report schedules are stopped.')];
    }
}
