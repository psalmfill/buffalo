<?php

namespace App\Console\Commands;

use App\Actions\Taskings\SendTaskingOverdueAlerts;
use Illuminate\Console\Command;

/**
 * Overdue taskings (Phase 11H): tells each overdue LGA's chief once at the
 * due time, and each sender once a day while any LGA is overdue.
 * Scheduled every five minutes; runs in the system context.
 */
class SendTaskingOverdueAlertsCommand extends Command
{
    protected $signature = 'buffalo:taskings:overdue';

    protected $description = 'Notify LGA chiefs and senders of overdue taskings';

    public function handle(SendTaskingOverdueAlerts $alerts): int
    {
        [$chiefs, $summaries] = $alerts();

        $this->components->info("{$chiefs} overdue notices to LGA chiefs, {$summaries} daily summaries to senders.");

        return self::SUCCESS;
    }
}
