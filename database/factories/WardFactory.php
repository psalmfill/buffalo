<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic wards only ("Test Ward 12"). `state_id` is always taken from the
 * ward's LGA (enforced by a composite foreign key).
 *
 * @extends Factory<Ward>
 */
class WardFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1, 999999);

        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'code' => 'W'.$n,
            'name' => "Test Ward {$n}",
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
