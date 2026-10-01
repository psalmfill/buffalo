<?php

namespace App\Console\Commands;

use App\Actions\Disseminations\CheckDisseminationGaps as Check;
use Illuminate\Console\Command;

/**
 * Scheduled daily (Phase 7A): tells the SD and DSD, once per record, about
 * urgent threat reports and flashpoints whose areas cross into places they
 * have not been disseminated to. Runs in the console's system context.
 */
class CheckDisseminationGaps extends Command
{
    protected $signature = 'buffalo:check-dissemination-gaps';

    protected $description = 'Report urgent cross-border threats and flashpoints that have not been disseminated';

    public function handle(Check $check): int
    {
        $this->components->info('Records reported: '.$check().'.');

        return self::SUCCESS;
    }
}
