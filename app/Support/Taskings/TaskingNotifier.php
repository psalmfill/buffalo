<?php

namespace App\Support\Taskings;

use App\Enums\NotificationSeverity;
use App\Enums\Role;
use App\Events\Taskings\TaskingChanged;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Notifications\TaskingNotification;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a tasking (Phase 11H), and the live refresh events:
 *
 * | Event                                   | Notified                                 |
 * |-----------------------------------------|------------------------------------------|
 * | sent                                    | each recipient LGA's current chief (by priority) |
 * | follow-up note, recalled                | each recipient LGA's current chief       |
 * | acknowledged, completed, response added | the sender and the state's directors     |
 * | overdue                                 | the LGA's chief once; the sender daily   |
 *
 * Every recipient may open the tasking: chiefs of recipient LGAs and the
 * state's directors. Payloads: reference, priority and link only.
 */
final class TaskingNotifier
{
    public function __construct(
        private readonly LgaChiefs $chiefs,
        private readonly NotificationRecipients $recipients,
    ) {}

    public function toChiefs(string $event, Tasking $tasking, NotificationSeverity $level): void
    {
        $chiefs = $this->chiefs->forLgas($this->recipientLgaIds($tasking))->values();

        if ($chiefs->isNotEmpty()) {
            Notification::send($chiefs, TaskingNotification::about($event, $tasking, $level));
        }

        $this->broadcast($tasking, $event, $chiefs);
    }

    /** Progress from one LGA: to the sender and the state's directors. */
    public function toDirectors(string $event, Tasking $tasking, TaskingRecipient $recipient): void
    {
        $directors = $this->directors($tasking);

        if ($directors->isNotEmpty()) {
            Notification::send($directors, TaskingNotification::about($event, $tasking, NotificationSeverity::Info, $recipient->ulid));
        }

        // The LGA's own chief (another session, a successor) refreshes too.
        $this->broadcast($tasking, $event, $this->chiefs->forLgas([$recipient->lga_id])->values());
    }

    public function overdueToChief(Tasking $tasking, TaskingRecipient $recipient): void
    {
        $chiefs = $this->chiefs->forLgas([$recipient->lga_id])->values();

        if ($chiefs->isNotEmpty()) {
            Notification::send($chiefs, TaskingNotification::about('overdue', $tasking, NotificationSeverity::Warning));
        }
    }

    public function overdueToSender(Tasking $tasking, int $count): void
    {
        $sender = $this->recipients->users([$tasking->sent_by]);

        if ($sender->isNotEmpty()) {
            Notification::send($sender, TaskingNotification::about('overdue_summary', $tasking, NotificationSeverity::Warning, count: $count));
        }

        $this->broadcast($tasking, 'overdue', collect());
    }

    /**
     * @param  Collection<int, User>  $chiefs
     */
    public function broadcast(Tasking $tasking, string $event, Collection $chiefs): void
    {
        TaskingChanged::dispatch($tasking, $event, array_values(array_map(fn (User $chief): string => $chief->ulid, $chiefs->all())));
    }

    /**
     * The sender and every active SD and DSD of the tasking's state.
     *
     * @return Collection<int, User>
     */
    private function directors(Tasking $tasking): Collection
    {
        $state = $tasking->state;

        return $this->recipients->users([$tasking->sent_by])
            ->merge($this->recipients->resolve(Role::StateDirector, $state))
            ->merge($this->recipients->resolve(Role::DeputyStateDirector, $state))
            ->unique('id')
            ->values();
    }

    /**
     * Every recipient LGA (not only those the current context may see).
     *
     * @return list<int>
     */
    private function recipientLgaIds(Tasking $tasking): array
    {
        return array_values(array_map(fn (mixed $id): int => (int) $id, DB::table('tasking_recipients')->where('tasking_id', $tasking->id)->pluck('lga_id')->all()));
    }
}
