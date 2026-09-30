<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Models\Organization;
use App\Models\OrganizationSector;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Test Organization '.fake()->unique()->numberBetween(1, 999999),
            'other_names' => [],
            'type' => OrganizationType::Company,
            'organization_sector_id' => OrganizationSector::factory(),
            'status' => OrganizationStatus::Active,
        ];
    }
}
