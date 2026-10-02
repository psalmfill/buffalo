<?php

namespace Tests\Support;

use App\Support\Database\MigrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RefreshDatabase, migrating as buffalo_owner (Phase 8A). Tests run as
 * buffalo_app, the application's own role. Console events are off in
 * tests, so MigrationConnection's command hook does not apply here.
 */
trait RefreshDatabaseAsOwner
{
    use RefreshDatabase {
        migrateFreshUsing as baseMigrateFreshUsing;
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing()
    {
        return [...$this->baseMigrateFreshUsing(), '--database' => MigrationConnection::NAME];
    }
}
