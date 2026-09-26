<?php

namespace App\Support\Persons;

use App\Models\Person;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * A person linked from another record (a community's traditional ruler, a
 * religious institution's leader: Phase 3A).
 *
 * The link is shown only when the viewer can see the person; otherwise the
 * page says a person is recorded without naming them, and editing the
 * record leaves the link unchanged unless it is replaced. Changes are
 * audited with ULIDs only, because the record's history may be read by
 * users who cannot see the person.
 */
final class LinkedPerson
{
    /**
     * @return array{person: array{ulid: string, name: string}|null, hidden: bool}
     */
    public static function present(?int $personId, ?Person $visible): array
    {
        return [
            'person' => $visible === null ? null : ['ulid' => $visible->ulid, 'name' => $visible->name],
            'hidden' => $personId !== null && $visible === null,
        ];
    }

    public static function id(?string $ulid): ?int
    {
        return $ulid === null ? null : Person::query()->where('ulid', $ulid)->value('id');
    }

    public static function logChange(Model $record, string $event, string $description, ?int $old, ?int $new, User $actor): void
    {
        if ($old === $new) {
            return;
        }

        $ulids = Person::query()->whereKey(array_filter([$old, $new]))->pluck('ulid', 'id');

        AuditLog::event($event, $description, $record, [
            'old_person' => $old === null ? null : ($ulids[$old] ?? 'not visible'),
            'new_person' => $new === null ? null : ($ulids[$new] ?? 'not visible'),
        ], $actor);
    }
}
