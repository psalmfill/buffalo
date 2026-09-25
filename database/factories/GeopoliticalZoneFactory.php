<?php

namespace Database\Factories;

use App\Models\GeopoliticalZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic zones only ("Test Zone 12"); real zones come from
 * database/data/zones_states.csv.
 *
 * @extends Factory<GeopoliticalZone>
 */
class GeopoliticalZoneFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'code' => 'Z'.$n,
            'name' => "Test Zone {$n}",
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
