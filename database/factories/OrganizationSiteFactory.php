<?php

namespace Database\Factories;

use App\Enums\Classification;
use App\Enums\SecurityRelevance;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationSite>
 */
class OrganizationSiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Test Site '.fake()->unique()->numberBetween(1, 999999),
            'site_type' => SiteType::Facility,
            'lga_id' => Lga::factory(),
            'state_id' => fn (array $attributes) => Lga::query()->whereKey($attributes['lga_id'])->value('state_id'),
            'security_relevance' => SecurityRelevance::Medium,
            'status' => SiteStatus::Active,
            'classification' => Classification::Restricted,
        ];
    }

    public function in(Lga $lga): static
    {
        return $this->state(['lga_id' => $lga->id, 'state_id' => $lga->state_id]);
    }

    public function of(Organization $organization): static
    {
        return $this->state(['organization_id' => $organization->id]);
    }

    public function classified(Classification $level): static
    {
        return $this->state(['classification' => $level]);
    }
}
