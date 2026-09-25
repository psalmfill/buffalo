<?php

namespace Database\Factories;

use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Models\IncidentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IncidentType>
 */
class IncidentTypeFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word().' '.fake()->word());

        return [
            'name' => $name,
            'code' => Str::snake($name).'_'.fake()->unique()->numberBetween(1, 999999),
            'description' => null,
            'sort_order' => 0,
            'is_active' => true,
            'category' => IncidentCategory::Crime,
            'default_severity' => Severity::Medium,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
