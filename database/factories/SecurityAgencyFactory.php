<?php

namespace Database\Factories;

use App\Enums\AgencyType;
use App\Models\SecurityAgency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SecurityAgency>
 */
class SecurityAgencyFactory extends Factory
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
            'short_name' => strtoupper(fake()->unique()->lexify('???')),
            'agency_type' => AgencyType::Federal,
            'state_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
