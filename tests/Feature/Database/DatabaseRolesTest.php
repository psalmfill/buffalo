<?php

use App\Support\Database\DatabaseRoles;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Phase 8A.1: the suite runs connected as buffalo_app. These tests prove
| what that role cannot do, against the real roles (never the superuser).
*/

/** Runs a statement as the application role inside a savepoint. */
function asApp(string $sql): void
{
    DB::transaction(fn () => DB::statement($sql));
}

test('the tests and the application run as buffalo_app, which has no special attributes', function () {
    $role = DB::selectOne('SELECT current_user AS name, r.rolsuper, r.rolcreatedb, r.rolcreaterole, r.rolbypassrls, r.rolreplication FROM pg_roles r WHERE r.rolname = current_user');

    expect($role->name)->toBe('buffalo_app')
        ->and([$role->rolsuper, $role->rolcreatedb, $role->rolcreaterole, $role->rolbypassrls, $role->rolreplication])->toBe([false, false, false, false, false]);
    // Migrations (RefreshDatabase) ran as the owner.
    expect(DB::selectOne("SELECT pg_get_userbyid(relowner) AS owner FROM pg_class WHERE relname = 'migrations'")->owner)->toBe('buffalo_owner');
});

test('the application role cannot alter schema, disable triggers, truncate or create objects', function () {
    expect(fn () => asApp('ALTER TABLE users ADD COLUMN pwned text'))->toThrow(QueryException::class, 'must be owner of table users')
        ->and(fn () => asApp('ALTER TABLE activity_log DISABLE TRIGGER activity_log_immutable'))->toThrow(QueryException::class, 'must be owner of table activity_log')
        ->and(fn () => asApp('ALTER TABLE incidents DISABLE TRIGGER ALL'))->toThrow(QueryException::class, 'must be owner of table incidents')
        ->and(fn () => asApp('TRUNCATE users CASCADE'))->toThrow(QueryException::class, 'permission denied for table users')
        ->and(fn () => asApp('DROP TABLE search_logs'))->toThrow(QueryException::class, 'must be owner of table search_logs')
        ->and(fn () => asApp('CREATE TABLE pwned (x int)'))->toThrow(QueryException::class, 'permission denied for schema public')
        ->and(fn () => asApp('CREATE FUNCTION pwned() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'))->toThrow(QueryException::class, 'permission denied for schema public')
        ->and(fn () => asApp('CREATE TEMPORARY TABLE pwned (x int)'))->toThrow(QueryException::class, 'permission denied to create temporary tables')
        ->and(fn () => asApp("SET session_replication_role = 'replica'"))->toThrow(QueryException::class, 'permission denied to set parameter');
});

test('append-only tables accept inserts but no update or delete from the application', function () {
    foreach (array_filter([...DatabaseRoles::APPEND_ONLY, ...DatabaseRoles::FOLLOW_PLACE], fn (string $t) => Schema::hasTable($t)) as $table) {
        expect(fn () => asApp("UPDATE {$table} SET id = id"))->toThrow(QueryException::class, "permission denied for table {$table}")
            ->and(fn () => asApp("DELETE FROM {$table}"))->toThrow(QueryException::class, "permission denied for table {$table}")
            ->and(fn () => asApp("TRUNCATE {$table}"))->toThrow(QueryException::class, "permission denied for table {$table}");
        expect(DB::selectOne("SELECT has_table_privilege(current_user, ?, 'INSERT') AS i, has_table_privilege(current_user, ?, 'SELECT') AS s", [$table, $table]))
            ->toEqual((object) ['i' => true, 's' => true]);
    }

    // The place of follow-ups, status history and assessments may follow their parent.
    foreach (DatabaseRoles::FOLLOW_PLACE as $table) {
        expect(DB::selectOne("SELECT has_column_privilege(current_user, ?, 'lga_id', 'UPDATE') AS lga, has_column_privilege(current_user, ?, 'id', 'UPDATE') AS other", [$table, $table]))
            ->toEqual((object) ['lga' => true, 'other' => false]);
    }
});

test('privileges match the role design on every table', function () {
    $roles = DatabaseRoles::fromConfig();
    $tables = $roles->ownedTables(DB::connection());
    $notOwned = DB::select("SELECT c.relname, pg_get_userbyid(c.relowner) AS owner FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'r' AND pg_get_userbyid(c.relowner) <> 'buffalo_owner'
        AND NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = c.oid AND d.deptype = 'e')");

    expect($notOwned)->toBe([])->and(count($tables))->toBeGreaterThan(90);

    foreach ($tables as $table) {
        $p = DB::selectOne(<<<'SQL'
            SELECT has_table_privilege('buffalo_app', ?, 'SELECT') s, has_table_privilege('buffalo_app', ?, 'INSERT') i,
                   has_table_privilege('buffalo_app', ?, 'UPDATE') u, has_table_privilege('buffalo_app', ?, 'DELETE') d,
                   has_table_privilege('buffalo_app', ?, 'TRUNCATE') t, has_table_privilege('buffalo_app', ?, 'TRIGGER') tr,
                   has_table_privilege('buffalo_readonly', ?, 'SELECT') rs, has_table_privilege('buffalo_readonly', ?, 'INSERT') ri
            SQL, array_fill(0, 8, $table));

        $expected = match (true) {
            in_array($table, DatabaseRoles::APPEND_ONLY, true), in_array($table, DatabaseRoles::FOLLOW_PLACE, true), array_key_exists($table, DatabaseRoles::UNDO_COLUMNS) => [true, true, false, false],
            in_array($table, DatabaseRoles::OWNER_ONLY, true) => [true, false, false, false],
            default => [true, true, true, true],
        };
        expect([$p->s, $p->i, $p->u, $p->d])->toBe($expected, $table)
            ->and([$p->t, $p->tr, $p->rs, $p->ri])->toBe([false, false, true, false], $table);
    }
});

test('every table with an append-only trigger is in the append-only lists', function () {
    $guarded = array_map(fn ($r) => $r->table, DB::select(<<<'SQL'
        SELECT DISTINCT t.tgrelid::regclass::text AS table FROM pg_trigger t JOIN pg_proc p ON p.oid = t.tgfoid
        WHERE NOT t.tgisinternal AND (t.tgtype & 1) = 1 AND (t.tgtype & 16) = 16
          AND p.proname IN ('buffalo_reject_audit_change', 'login_histories_reject_change', 'buffalo_append_only_follow_place')
        ORDER BY 1
        SQL));

    expect($guarded)->not->toBe([])
        ->and(array_diff($guarded, [...DatabaseRoles::APPEND_ONLY, ...DatabaseRoles::FOLLOW_PLACE]))->toBe([]);
});

test('the triggers still stop the owner role (defence in depth)', function () {
    $owner = DB::connection('pgsql_migrations');

    expect(fn () => $owner->transaction(fn () => $owner->statement('TRUNCATE activity_log')))->toThrow(QueryException::class, 'activity_log is append-only: TRUNCATE')
        ->and(fn () => $owner->transaction(fn () => $owner->statement('TRUNCATE login_histories')))->toThrow(QueryException::class, 'login_histories is append-only: TRUNCATE')
        ->and(fn () => $owner->transaction(fn () => $owner->statement('TRUNCATE incident_updates')))->toThrow(QueryException::class, 'append-only');
    expect($owner->selectOne('SELECT current_user AS name')->name)->toBe('buffalo_owner');
});
