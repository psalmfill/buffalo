<?php

namespace App\Actions\Persons;

use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Adds and removes a person's aliases. Removing is a soft delete; both are
 * audited (the alias rows log automatically; the person's history shows
 * them).
 */
final class ManagePersonAliases
{
    public function add(Person $person, string $alias, User $actor): PersonAlias
    {
        $record = new PersonAlias(['alias' => trim($alias)]);
        $record->forceFill(['person_id' => $person->id, 'created_by' => $actor->id])->save();

        return $record;
    }

    public function remove(PersonAlias $alias, User $actor): void
    {
        DB::transaction(function () use ($alias, $actor): void {
            AuditLog::event('alias_removed', "Alias \"{$alias->alias}\" removed", $alias->person, ['alias' => $alias->ulid], $actor);
            $alias->delete();
        });
    }
}
