<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\Support\RefreshDatabaseAsOwner;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests run against the buffalo_test PostgreSQL database (see
| phpunit.xml), are wrapped in RefreshDatabase and start with roles and
| permissions seeded. Migrations run as buffalo_owner; tests run as
| buffalo_app (Phase 8A).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabaseAsOwner::class)
    ->beforeEach(function () {
        // Roles and permissions exist in every environment (the seeder is
        // idempotent and safe in production), so every feature test has them.
        $this->seed(RolesAndPermissionsSeeder::class);
    })
    ->in('Feature');
