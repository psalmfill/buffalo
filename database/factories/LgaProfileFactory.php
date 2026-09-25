<?php

namespace Database\Factories;

use App\Models\Lga;
use App\Models\LgaProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LgaProfile>
 */
class LgaProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'terrain' => [],
        ];
    }

    public function forLga(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }
}
