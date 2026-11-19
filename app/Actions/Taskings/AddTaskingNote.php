<?php

namespace App\Actions\Taskings;

use App\Enums\NotificationSeverity;
use App\Models\Tasking;
use App\Models\TaskingNote;
use App\Models\User;
use App\Support\Taskings\TaskingNotifier;
use InvalidArgumentException;

/**
 * Adds a follow-up note to a sent tasking (Phase 11H): append-only
 * (trigger), rich text sanitised on save. Each recipient LGA's chief is
 * told (info).
 */
final class AddTaskingNote
{
    public function __construct(private readonly TaskingNotifier $notifier) {}

    public function __invoke(Tasking $tasking, string $body, User $actor): TaskingNote
    {
        if (! $tasking->isSent()) {
            throw new InvalidArgumentException('Follow-up notes are added to sent taskings only.');
        }

        $note = new TaskingNote;
        $note->forceFill([
            'tasking_id' => $tasking->id,
            'state_id' => $tasking->state_id,
            'body' => $body,
            'created_by' => $actor->id,
        ])->save();

        $this->notifier->toChiefs('note', $tasking, NotificationSeverity::Info);

        return $note;
    }
}
