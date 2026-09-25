<?php

namespace Database\Factories;

use App\Models\SenatorialDistrict;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic districts only ("Test District 12", code "<STATE>-T12").
 *
 * @extends Factory<SenatorialDistrict>
 */
class SenatorialDistrictFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'state_id' => State::factory(),
            'code' => fn (array $attributes) => State::query()->whereKey($attributes['state_id'])->value('code').'-T'.$n,
            'name' => "Test District {$n}",
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
