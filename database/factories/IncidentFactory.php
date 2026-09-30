<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'incident_type_id' => IncidentType::factory(),
            'details' => [],
            'details_schema_version' => 1,
            'title' => 'Test incident '.fake()->unique()->numberBetween(1, 999999),
            'description' => 'Synthetic test incident.',
            'severity' => Severity::Medium,
            'occurred_at' => now()->subDay(),
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'status' => IncidentStatus::Draft,
            'classification' => Classification::Restricted,
            'reporting_officer_id' => User::factory(),
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function by(User $user): static
    {
        return $this->state(['reporting_officer_id' => $user->id, 'created_by' => $user->id]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
