<?php

namespace Database\Factories;

use App\Enums\CommunityType;
use App\Models\Community;
use App\Models\Lga;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic communities ("Test Community 12").
 *
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'name' => 'Test Community '.fake()->unique()->numberBetween(1, 999999),
            'other_names' => [],
            'type' => CommunityType::Village,
            'population_estimate' => null,
            'notes' => null,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function at(float $latitude, float $longitude): static
    {
        return $this->state(['location' => Community::pointFrom($latitude, $longitude)]);
    }
}
