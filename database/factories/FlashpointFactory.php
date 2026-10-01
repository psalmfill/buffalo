<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\ThreatType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Flashpoints written directly (no first assessment): tests that need the
 * assessment trail use CreateFlashpoint.
 *
 * @extends Factory<Flashpoint>
 */
class FlashpointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'kind' => FlashpointKind::Flashpoint,
            'name' => 'Test Flashpoint '.fake()->unique()->numberBetween(1, 999999),
            'threat_type_id' => ThreatType::factory(),
            'concern_level' => ConcernLevel::Moderate,
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'location' => Flashpoint::pointFrom(6.5, 3.4),
            'radius_meters' => 1000,
            'description' => 'Recurring tension between two groups.',
            'status' => FlashpointStatus::Active,
            'classification' => Classification::Restricted,
            'last_assessed_at' => CarbonImmutable::now(),
            'review_due_at' => CarbonImmutable::today()->addDays(90),
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function at(float $latitude, float $longitude, int $radius = 1000): static
    {
        return $this->state(['location' => Flashpoint::pointFrom($latitude, $longitude), 'radius_meters' => $radius]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }

    public function blackspot(): static
    {
        return $this->state(['kind' => FlashpointKind::Blackspot]);
    }
}
