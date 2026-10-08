<?php

namespace App\Actions\Persons;

use App\Models\Person;
use App\Models\PersonDuplicateDismissal;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Not a duplicate" (Phase 10A.3): the pair never reappears as a
 * candidate. State Directors and national users, both records in their own
 * jurisdiction. Named events on both persons.
 */
final class DismissDuplicatePair
{
    /**
     * @throws AuthorizationException
     */
    public function __invoke(User $actor, Person $a, Person $b, string $reason): void
    {
        if ($a->id === $b->id || ! $actor->can('merge', $a) || ! $actor->can('merge', $b)) {
            throw new AuthorizationException('You may not decide on these records.');
        }

        DB::transaction(function () use ($actor, $a, $b, $reason): void {
            $dismissal = new PersonDuplicateDismissal;
            $dismissal->forceFill([
                'person_a_id' => min($a->id, $b->id),
                'person_b_id' => max($a->id, $b->id),
                'dismissed_by' => $actor->id,
                'reason' => trim($reason),
            ])->save();

            foreach ([[$a, $b], [$b, $a]] as [$person, $other]) {
                AuditLog::event('duplicate_dismissed', 'Marked as not a duplicate of another record', $person, ['other' => $other->ulid, 'reason' => trim($reason)], $actor);
            }
        });
    }
}
