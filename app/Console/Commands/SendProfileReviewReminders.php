<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Notifications\LgaProfileReviewDue;
use App\Support\Notifications\NotifyRole;
use Illuminate\Console\Command;

/**
 * Scheduled weekly: reminds each LGA chief whose LGA profile has not been
 * reviewed in BUFFALO_PROFILE_REVIEW_DAYS days (or never). Runs in the
 * console's system context.
 */
class SendProfileReviewReminders extends Command
{
    protected $signature = 'buffalo:profile-review-reminders';

    protected $description = 'Remind LGA chiefs whose LGA profile is due for review';

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

        $this->components->info("Profile review reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
