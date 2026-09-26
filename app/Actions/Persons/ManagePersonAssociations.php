<?php

namespace App\Actions\Persons;

use App\Enums\AssociationRelationship;
use App\Models\Person;
use App\Models\PersonAssociation;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Links two persons (stored once per pair, shown from both sides) and
 * removes a link (soft delete). Both persons must be visible to the actor:
 * the controller binds them through both scopes and the policy checks them.
 */
final class ManagePersonAssociations
{
    public function add(Person $person, Person $other, AssociationRelationship $relationship, ?string $notes, User $actor): PersonAssociation
    {
        if ($person->is($other)) {
            throw new InvalidArgumentException('A person cannot be associated with themselves.');
        }

        return DB::transaction(function () use ($person, $other, $relationship, $notes, $actor): PersonAssociation {
            $association = new PersonAssociation(['relationship' => $relationship, 'notes' => $notes === null || trim($notes) === '' ? null : trim($notes)]);
            $association->forceFill([
                'person_id' => $person->id,
                'associated_person_id' => $other->id,
                'created_by' => $actor->id,
            ])->save();

            AuditLog::event('association_added', "Associated with {$other->name} ({$relationship->label()})", $person, ['association' => $association->ulid, 'person' => $other->ulid], $actor);
            AuditLog::event('association_added', "Associated with {$person->name} ({$relationship->inverse()->label()})", $other, ['association' => $association->ulid, 'person' => $person->ulid], $actor);

            return $association;
        });
    }

    /**
     * @param  Person  $from  the person whose page the link was removed on
     */
    public function remove(PersonAssociation $association, Person $from, Person $other, User $actor): void
    {
        DB::transaction(function () use ($association, $from, $other, $actor): void {
            AuditLog::event('association_removed', "Association with {$other->name} removed", $from, ['association' => $association->ulid, 'person' => $other->ulid], $actor);
            AuditLog::event('association_removed', "Association with {$from->name} removed", $other, ['association' => $association->ulid, 'person' => $from->ulid], $actor);
            $association->delete();
        });
    }
}
