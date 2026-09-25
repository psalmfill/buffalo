<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the buffalo_test PostgreSQL database (see
| phpunit.xml), are wrapped in RefreshDatabase and start with roles and
| permissions seeded.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Roles and permissions exist in every environment (the seeder is
        // idempotent and safe in production), so every feature test has them.
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');
