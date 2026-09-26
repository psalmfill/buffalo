<?php

namespace Database\Factories;

use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\Person;
use App\Models\PersonOfInterest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<PersonOfInterest>
 */
class PersonOfInterestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'state_id' => fn (array $attributes) => DB::table('persons')->where('id', $attributes['person_id'])->value('state_id'),
            'lga_id' => fn (array $attributes) => DB::table('persons')->where('id', $attributes['person_id'])->value('lga_id'),
            'risk_level' => RiskLevel::Medium,
            'status' => PoiStatus::AtLarge,
            'offence_categories' => [],
            'known_offences' => 'Synthetic test offences.',
            'review_due_at' => now()->addYear()->toDateString(),
        ];
    }

    public function forPerson(Person $person): static
    {
        return $this->state(['person_id' => $person->id, 'state_id' => $person->state_id, 'lga_id' => $person->lga_id]);
    }
}
