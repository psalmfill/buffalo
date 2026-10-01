<?php

namespace App\Console\Commands;

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Models\Flashpoint;
use App\Models\User;
use App\Notifications\FlashpointNotification;
use App\Support\Notifications\NotifyRole;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Scheduled daily (Phase 6A): flashpoints (not resolved) whose review date
 * has come are reminded to the chief of their LGA and the SD and DSD of
 * their state, once per due date (review_reminded_for), and only to those
 * who can see the flashpoint. Runs in the console's system context.
 */
class SendFlashpointReviewReminders extends Command
{
    protected $signature = 'buffalo:flashpoint-review-reminders';

    protected $description = 'Remind LGA chiefs and state directors of flashpoint reviews that are due';

    public function handle(NotifyRole $notify): int
    {
        $due = Flashpoint::query()
            ->with(['lga', 'threatType'])
            ->where('status', '!=', 'resolved')
            ->where('review_due_at', '<=', CarbonImmutable::today()->toDateString())
            ->where(fn ($q) => $q->whereNull('review_reminded_for')->orWhereColumn('review_reminded_for', '<>', 'review_due_at'))
            ->get();

        $sent = 0;

        foreach ($due as $flashpoint) {
            $notification = FlashpointNotification::about('review_due', $flashpoint, level: NotificationSeverity::Info);
            $canSee = fn (User $user): bool => $user->can('view', $flashpoint);

            foreach ([Role::LgaSecurityChief, Role::StateDirector, Role::DeputyStateDirector] as $role) {
                $sent += $notify->send($notification, $role, $flashpoint->lga, $canSee);
            }

            // Bookkeeping only (not audited: see Flashpoint::auditExcept()).
            Flashpoint::query()->whereKey($flashpoint->id)->update(['review_reminded_for' => $flashpoint->review_due_at->toDateString()]);
        }

        $this->components->info("Flashpoint review reminders sent: {$sent} (flashpoints: {$due->count()}).");

        return self::SUCCESS;
    }
}
