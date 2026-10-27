<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\State;
use App\Models\StateProfile;
use App\Notifications\LgaProfileReviewDue;
use App\Notifications\StateProfileReviewDue;
use App\Support\Notifications\NotifyRole;
use Illuminate\Console\Command;

/**
 * Scheduled weekly: reminds each LGA chief whose LGA profile has not been
 * reviewed in BUFFALO_PROFILE_REVIEW_DAYS days (or never), and the State
 * Director and Deputies of each state whose state profile has not been
 * (Phase 11F). Runs in the console's system context.
 */
class SendProfileReviewReminders extends Command
{
    protected $signature = 'buffalo:profile-review-reminders';

    protected $description = 'Remind LGA chiefs and State Directors whose LGA or state profile is due for review';

    public function handle(NotifyRole $notify): int
    {
        $days = (int) config('buffalo.profile_review_days', 180);
        $cutoff = now()->subDays($days);

        $recentlyReviewed = LgaProfile::query()
            ->where('last_reviewed_at', '>=', $cutoff)
            ->select('lga_id');

        $sent = 0;

        Lga::query()->active()->whereNotIn('id', $recentlyReviewed)->orderBy('id')
            ->chunkById(200, function ($lgas) use ($notify, $days, &$sent): void {
                foreach ($lgas as $lga) {
                    $sent += $notify->send(new LgaProfileReviewDue($lga, $days), Role::LgaSecurityChief, $lga);
                }
            });

        $recentlyReviewedStates = StateProfile::query()
            ->where('last_reviewed_at', '>=', $cutoff)
            ->select('state_id');

        foreach (State::query()->active()->whereNotIn('id', $recentlyReviewedStates)->orderBy('id')->get() as $state) {
            foreach ([Role::StateDirector, Role::DeputyStateDirector] as $role) {
                $sent += $notify->send(new StateProfileReviewDue($state, $days), $role, $state);
            }
        }

        $this->components->info("Profile review reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
