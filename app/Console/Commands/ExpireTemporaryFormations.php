<?php

namespace App\Console\Commands;

use App\Enums\FormationStatus;
use App\Models\AgencyFormation;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily (Phase 4A): temporary formations past their active_to date become
 * inactive. Runs in the console's system context; each change is logged as
 * a named event.
 */
class ExpireTemporaryFormations extends Command
{
    protected $signature = 'buffalo:expire-temporary-formations';

    protected $description = 'Set temporary formations past their end date to inactive';

    public function handle(): int
    {
        $today = CarbonImmutable::today();
        $count = 0;

        AgencyFormation::query()
            ->where('is_temporary', true)
            ->where('status', FormationStatus::Active->value)
            ->whereNotNull('active_to')
            ->where('active_to', '<', $today->toDateString())
            ->orderBy('id')
            ->each(function (AgencyFormation $formation) use (&$count): void {
                $formation->forceFill(['status' => FormationStatus::Inactive])->save();
                AuditLog::event('formation_expired', "Temporary formation {$formation->name} ended", $formation, [
                    'active_to' => $formation->active_to?->toDateString(),
                ]);
                $count++;
            });

        $this->components->info("Temporary formations set inactive: {$count}.");

        return self::SUCCESS;
    }
}
