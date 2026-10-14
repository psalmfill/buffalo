<?php

namespace App\Actions\Political;

use App\Models\Person;
use App\Models\PersonRoleType;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\RoleAssigner;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects the holder of a political office recorded against the wrong
 * person (Phase 10B.3). Not for an office that changed hands: that is an
 * end date on the old record and a new record for the new holder.
 *
 * The new holder must be a person in the user's OWN jurisdiction (`link`)
 * and gains the political_leader role if missing; the old holder's role is
 * deactivated when they hold no other current office. A named event on the
 * office and on both persons records the change and the reason.
 */
final class ReassignPoliticalOffice
{
    public function __construct(private readonly RoleAssigner $roles) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(PoliticalOffice $office, Person $newHolder, User $actor, string $reason): void
    {
        if (! $actor->can('reassign', $office) || ! $actor->can('link', $newHolder)) {
            throw new AuthorizationException('You may not reassign this office to that person.');
        }

        if ($newHolder->id === $office->person_id) {
            throw ValidationException::withMessages(['person' => 'This person already holds the office.']);
        }

        if (mb_strlen(trim($reason)) < 20) {
            throw ValidationException::withMessages(['reason' => 'Explain the correction in at least 20 characters.']);
        }

        DB::transaction(function () use ($office, $newHolder, $actor, $reason): void {
            /** @var PoliticalOffice $office */
            $office = PoliticalOffice::query()->whereKey($office->id)->lockForUpdate()->firstOrFail();
            $oldHolder = Person::query()->whereKey($office->person_id)->firstOrFail();

            $office->forceFill(['person_id' => $newHolder->id, 'updated_by' => $actor->id])->save();
            $this->roles->activate($newHolder, PersonRoleType::POLITICAL_LEADER, $actor);

            // Any current office, wherever it is (bookkeeping only: nothing is shown).
            $stillHolds = DB::table('political_offices')->where('person_id', $oldHolder->id)->whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('ended_on')->orWhere('ended_on', '>=', now()->toDateString()))
                ->exists();
            if (! $stillHolds) {
                $this->roles->deactivate($oldHolder, PersonRoleType::POLITICAL_LEADER);
            }

            $properties = ['office' => $office->ulid, 'old_holder' => $oldHolder->ulid, 'new_holder' => $newHolder->ulid, 'reason' => trim($reason)];
            AuditLog::event('political_office_reassigned', 'Office holder corrected', $office, $properties, $actor);
            AuditLog::event('political_office_reassigned', 'A political office recorded against this person was reassigned to another person', $oldHolder, $properties, $actor);
            AuditLog::event('political_office_reassigned', 'A political office was reassigned to this person', $newHolder, $properties, $actor);
        });
    }
}
