<?php

namespace App\Support\Persons;

use App\Models\Person;
use App\Models\PersonPhone;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * A person's phone numbers (Phase 11G.4): every number encrypted with a
 * blind index, one of them primary.
 *
 * Matching is exact, through the blind index, against ALL of a person's
 * numbers: personIdsWithNumber() returns the ids of persons having a
 * number, for use inside a persons query, so the persons' own scopes
 * (jurisdiction, classification, grants) still decide what is visible, as
 * they did when the number was a column of persons.
 */
final class PersonPhones
{
    /**
     * Ids of persons with this number (normalised, +234XXXXXXXXXX), as a
     * subquery for `whereIn('persons.id', ...)`. Deleted numbers never match.
     */
    public static function personIdsWithNumber(string $number): QueryBuilder
    {
        return DB::table('person_phones')
            ->join('blind_indexes', function ($join): void {
                $join->on('blind_indexes.indexable_id', '=', 'person_phones.id')
                    ->where('blind_indexes.indexable_type', (new PersonPhone)->getMorphClass())
                    ->where('blind_indexes.name', 'person_phone_bidx');
            })
            ->whereNull('person_phones.deleted_at')
            ->where('blind_indexes.value', PersonPhone::blindIndexFor($number))
            ->select('person_phones.person_id');
    }

    /** Whether this person has this number (normalised). */
    public static function has(Person $person, string $number): bool
    {
        return self::personIdsWithNumber($number)->where('person_phones.person_id', $person->id)->exists();
    }

    /**
     * The person's numbers, primary first, for the person page and PDF.
     *
     * @return list<array{ulid: string, number: string, label: array{value: string, label: string}, is_primary: bool}>
     */
    public static function forPerson(Person $person): array
    {
        return array_values(PersonPhone::query()->where('person_id', $person->id)
            ->orderByDesc('is_primary')->orderBy('id')->get()
            ->map(fn (PersonPhone $phone) => [
                'ulid' => $phone->ulid,
                'number' => $phone->number,
                'label' => ['value' => $phone->label, 'label' => PersonPhone::LABELS[$phone->label] ?? $phone->label],
                'is_primary' => $phone->is_primary,
            ])->all());
    }

    /** The primary number, if any. */
    public static function primary(Person $person): ?string
    {
        $phone = PersonPhone::query()->where('person_id', $person->id)->orderByDesc('is_primary')->orderBy('id')->first();

        return $phone?->number;
    }

    /**
     * Replaces the person's numbers with the given list (from the person
     * form): numbers already recorded are kept (label and primary updated),
     * new ones added, missing ones removed (soft delete). Exactly one is
     * primary when there are any. Logged on the person without the values.
     *
     * @param  list<array{number: string, label: string, is_primary: bool}>  $numbers  normalised
     */
    public static function sync(Person $person, array $numbers, ?User $actor): void
    {
        $numbers = self::deduplicate($numbers);
        $existing = PersonPhone::query()->where('person_id', $person->id)->get()->keyBy('number');
        $added = 0;
        $removed = 0;
        $keep = [];

        // Clear the primary first: the unique index allows one at a time.
        PersonPhone::query()->where('person_id', $person->id)->where('is_primary', true)->update(['is_primary' => false]);

        foreach ($numbers as $row) {
            /** @var PersonPhone|null $phone */
            $phone = $existing->get($row['number']);

            if ($phone === null) {
                $phone = new PersonPhone;
                $phone->forceFill(['person_id' => $person->id, 'number' => $row['number'], 'created_by' => $actor?->id]);
                $added++;
            }

            $phone->forceFill(['label' => $row['label'], 'is_primary' => $row['is_primary'], 'updated_by' => $actor?->id])->save();
            $keep[] = $phone->id;
        }

        foreach ($existing as $phone) {
            if (! in_array($phone->id, $keep, true)) {
                $phone->forceFill(['updated_by' => $actor?->id])->save();
                $phone->delete();
                $removed++;
            }
        }

        if ($added + $removed > 0) {
            AuditLog::event('phone_numbers_changed', 'Phone numbers changed', $person, ['added' => $added, 'removed' => $removed], $actor);
        }
    }

    /**
     * Adds one number (quick entry of a new person, Phase 4A.0), primary
     * when the person has none. Nothing happens if it is already recorded.
     */
    public static function add(Person $person, string $number, ?User $actor): void
    {
        if (self::has($person, $number)) {
            return;
        }

        $phone = new PersonPhone;
        $phone->forceFill([
            'person_id' => $person->id,
            'number' => $number,
            'label' => 'mobile',
            'is_primary' => ! PersonPhone::query()->where('person_id', $person->id)->where('is_primary', true)->exists(),
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ])->save();

        AuditLog::event('phone_numbers_changed', 'Phone numbers changed', $person, ['added' => 1, 'removed' => 0], $actor);
    }

    /**
     * One row per number; exactly one primary (the first marked, else the
     * first) when there are any.
     *
     * @param  list<array{number: string, label: string, is_primary: bool}>  $numbers
     * @return list<array{number: string, label: string, is_primary: bool}>
     */
    private static function deduplicate(array $numbers): array
    {
        $unique = [];

        foreach ($numbers as $row) {
            $unique[$row['number']] ??= $row;
        }

        $rows = array_values($unique);
        $primary = array_search(true, array_column($rows, 'is_primary'), true);
        $primary = $primary === false ? 0 : $primary;

        foreach ($rows as $i => $row) {
            $rows[$i]['is_primary'] = $i === $primary;
        }

        return $rows;
    }
}
