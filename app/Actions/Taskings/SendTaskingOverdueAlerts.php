<?php

namespace App\Actions\Taskings;

use App\Enums\TaskingRecipientStatus;
use App\Enums\TaskingStatus;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Support\Taskings\TaskingNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Overdue taskings (Phase 11H): a recipient row not completed by the
 * tasking's response date is overdue.
 *
 * - Each overdue LGA's chief is told once, at the due time
 *   (`overdue_notified_at`).
 * - The sender gets one summary a day while any LGA is overdue, with the
 *   count only: the first when the tasking falls due, then once a day from
 *   `buffalo.taskings.overdue_summary_hour` (`overdue_summary_sent_on`).
 *
 * Recalled taskings are never overdue. Runs in the system context (the
 * scheduled command); returns [chiefs told, summaries sent].
 */
final class SendTaskingOverdueAlerts
{
    public function __construct(private readonly TaskingNotifier $notifier) {}

    /**
     * @return array{int, int}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone((string) config('app.timezone'));
        $chiefs = 0;
        $summaries = 0;

        Tasking::query()
            ->where('status', TaskingStatus::Sent->value)
            ->whereNotNull('response_due_at')
            ->where('response_due_at', '<=', $now)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('tasking_recipients')
                ->whereColumn('tasking_recipients.tasking_id', 'taskings.id')
                ->where('tasking_recipients.status', '<>', TaskingRecipientStatus::Completed->value))
            ->orderBy('id')
            ->each(function (Tasking $tasking) use ($now, $today, &$chiefs, &$summaries): void {
                $overdue = TaskingRecipient::query()
                    ->where('tasking_id', $tasking->id)
                    ->where('status', '<>', TaskingRecipientStatus::Completed->value)
                    ->get();

                foreach ($overdue->whereNull('overdue_notified_at') as $recipient) {
                    // Claim the row first, so two runs never both notify.
                    $claimed = DB::table('tasking_recipients')->where('id', $recipient->id)->whereNull('overdue_notified_at')
                        ->update(['overdue_notified_at' => $now]) === 1;

                    if ($claimed) {
                        $this->notifier->overdueToChief($tasking, $recipient);
                        $chiefs++;
                    }
                }

                $last = $tasking->overdue_summary_sent_on;
                $due = $last === null
                    || ($last->toDateString() < $today->toDateString() && (int) $today->format('G') >= (int) config('buffalo.taskings.overdue_summary_hour'));

                if ($due && $overdue->isNotEmpty()) {
                    $claimed = DB::table('taskings')->where('id', $tasking->id)
                        ->where(fn ($q) => $q->whereNull('overdue_summary_sent_on')->orWhere('overdue_summary_sent_on', '<', $today->toDateString()))
                        ->update(['overdue_summary_sent_on' => $today->toDateString()]) === 1;

                    if ($claimed) {
                        $this->notifier->overdueToSender($tasking, $overdue->count());
                        $summaries++;
                    }
                }
            });

        return [$chiefs, $summaries];
    }
}
