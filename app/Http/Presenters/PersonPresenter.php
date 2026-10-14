<?php

namespace App\Http\Presenters;

use App\Enums\AssociationRelationship;
use App\Enums\Classification;
use App\Enums\Gender;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
use BackedEnum;

/**
 * Persons for the browser: ULIDs and labels, never numeric ids.
 *
 * Only detail() includes the decrypted protected fields (NIN, phone, date
 * of birth, address), and only the person page and its edit form use it,
 * after the policy check. Every other shape leaves them out.
 */
final class PersonPresenter
{
    /**
     * A search result, picker entry or duplicate candidate: no protected
     * fields.
     *
     * @return array<string, mixed>
     */
    public static function row(Person $person): array
    {
        $photo = $person->relationLoaded('media')
            ? Person::photosCurrentFirst($person->media->where('collection_name', Person::PHOTOS))->first()
            : null;

        return [
            'ulid' => $person->ulid,
            'name' => $person->name,
            'gender' => self::option($person->gender),
            'roles' => $person->relationLoaded('roles') ? self::activeRoles($person) : [],
            'lga' => $person->relationLoaded('lga') ? ['ulid' => $person->lga->ulid, 'name' => $person->lga->name] : null,
            'state' => $person->relationLoaded('state') ? ['ulid' => $person->state->ulid, 'name' => $person->state->name] : null,
            'classification' => self::option($person->classificationLevel()),
            'is_deceased' => $person->is_deceased,
            'thumbnail' => $photo instanceof Media ? self::photoUrl($person, $photo, 'thumb') : null,
        ];
    }

    /**
     * The person page and edit form: includes the decrypted protected
     * fields.
     *
     * @return array<string, mixed>
     */
    public static function detail(Person $person): array
    {
        $person->loadMissing(['lga.state', 'community', 'roles']);

        return [
            ...self::row($person),
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'other_names' => $person->other_names,
            'state' => ['ulid' => $person->lga->state->ulid, 'name' => $person->lga->state->name],
            'community' => $person->community === null ? null : ['ulid' => $person->community->ulid, 'name' => $person->community->name],
            'date_of_birth' => $person->date_of_birth,
            'approximate_age' => $person->approximate_age,
            'nin' => $person->nin,
            'phone' => $person->phone,
            'address' => $person->address,
            'occupation' => $person->occupation,
            'nationality' => $person->nationality,
            'description' => $person->description,
            'all_roles' => $person->roles
                ->sortBy(fn (PersonRole $role) => [$role->type->sort_order, $role->type->name])
                ->values()
                ->map(fn (PersonRole $role) => [
                    'role' => $role->type->option(),
                    'type_active' => $role->type->is_active,
                    'is_active' => $role->is_active,
                    'notes' => $role->notes,
                ])->all(),
            'created_at' => $person->created_at?->toIso8601String(),
            'updated_at' => $person->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function activeRoles(Person $person): array
    {
        return array_values($person->roles
            ->filter(fn (PersonRole $role) => $role->is_active)
            ->sortBy(fn (PersonRole $role) => [$role->type->sort_order, $role->type->name])
            ->map(fn (PersonRole $role) => $role->type->option())
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public static function photo(Person $person, Media $media, bool $current): array
    {
        return [
            'ulid' => $media->ulid,
            'current' => $current,
            'thumbnail' => self::photoUrl($person, $media, 'thumb'),
            'preview' => self::photoUrl($person, $media, 'preview'),
            'original' => self::photoUrl($person, $media, null),
            'added_at' => $media->created_at?->toIso8601String(),
        ];
    }

    /**
     * The authorised streaming route (never a storage URL).
     */
    public static function photoUrl(Person $person, Media $media, ?string $conversion): string
    {
        return route('persons.photos.show', array_filter([
            'person' => $person->ulid,
            'media' => $media->ulid,
            'conversion' => $conversion,
        ]), absolute: false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function alias(PersonAlias $alias): array
    {
        return [
            'ulid' => $alias->ulid,
            'alias' => $alias->alias,
            'added_at' => $alias->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        return [
            'genders' => array_map(self::option(...), Gender::cases()),
            // Role types (Phase 11A.1): `roles` may be newly assigned;
            // `allRoles` (every type, for filters and display) includes
            // deactivated types and the person-of-interest type.
            'roles' => array_values(PersonRoleType::query()->assignable()->orderBy('sort_order')->orderBy('name')->get()->map(fn (PersonRoleType $t) => $t->option())->all()),
            'allRoles' => array_values(PersonRoleType::query()->orderBy('sort_order')->orderBy('name')->get()->map(fn (PersonRoleType $t) => $t->option())->all()),
            'classifications' => array_map(self::option(...), Classification::cases()),
            'relationships' => array_map(self::option(...), AssociationRelationship::cases()),
        ];
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function option(BackedEnum $case): array
    {
        /** @var Gender|Classification|AssociationRelationship $case */
        return ['value' => (string) $case->value, 'label' => $case->label()];
    }
}
