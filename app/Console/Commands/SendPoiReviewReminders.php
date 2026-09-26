<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\PersonOfInterest;
use App\Models\State;
use App\Notifications\PoiReviewsOverdue;
use App\Support\Notifications\NotifyRole;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily (Phase 3B): notifies the owning LGA chief (per LGA) and
 * State Director (per state) of persons of interest past review_due_at.
 * Each overdue due date is reminded once (review_reminded_for), so the
 * same record does not trigger a notice every day. Runs in the console's
 * system context.
 */
class SendPoiReviewReminders extends Command
{
    protected $signature = 'buffalo:poi-review-reminders';

    protected $description = 'Remind LGA chiefs and State Directors of person of interest reviews that are overdue';

    public function handle(NotifyRole $notify): int
    {
        $due = PersonOfInterest::query()
            ->where('review_due_at', '<', CarbonImmutable::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('review_reminded_for')->orWhereColumn('review_reminded_for', '<>', 'review_due_at'))
            ->get(['id', 'lga_id', 'state_id', 'review_due_at']);

        $sent = 0;

        foreach ($due->groupBy('lga_id') as $lgaId => $records) {
            $lga = Lga::query()->find($lgaId);

            if ($lga !== null) {
                $sent += $notify->send(new PoiReviewsOverdue($lga, $records->count()), Role::LgaSecurityChief, $lga);
            }
        }

        foreach ($due->groupBy('state_id') as $stateId => $records) {
            $state = State::query()->find($stateId);

            if ($state !== null) {
                $sent += $notify->send(new PoiReviewsOverdue($state, $records->count()), Role::StateDirector, $state);
            }
        }

        // Bookkeeping only: not an audited change of the record.
        foreach ($due as $poi) {
            PersonOfInterest::query()->whereKey($poi->id)->update(['review_reminded_for' => $poi->review_due_at->toDateString()]);
        }

        $this->components->info("POI review reminders sent: {$sent} (records: {$due->count()}).");

        return self::SUCCESS;
    }
}
