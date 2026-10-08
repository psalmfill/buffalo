<?php

namespace App\Actions\Persons;

use App\Enums\MergeRequestStatus;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Notifications\PersonMergeNotification;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\NotificationRecipients;
use Illuminate\Support\Facades\DB;

/** Declines a merge request with a note (Phase 10A.7); the requester is told. */
final class DeclinePersonMergeRequest
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    public function __invoke(PersonMergeRequest $request, User $actor, string $note): void
    {
        DB::transaction(function () use ($request, $actor, $note): void {
            $request->forceFill([
                'status' => MergeRequestStatus::Declined,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => trim($note),
            ])->save();

            AuditLog::event('person_merge_declined', 'Merge request declined', $request, ['note' => trim($note)], $actor);
        });

        foreach ($this->recipients->users([$request->requested_by]) as $requester) {
            $requester->notify(new PersonMergeNotification(PersonMergeNotification::DECLINED, '/persons/merge-requests'));
        }
    }
}
