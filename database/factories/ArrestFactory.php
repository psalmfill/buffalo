<?php

namespace Database\Factories;

use App\Enums\ArrestStatus;
use App\Enums\Classification;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestPersonOffence;
use App\Models\Lga;
use App\Models\OffenceType;
use App\Models\Person;
use App\Models\SecurityAgency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Arrests for tests (Phase 11C). Run in the system context (scoped rows).
 *
 * @extends Factory<Arrest>
 */
class ArrestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference_no' => 'ARR/T/'.CarbonImmutable::now()->year.'/F'.str_pad((string) fake()->unique()->numberBetween(1, 9999999), 7, '0', STR_PAD_LEFT),
            'arrested_at' => CarbonImmutable::now()->subDay(),
            'security_agency_id' => SecurityAgency::factory(),
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'status' => ArrestStatus::InCustody,
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

    /**
     * An arrested person (on record, or unidentified when null) with offences.
     *
     * @param  list<OffenceType>  $offences
     */
    public function withPerson(?Person $person, array $offences = [], ?string $description = 'Unidentified man in a red shirt'): static
    {
        return $this->afterCreating(function (Arrest $arrest) use ($person, $offences, $description): void {
            $row = new ArrestPerson;
            $row->forceFill([
                'arrest_id' => $arrest->id,
                'state_id' => $arrest->state_id,
                'lga_id' => $arrest->lga_id,
                'person_id' => $person?->id,
                'unidentified_description' => $person === null ? $description : null,
                'status' => $arrest->status,
            ])->save();

            foreach ($offences === [] ? [OffenceType::factory()->create()] : $offences as $offence) {
                (new ArrestPersonOffence)->forceFill([
                    'arrest_person_id' => $row->id,
                    'state_id' => $arrest->state_id,
                    'lga_id' => $arrest->lga_id,
                    'offence_type_id' => $offence->id,
                ])->save();
            }
        });
    }
}
