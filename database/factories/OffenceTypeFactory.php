<?php

namespace Database\Factories;

use App\Models\OffenceType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OffenceType>
 */
class OffenceTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'name' => $name,
            'code' => Str::snake($name).'_'.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'threat_type_id' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
