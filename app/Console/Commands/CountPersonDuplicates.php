<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Support\Persons\Merge\DuplicateCounts;
use App\Support\Persons\Merge\PersonDuplicateFinder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Nightly (Phase 10A.3): counts duplicate candidate pairs per state, for
 * the State Director's dashboard. Counts only: no pairs or names are
 * stored. Runs in the system context, one state at a time, so every pair
 * is within one state.
 */
class CountPersonDuplicates extends Command
{
    protected $signature = 'buffalo:persons:count-duplicates';

    protected $description = 'Count duplicate person candidates per state (dashboard figures)';

    public function handle(PersonDuplicateFinder $finder): int
    {
        $counts = [];

        foreach (DB::table('persons')->whereNull('deleted_at')->distinct()->orderBy('state_id')->pluck('state_id') as $stateId) {
            $counts[(int) $stateId] = $finder->counts(Person::query()->where('persons.state_id', $stateId));
        }

        DuplicateCounts::store($counts);
        $this->components->info(count($counts).' state(s) counted.');

        return self::SUCCESS;
    }
}
