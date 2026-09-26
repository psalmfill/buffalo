<?php

namespace Database\Factories;

use App\Enums\VulnerableCategory;
use App\Models\Community;
use App\Models\Lga;
use App\Models\VulnerablePopulationStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VulnerablePopulationStat>
 */
class VulnerablePopulationStatFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'community_id' => null,
            'category' => VulnerableCategory::InternallyDisplaced,
            'adult_male' => 1,
            'adult_female' => 2,
            'child_male' => 3,
            'child_female' => 4,
            'as_of_date' => now()->subDay()->toDateString(),
            'source' => 'LGA social welfare office',
        ];
    }

    public function forLga(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id, 'community_id' => null]);
    }

    public function forCommunity(Community $community): static
    {
        return $this->state(['lga_id' => $community->lga_id, 'state_id' => $community->state_id, 'community_id' => $community->id]);
    }
}
