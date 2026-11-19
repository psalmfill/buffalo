<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\Tasking;
use App\Models\User;

/**
 * Tasking notifications (Phase 11H). The payload is the reference, the
 * priority and a link (checked when opened): never the title, the body,
 * a response or which other LGAs received it. Overdue summaries carry a
 * count only.
 */
final class TaskingNotification extends BuffaloNotification
{
    private const TITLES = [
        'sent' => 'New tasking from the Director',
        'note' => 'Follow-up note on a tasking',
        'recalled' => 'Tasking recalled',
        'acknowledged' => 'Tasking acknowledged',
        'completed' => 'Tasking completed',
        'responded' => 'Further response on a tasking',
        'overdue' => 'Tasking overdue',
        'overdue_summary' => 'Taskings overdue',
    ];

    public function __construct(
        public readonly string $event,
        public readonly string $taskingUlid,
        public readonly string $reference,
        public readonly string $priority,
        public readonly NotificationSeverity $level,
        public readonly ?string $recipientUlid = null,
        public readonly int $count = 0,
    ) {}

    public static function about(string $event, Tasking $tasking, NotificationSeverity $level, ?string $recipientUlid = null, int $count = 0): self
    {
        return new self($event, $tasking->ulid, (string) $tasking->reference_no, $tasking->priority->label(), $level, $recipientUlid, $count);
    }

    public function type(): string
    {
        return 'tasking_'.$this->event;
    }

    public function title(User $notifiable): string
    {
        return self::TITLES[$this->event] ?? 'Tasking update';
    }

    public function body(User $notifiable): string
    {
        $what = "{$this->reference} ({$this->priority})";

        return match ($this->event) {
            'sent' => "Tasking {$what} is waiting for you.",
            'note' => "A follow-up note was added to tasking {$what}.",
            'recalled' => "Tasking {$what} was recalled. Open it to see why.",
            'acknowledged' => "An LGA acknowledged tasking {$what}.",
            'completed' => "An LGA reported tasking {$what} completed.",
            'responded' => "An LGA added a response to tasking {$what}.",
            'overdue' => "Tasking {$what} is past its response date.",
            'overdue_summary' => $this->count === 1
                ? "1 LGA has not completed tasking {$what} by its due date."
                : "{$this->count} LGAs have not completed tasking {$what} by its due date.",
            default => "Tasking {$what}",
        };
    }

    public function url(User $notifiable): string
    {
        return '/taskings/'.$this->taskingUlid.($this->recipientUlid !== null ? '?recipient='.$this->recipientUlid : '');
    }

    public function severity(): NotificationSeverity
    {
        return $this->level;
    }
}
