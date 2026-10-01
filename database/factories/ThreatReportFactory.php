<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatSourceType;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Threat reports written directly, with a factory reference outside the
 * real THR sequence: tests of the sequence use CreateThreatReport.
 *
 * @extends Factory<ThreatReport>
 */
class ThreatReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_no' => 'THR/T/'.CarbonImmutable::now()->year.'/F'.str_pad((string) fake()->unique()->numberBetween(1, 9999999), 7, '0', STR_PAD_LEFT),
            'threat_type_id' => ThreatType::factory(),
            'title' => 'Planned attack on the weekly market',
            'description' => 'Armed men are said to be planning an attack.',
            'credibility' => ThreatCredibility::Possible,
            'urgency' => ThreatUrgency::Priority,
            'source_type' => ThreatSourceType::Community,
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'expires_at' => CarbonImmutable::now()->addDays(14),
            'status' => ThreatStatus::Active,
            'classification' => Classification::Restricted,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function at(float $latitude, float $longitude, ?int $radius = 2000): static
    {
        return $this->state(['location' => Flashpoint::pointFrom($latitude, $longitude), 'radius_meters' => $radius]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
