<?php

namespace App\Console\Commands;

use App\Actions\Disseminations\ProcessDisseminations as Process;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes (Phase 7A): ends expired disseminations
 * (notice, audit event, open pages closed) and reminds recipients once of
 * unacknowledged ones. Runs in the console's system context.
 */
class ProcessDisseminations extends Command
{
    protected $signature = 'buffalo:process-disseminations';

    protected $description = 'End expired disseminations and remind recipients of unacknowledged ones';

    public function handle(Process $process): int
    {
        $result = $process();
        $this->components->info("Disseminations expired: {$result['expired']}; reminders sent: {$result['reminded']}.");

        return self::SUCCESS;
    }
}
