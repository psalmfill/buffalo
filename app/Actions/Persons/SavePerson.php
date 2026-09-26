<?php

namespace App\Actions\Persons;

use App\Enums\Classification;
use App\Enums\PersonRoleType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates or updates a person from validated values, with their roles.
 *
 * The owning LGA (and state) is fixed once created: moving a person would
 * change who may see them. Roles are set from the checked boxes: an
 * unchecked role is deactivated, not deleted. The person-of-interest role
 * is never set or cleared here (POI module, Phase 3B).
 *
 * Every change is audited by the models: attribute changes (never the
 * encrypted values), protected-field changes, classification changes and
 * role changes.
 */
final class SavePerson
{
    /**
     * @param  array<string, mixed>  $data  validated person values
     * @param  array<string, string|null>  $roles  role value => notes, for the checked roles
     */
    public function create(Lga $lga, ?Community $community, array $data, array $roles, User $actor): Person
    {
        return DB::transaction(function () use ($lga, $community, $data, $roles, $actor): Person {
            $person = new Person;
            $person->fill(self::attributes($data));
            $person->forceFill([
                'classification' => $data['classification'] ?? Classification::Restricted->value,
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'community_id' => $community?->id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->syncRoles($person, $roles, $actor);

            return $person;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string|null>  $roles
     */
    public function update(Person $person, ?Community $community, array $data, array $roles, User $actor): Person
    {
        if ($community !== null && $community->lga_id !== $person->lga_id) {
            throw new InvalidArgumentException('The community must be in the person\'s LGA.');
        }

        return DB::transaction(function () use ($person, $community, $data, $roles, $actor): Person {
            $person->fill(self::attributes($data));
            $person->forceFill([
                'classification' => $data['classification'] ?? $person->classificationLevel()->value,
                'community_id' => $community?->id,
            ]);

            if ($person->isDirty()) {
                $person->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->syncRoles($person, $roles, $actor);

            return $person;
        });
    }

    /**
     * @param  array<string, string|null>  $roles  role value => notes
     */
    private function syncRoles(Person $person, array $roles, User $actor): void
    {
        foreach (array_keys($roles) as $value) {
            if (PersonRoleType::tryFrom($value) === null || $value === PersonRoleType::PersonOfInterest->value) {
                throw new InvalidArgumentException("Role {$value} cannot be set here.");
            }
        }

        $existing = $person->roles()->get()->keyBy(fn (PersonRole $role) => $role->role->value);

        foreach (PersonRoleType::assignable() as $type) {
            $checked = array_key_exists($type->value, $roles);
            $notes = $checked ? self::blankToNull($roles[$type->value]) : null;
            /** @var PersonRole|null $role */
            $role = $existing->get($type->value);

            if ($role === null) {
                if ($checked) {
                    $new = new PersonRole(['role' => $type, 'is_active' => true, 'notes' => $notes]);
                    $new->forceFill(['person_id' => $person->id, 'created_by' => $actor->id])->save();
                }

                continue;
            }

            $role->fill($checked ? ['is_active' => true, 'notes' => $notes] : ['is_active' => false]);

            if ($role->isDirty()) {
                $role->save();
            }
        }

        $person->unsetRelation('roles');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'other_names' => self::blankToNull($data['other_names'] ?? null),
            'gender' => $data['gender'],
            'date_of_birth' => self::blankToNull($data['date_of_birth'] ?? null),
            'approximate_age' => isset($data['approximate_age']) ? (int) $data['approximate_age'] : null,
            'nin' => self::blankToNull($data['nin'] ?? null),
            'phone' => self::blankToNull($data['phone'] ?? null),
            'address' => self::blankToNull($data['address'] ?? null),
            'occupation' => self::blankToNull($data['occupation'] ?? null),
            'nationality' => self::blankToNull($data['nationality'] ?? null) ?? 'Nigerian',
            'description' => self::blankToNull($data['description'] ?? null),
            'is_deceased' => (bool) ($data['is_deceased'] ?? false),
        ];
    }

    private static function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
