<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Daily (Phase 10A.2): erases person_merges.survivor_prior_values (the
 * encrypted copy of a survivor's fields before a merge) once the undo
 * window has passed or the merge was undone, so the copy of personal data
 * does not outlive its purpose. The column is left out of the audit hash
 * chain, so erasing it does not break the chain.
 */
class ErasePersonMergePriorValues extends Command
{
    protected $signature = 'buffalo:merges:erase-prior-values';

    protected $description = 'Erase the saved prior values of person merges past their undo window';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('buffalo.merges.undo_days', 30));

        $erased = DB::table('person_merges')
            ->whereNotNull('survivor_prior_values')
            ->where(fn ($q) => $q->whereNotNull('undone_at')->orWhere('executed_at', '<', $cutoff))
            ->update(['survivor_prior_values' => null]);

        if ($erased > 0) {
            AuditLog::event('person_merge_values_erased', "Saved prior values of {$erased} person merge(s) erased after the undo window", properties: ['merges' => $erased]);
        }

        $this->components->info("{$erased} merge(s) erased.");

        return self::SUCCESS;
    }
}
