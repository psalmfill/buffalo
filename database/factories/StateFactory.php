<?php

namespace Database\Factories;

use App\Models\GeopoliticalZone;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic states only ("Test State 12"); real states come from
 * database/data/zones_states.csv.
 *
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'geopolitical_zone_id' => GeopoliticalZone::factory(),
            'code' => 'T'.$n,
            'name' => "Test State {$n}",
            'capital' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
