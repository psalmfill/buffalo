<?php

namespace App\Support\Persons\Merge;

use App\Enums\Gender;
use App\Models\Person;

/**
 * The person's scalar fields as a merge sees them (Phase 10A.4).
 *
 * A field "has a value" when it is not null/blank (and, for gender, not
 * "unknown"). Where only one record has a value it is kept; where both
 * differ the executor chooses. Encrypted fields are always read and
 * written through the model (plaintext in memory, re-encrypted on save, so
 * ciphertext and blind indexes are regenerated).
 */
final class MergeFields
{
    /** field => label */
    public const FIELDS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'other_names' => 'Other names',
        'gender' => 'Gender',
        'date_of_birth' => 'Date of birth',
        'approximate_age' => 'Approximate age',
        'nin' => 'NIN',
        'phone' => 'Phone number',
        'address' => 'Address',
        'occupation' => 'Occupation',
        'nationality' => 'Nationality',
        'description' => 'Description',
        'community' => 'Community',
    ];

    /** Encrypted at rest (the review page shows them; undo never lists their values). */
    public const PROTECTED = ['date_of_birth', 'nin', 'phone', 'address'];

    /**
     * The stored value of a field (community: its id).
     */
    public static function value(Person $person, string $field): mixed
    {
        return match ($field) {
            'community' => $person->community_id,
            'gender' => $person->gender->value,
            'approximate_age' => $person->approximate_age,
            default => $person->getAttribute($field),
        };
    }

    public static function hasValue(Person $person, string $field): bool
    {
        $value = self::value($person, $field);

        return match (true) {
            $value === null => false,
            $field === 'gender' => $value !== Gender::Unknown->value,
            is_string($value) => trim($value) !== '',
            default => true,
        };
    }

    /** Both records have a value and the values differ. */
    public static function conflicts(Person $survivor, Person $source, string $field): bool
    {
        if (! self::hasValue($survivor, $field) || ! self::hasValue($source, $field)) {
            return false;
        }

        return self::normalised(self::value($survivor, $field), $field) !== self::normalised(self::value($source, $field), $field);
    }

    /**
     * Which record's value the merge keeps when there is no conflict:
     * the survivor's when it has one, else the source's.
     *
     * @return 'survivor'|'source'
     */
    public static function automatic(Person $survivor, Person $source, string $field): string
    {
        return self::hasValue($survivor, $field) || ! self::hasValue($source, $field) ? 'survivor' : 'source';
    }

    /**
     * The person's scalar fields and place, for survivor_prior_values (and
     * for the undo comparison). Plaintext: the caller encrypts.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Person $person): array
    {
        return [
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'other_names' => $person->other_names,
            'gender' => $person->gender->value,
            'date_of_birth' => $person->date_of_birth,
            'approximate_age' => $person->approximate_age,
            'nin' => $person->nin,
            'phone' => $person->phone,
            'address' => $person->address,
            'occupation' => $person->occupation,
            'nationality' => $person->nationality,
            'description' => $person->description,
            'community_id' => $person->community_id,
            'state_id' => $person->state_id,
            'lga_id' => $person->lga_id,
            'classification' => $person->classificationLevel()->value,
            'is_deceased' => $person->is_deceased,
        ];
    }

    /** The snapshot key of a field. */
    public static function snapshotKey(string $field): string
    {
        return $field === 'community' ? 'community_id' : $field;
    }

    private static function normalised(mixed $value, string $field): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        // Names and free text: case-insensitive; NIN/phone are already normalised.
        return in_array($field, ['nin', 'phone', 'date_of_birth'], true) ? $value : mb_strtolower($value);
    }
}
