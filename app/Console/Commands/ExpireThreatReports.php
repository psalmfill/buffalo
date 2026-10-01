<?php

namespace App\Console\Commands;

use App\Actions\Threats\ExpireThreatReports as Expire;
use Illuminate\Console\Command;

/**
 * Scheduled every 15 minutes (Phase 6B): expires overdue threat reports and
 * sends the 24-hour expiry warnings. Runs in the console's system context.
 */
class ExpireThreatReports extends Command
{
    protected $signature = 'buffalo:expire-threat-reports';

    protected $description = 'Expire threat reports past their expiry and warn of those expiring soon';

    public function handle(Expire $expire): int
    {
        $result = $expire();
        $this->components->info("Threat reports expired: {$result['expired']}; expiry warnings sent: {$result['warned']}.");

        return self::SUCCESS;
    }
}
