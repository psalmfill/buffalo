<?php

namespace App\Actions\Persons;

use App\Enums\Classification;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonRole;
use App\Models\PersonRoleType;
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
     * The checked roles (code => notes) become the person's active roles.
     * The form manages every assignable type (active, never the POI type)
     * and any other type the person already holds (e.g. one deactivated
     * since). A deactivated type is never newly assigned: not added, and
     * not switched back on (Phase 11A.1).
     *
     * @param  array<string, string|null>  $roles  role code => notes
     */
    private function syncRoles(Person $person, array $roles, User $actor): void
    {
        $existing = PersonRole::query()->with('type')->where('person_id', $person->id)->get()
            ->keyBy(fn (PersonRole $role) => $role->type->code);
        // Plain arrays: Eloquent collections merge by primary key, not by these keys.
        $types = collect([
            ...PersonRoleType::query()->assignable()->get()->keyBy('code')->all(),
            ...$existing->mapWithKeys(fn (PersonRole $role) => [$role->type->code => $role->type])->all(),
        ])->except([PersonRoleType::PERSON_OF_INTEREST]);

        foreach (array_keys($roles) as $code) {
            /** @var PersonRole|null $row */
            $row = $existing->get($code);
            /** @var PersonRoleType|null $type */
            $type = $types->get($code);

            if ($type === null || (! $type->is_active && ! ($row !== null && $row->is_active))) {
                throw new InvalidArgumentException("Role {$code} cannot be set here.");
            }
        }

        foreach ($types as $code => $type) {
            $checked = array_key_exists($code, $roles);
            $notes = $checked ? self::blankToNull($roles[$code]) : null;
            /** @var PersonRole|null $role */
            $role = $existing->get($code);

            if ($role === null) {
                if ($checked) {
                    $new = new PersonRole(['is_active' => true, 'notes' => $notes]);
                    $new->forceFill(['person_id' => $person->id, 'person_role_type_id' => $type->id, 'created_by' => $actor->id])->save();
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
