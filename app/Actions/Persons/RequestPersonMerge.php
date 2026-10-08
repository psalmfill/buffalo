<?php

namespace App\Actions\Persons;

use App\Enums\MergeRequestStatus;
use App\Enums\Role;
use App\Models\Person;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Notifications\PersonMergeNotification;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\NotificationRecipients;
use App\Support\Persons\Merge\MergeBlocked;
use App\Support\Persons\Merge\MergePlan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * An LGA chief or Deputy State Director asks for two of their own records
 * to be merged (Phase 10A.7). The State Director(s) of the state are
 * notified; the payload names no one.
 */
final class RequestPersonMerge
{
    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * @throws MergeBlocked
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, Person $a, Person $b, ?Person $suggestedSurvivor, string $reason): PersonMergeRequest
    {
        if (! $actor->can('create', PersonMergeRequest::class) || ! $actor->can('requestMerge', $a) || ! $actor->can('requestMerge', $b)) {
            throw new AuthorizationException('You may not request a merge of these records.');
        }

        foreach (MergePlan::blockers($a, $b) as $blocker) {
            throw new MergeBlocked($blocker, 'other');
        }

        $pending = DB::table('person_merge_requests')->where('status', MergeRequestStatus::Pending->value)
            ->whereRaw('LEAST(person_a_id, person_b_id) = ? AND GREATEST(person_a_id, person_b_id) = ?', [min($a->id, $b->id), max($a->id, $b->id)])
            ->exists();

        if ($pending) {
            throw new MergeBlocked('A merge of these two records has already been requested.', 'other');
        }

        $request = DB::transaction(function () use ($actor, $a, $b, $suggestedSurvivor, $reason): PersonMergeRequest {
            $request = new PersonMergeRequest(['reason' => trim($reason)]);
            $request->forceFill([
                'state_id' => $a->state_id,
                'lga_id' => $actor->lga_id,
                'person_a_id' => $a->id,
                'person_b_id' => $b->id,
                'suggested_survivor_id' => $suggestedSurvivor?->id,
                'requested_by' => $actor->id,
                'status' => MergeRequestStatus::Pending,
            ])->save();

            AuditLog::event('person_merge_requested', 'Merge of two person records requested', $request, ['a' => $a->ulid, 'b' => $b->ulid], $actor);

            return $request;
        });

        $state = $a->state()->firstOrFail();

        foreach ($this->recipients->resolve(Role::StateDirector, $state) as $director) {
            $director->notify(new PersonMergeNotification(PersonMergeNotification::REQUESTED, '/persons/merge-requests'));
        }

        return $request;
    }
}
