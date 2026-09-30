<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\AgencyFormation;
use App\Models\Lga;
use App\Models\SecurityAgency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgencyFormation>
 */
class AgencyFormationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'security_agency_id' => SecurityAgency::factory(),
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'name' => 'Test Formation '.fake()->unique()->numberBetween(1, 999999),
            'formation_type' => FormationType::Station,
            'status' => FormationStatus::Active,
            'classification' => Classification::Restricted,
            'is_temporary' => false,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function of(SecurityAgency $agency): static
    {
        return $this->state(['security_agency_id' => $agency->id]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
