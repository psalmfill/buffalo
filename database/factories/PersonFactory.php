<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\Gender;
use App\Enums\PersonRoleType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic persons ("Test Person 12"). Encrypted fields are empty unless
 * set; the model encrypts them on save.
 *
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'community_id' => null,
            'first_name' => 'Test',
            'last_name' => 'Person '.fake()->unique()->numberBetween(1, 999999),
            'other_names' => null,
            'gender' => Gender::Unknown,
            'date_of_birth' => null,
            'approximate_age' => null,
            'nin' => null,
            'phone' => null,
            'address' => null,
            'occupation' => null,
            'nationality' => 'Nigerian',
            'description' => null,
            'classification' => Classification::Restricted,
            'is_deceased' => false,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function inCommunity(Community $community): static
    {
        return $this->state(['community_id' => $community->id, 'lga_id' => $community->lga_id, 'state_id' => $community->state_id]);
    }

    public function named(string $first, string $last, ?string $other = null): static
    {
        return $this->state(['first_name' => $first, 'last_name' => $last, 'other_names' => $other]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }

    public function withRole(PersonRoleType $role, bool $active = true): static
    {
        return $this->afterCreating(fn (Person $person) => $person->roles()->create(['role' => $role, 'is_active' => $active]));
    }
}
