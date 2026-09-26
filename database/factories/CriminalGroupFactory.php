<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\CriminalGroupStatus;
use App\Enums\CriminalGroupType;
use App\Models\CriminalGroup;
use App\Models\Lga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CriminalGroup>
 */
class CriminalGroupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'name' => 'Test Group '.fake()->unique()->numberBetween(1, 999999),
            'other_names' => [],
            'type' => CriminalGroupType::Cult,
            'description' => 'Synthetic test group.',
            'estimated_size' => null,
            'status' => CriminalGroupStatus::Active,
            'classification' => Classification::Restricted,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
