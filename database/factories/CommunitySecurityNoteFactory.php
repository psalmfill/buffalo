<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<CommunitySecurityNote>
 */
class CommunitySecurityNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'community_id' => Community::factory(),
            'lga_id' => fn (array $attributes) => DB::table('communities')->where('id', $attributes['community_id'])->value('lga_id'),
            'state_id' => fn (array $attributes) => DB::table('communities')->where('id', $attributes['community_id'])->value('state_id'),
            'category' => SecurityNoteCategory::LandDispute,
            'title' => 'Test note '.fake()->unique()->numberBetween(1, 999999),
            'description' => 'Synthetic test note.',
            'status' => SecurityNoteStatus::Active,
            'classification' => Classification::Restricted,
            'observed_at' => now()->subDays(3)->toDateString(),
            'resolved_at' => null,
        ];
    }

    public function in(Community $community): static
    {
        return $this->state(['community_id' => $community->id, 'lga_id' => $community->lga_id, 'state_id' => $community->state_id]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
