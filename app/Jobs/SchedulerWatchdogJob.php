<?php

namespace App\Jobs;

use App\Support\Health\SchedulerCheck;
use App\Support\Health\SystemAlerts;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Watches the scheduler from the queue (Phase 8C.4). buffalo:health:monitor
 * dispatches it (one at a time); it checks the scheduler and queues itself
 * again, so if the scheduler dies the alert is still raised as long as the
 * queue workers run. Each process watches the other: Horizon being down is
 * one of the monitor's service checks.
 */
final class SchedulerWatchdogJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function handle(SchedulerCheck $check, SystemAlerts $alerts): void
    {
        $alerts->sync($check->prefix(), $check->conditions());

        // Only a real queue can watch the scheduler; the sync driver (tests,
        // console without workers) would run the next one at once, forever.
        if ($this->job !== null && $this->job->getConnectionName() !== 'sync') {
            self::dispatch()->delay(now()->addMinutes(5));
        }
    }
}
