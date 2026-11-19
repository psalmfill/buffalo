<?php

namespace App\Actions\Taskings;

use App\Enums\TaskingRecipientStatus;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\TaskingResponse;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Taskings\TaskingNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An LGA's progress on a tasking (Phase 11H), by its Security Chief:
 *
 * - read: set the first time the tasking is opened from the LGA;
 * - acknowledge: one click (marks it read too);
 * - complete: with a written report back (required); fixed afterwards;
 * - respond: further reports back after completing, append-only.
 *
 * Statuses only move forward (also a trigger). A recalled tasking can be
 * read but not acknowledged, completed or answered. Acknowledging,
 * completing and responding tell the sender and the state's directors.
 */
final class RecordTaskingProgress
{
    public function __construct(private readonly TaskingNotifier $notifier) {}

    /** Marks the row read on first opening; true when it changed. */
    public function read(TaskingRecipient $recipient, User $actor): bool
    {
        $changed = DB::table('tasking_recipients')
            ->where('id', $recipient->id)
            ->where('status', TaskingRecipientStatus::Delivered->value)
            ->update([
                'status' => TaskingRecipientStatus::Read->value,
                'read_at' => now(),
                'read_by' => $actor->id,
                'updated_at' => now(),
            ]) === 1;

        if ($changed) {
            $recipient->refresh();
            AuditLog::event('tasking_read', 'Tasking opened by the LGA', $recipient, [], $actor);
            $tasking = $recipient->tasking;

            if ($tasking !== null) {
                // Directors' progress lines refresh; nobody is notified.
                $this->notifier->broadcast($tasking, 'read', collect());
            }
        }

        return $changed;
    }

    public function acknowledge(TaskingRecipient $recipient, User $actor): void
    {
        $tasking = $this->sentTasking($recipient);

        if ($recipient->status->atLeast(TaskingRecipientStatus::Acknowledged)) {
            return;
        }

        $now = now();
        $recipient->forceFill([
            'status' => TaskingRecipientStatus::Acknowledged,
            'read_at' => $recipient->read_at ?? $now,
            'read_by' => $recipient->read_by ?? $actor->id,
            'acknowledged_at' => $now,
            'acknowledged_by' => $actor->id,
        ])->save();

        $this->notifier->toDirectors('acknowledged', $tasking, $recipient);
    }

    public function complete(TaskingRecipient $recipient, string $response, User $actor): void
    {
        $tasking = $this->sentTasking($recipient);

        if ($recipient->isCompleted()) {
            throw ValidationException::withMessages(['response' => 'This tasking is already completed. Add a further response instead.']);
        }

        $now = now();
        $recipient->forceFill([
            'status' => TaskingRecipientStatus::Completed,
            'read_at' => $recipient->read_at ?? $now,
            'read_by' => $recipient->read_by ?? $actor->id,
            'acknowledged_at' => $recipient->acknowledged_at ?? $now,
            'acknowledged_by' => $recipient->acknowledged_by ?? $actor->id,
            'completed_at' => $now,
            'completed_by' => $actor->id,
            'response' => $response,
        ])->save();

        $this->notifier->toDirectors('completed', $tasking, $recipient);
    }

    public function respond(TaskingRecipient $recipient, string $body, User $actor): TaskingResponse
    {
        $tasking = $this->sentTasking($recipient);

        if (! $recipient->isCompleted()) {
            throw ValidationException::withMessages(['body' => 'Mark the tasking completed first; further responses follow the completion report.']);
        }

        $response = new TaskingResponse;
        $response->forceFill([
            'tasking_id' => $tasking->id,
            'tasking_recipient_id' => $recipient->id,
            'state_id' => $recipient->state_id,
            'lga_id' => $recipient->lga_id,
            'body' => $body,
            'created_by' => $actor->id,
        ])->save();

        $this->notifier->toDirectors('responded', $tasking, $recipient);

        return $response;
    }

    private function sentTasking(TaskingRecipient $recipient): Tasking
    {
        $tasking = $recipient->tasking;

        if ($tasking === null || ! $tasking->isSent()) {
            throw ValidationException::withMessages(['tasking' => 'This tasking was recalled and can no longer be acted on.']);
        }

        return $tasking;
    }
}
