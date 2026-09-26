<?php

namespace Database\Factories;

use App\Enums\ReligiousInstitutionType;
use App\Models\Community;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<ReligiousInstitution>
 */
class ReligiousInstitutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'lga_id' => fn (array $attributes) => DB::table('communities')->where('id', $attributes['community_id'])->value('lga_id'),
            'state_id' => fn (array $attributes) => DB::table('communities')->where('id', $attributes['community_id'])->value('state_id'),
            'religion_id' => Religion::factory(),
            'denomination_id' => null,
            'name' => 'Test Institution '.fake()->unique()->numberBetween(1, 999999),
            'type' => ReligiousInstitutionType::Church,
        ];
    }

    public function in(Community $community): static
    {
        return $this->state(['community_id' => $community->id, 'lga_id' => $community->lga_id, 'state_id' => $community->state_id]);
    }
}
