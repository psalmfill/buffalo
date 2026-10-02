<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseRoles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Creates or updates the database roles and privileges (Phase 8A) on an
 * existing installation, as the cluster superuser (`pgsql_admin`). New
 * installations get the same from docker/postgres/init/03-roles.sh.
 * Idempotent: safe to run again, for example after changing a password.
 *
 * It gives buffalo_owner every schema object that is not part of an
 * extension, sets default privileges, and narrows buffalo_app's rights on
 * the audit tables. Passwords come from DB_PASSWORD (buffalo_app),
 * DB_MIGRATIONS_PASSWORD (buffalo_owner) and DB_READONLY_PASSWORD.
 */
class ProvisionDatabaseRoles extends Command
{
    protected $signature = 'buffalo:db:provision-roles
        {--database=* : Databases to provision (default: the application database)}';

    protected $description = 'Create the owner, application and read-only database roles and apply their privileges (run as the superuser)';

    public function handle(): int
    {
        $passwords = [
            'owner' => (string) config('database.connections.pgsql_migrations.password'),
            'app' => (string) config('database.connections.pgsql.password'),
            'readonly' => (string) config('database.roles.readonly.password'),
        ];

        foreach ($passwords as $role => $password) {
            if ($password === '') {
                $this->components->error("No password is configured for the {$role} role.");

                return self::FAILURE;
            }
        }

        $roles = DatabaseRoles::fromConfig();
        /** @var list<string> $databases */
        $databases = $this->option('database') ?: [(string) config('database.connections.pgsql.database')];
        $base = config('database.connections.pgsql_admin');

        try {
            $roles->createRoles(DB::connection('pgsql_admin'), $passwords);
            $this->components->info("Roles ready: {$roles->owner}, {$roles->app}, {$roles->readonly}.");

            foreach ($databases as $database) {
                config(['database.connections.pgsql_admin_target' => [...$base, 'database' => $database]]);
                DB::purge('pgsql_admin_target');
                $connection = DB::connection('pgsql_admin_target');
                $connection->transaction(fn () => $roles->provisionDatabase($connection));
                $tables = count($roles->ownedTables($connection));
                DB::purge('pgsql_admin_target');
                $this->components->info("Database {$database}: {$tables} tables owned by {$roles->owner}; privileges applied.");
            }
        } catch (Throwable $e) {
            // A failed ALTER ROLE statement would carry its password: mask it.
            $this->components->error('Provisioning failed: '.preg_replace("/PASSWORD\\s+'(?:[^']|'')*'/i", "PASSWORD '***'", $e->getMessage()));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
