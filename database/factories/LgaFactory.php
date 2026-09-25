<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic LGAs only ("Test LGA 12"). The LGA's senatorial district always
 * belongs to the LGA's state (enforced by a composite foreign key):
 * - pass `senatorial_district_id` and the state is taken from the district;
 * - pass `state_id` and a new district is created in that state;
 * - pass neither and both are created.
 *
 * @extends Factory<Lga>
 */
class LgaFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'state_id' => fn (array $attributes) => is_numeric($attributes['senatorial_district_id'] ?? null)
                ? SenatorialDistrict::query()->whereKey($attributes['senatorial_district_id'])->value('state_id')
                : State::factory(),
            'senatorial_district_id' => fn (array $attributes) => SenatorialDistrict::factory()->state([
                'state_id' => $attributes['state_id'],
            ]),
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => "Test LGA {$n}",
            'headquarters' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
