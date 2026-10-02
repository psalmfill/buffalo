<?php

namespace Tests\Support\Database;

use App\Support\Database\DatabaseRoles;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Planner statistics for index tests (Phase 8A).
 *
 * ANALYZE needs the MAINTAIN privilege, which buffalo_app does not hold,
 * and it must run in the test's own session to sample its uncommitted
 * rows. This grants MAINTAIN on the given tables in the test database
 * only, analyzes them as buffalo_app, and revokes it at once.
 */
final class Statistics
{
    public static function analyze(string ...$tables): void
    {
        $owner = DB::connection('pgsql_migrations');
        $app = DatabaseRoles::ident((string) config('database.connections.pgsql.username'));
        $list = implode(', ', array_map(DatabaseRoles::ident(...), $tables));

        $owner->statement("GRANT MAINTAIN ON {$list} TO {$app}");

        try {
            foreach ($tables as $table) {
                // A statement in this session after the grant makes it visible;
                // fail loudly rather than let ANALYZE skip the table.
                if (DB::selectOne("SELECT has_table_privilege(?, 'MAINTAIN') AS granted", [$table])->granted !== true) {
                    throw new RuntimeException("MAINTAIN on {$table} is not visible to the application role.");
                }
                DB::statement('ANALYZE '.DatabaseRoles::ident($table));
            }
        } finally {
            $owner->statement("REVOKE MAINTAIN ON {$list} FROM {$app}");
        }
    }
}
