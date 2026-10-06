<?php

namespace App\Console\Commands;

use App\Support\Health\Checks;
use Illuminate\Console\Command;

/**
 * The scheduler container's health check (Phase 8C.5): succeeds when a
 * scheduled command ran in the last 3 minutes (buffalo:health:monitor runs
 * every minute).
 */
class SchedulerAliveCommand extends Command
{
    protected $signature = 'buffalo:health:scheduler-alive';

    protected $description = 'Exit 0 when the scheduler has run a command in the last 3 minutes';

    public function handle(): int
    {
        $last = Checks::lastScheduledRun();

        return $last !== null && $last->gte(now()->subMinutes(3)) ? self::SUCCESS : self::FAILURE;
    }
}
