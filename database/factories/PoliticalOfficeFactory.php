<?php

namespace Database\Factories;

use App\Enums\PoliticalOfficeType;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<PoliticalOffice>
 */
class PoliticalOfficeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'office' => PoliticalOfficeType::Commissioner,
            'office_title' => null,
            'party' => null,
            'state_id' => fn (array $attributes) => DB::table('persons')->where('id', $attributes['person_id'])->value('state_id'),
            'senatorial_district_id' => null,
            'lga_id' => null,
            'ward_id' => null,
            'constituency_name' => null,
            'started_on' => now()->subYear()->toDateString(),
            'ended_on' => null,
        ];
    }

    public function forPerson(Person $person): static
    {
        return $this->state(['person_id' => $person->id, 'state_id' => $person->state_id]);
    }

    public function stateWide(PoliticalOfficeType $office, State $state): static
    {
        return $this->state(['office' => $office, 'state_id' => $state->id, 'senatorial_district_id' => null, 'lga_id' => null, 'ward_id' => null]);
    }

    public function senator(SenatorialDistrict $district): static
    {
        return $this->state(['office' => PoliticalOfficeType::Senator, 'state_id' => $district->state_id, 'senatorial_district_id' => $district->id, 'lga_id' => null]);
    }

    public function inLga(PoliticalOfficeType $office, Lga $lga): static
    {
        return $this->state(['office' => $office, 'state_id' => $lga->state_id, 'lga_id' => $lga->id]);
    }

    public function councillor(Ward $ward): static
    {
        return $this->state(fn () => [
            'office' => PoliticalOfficeType::Councillor,
            'ward_id' => $ward->id,
            'lga_id' => $ward->lga_id,
            'state_id' => Lga::query()->whereKey($ward->lga_id)->value('state_id'),
        ]);
    }

    public function ended(string $on = '-1 month'): static
    {
        return $this->state(['started_on' => now()->subYears(4)->toDateString(), 'ended_on' => now()->modify($on)->toDateString()]);
    }
}
