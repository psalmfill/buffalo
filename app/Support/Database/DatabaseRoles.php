<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;

/**
 * PostgreSQL roles and privileges (Phase 8A).
 *
 * | Role               | Login | Purpose                                                        |
 * |--------------------|-------|----------------------------------------------------------------|
 * | `buffalo_owner`    | yes   | Owns every schema object; used only by migrations              |
 * | `buffalo_app`      | yes   | The application: DML on tables, SELECT/INSERT on audit tables  |
 * | `buffalo_readonly` | yes   | SELECT only (backup verification, future reporting tools)      |
 *
 * The cluster superuser (POSTGRES_USER) only creates extensions, databases
 * and these roles. None of the three roles is a superuser, creates
 * databases or roles, or bypasses row-level security.
 *
 * Table privileges for buffalo_app follow from default privileges (new
 * tables of buffalo_owner get SELECT, INSERT, UPDATE, DELETE), narrowed by
 * applyTablePrivileges() after every migration:
 * - APPEND_ONLY tables: SELECT, INSERT only;
 * - FOLLOW_PLACE tables: SELECT, INSERT, and UPDATE of state_id/lga_id only
 *   (their place follows a moved incident or flashpoint; a trigger rejects
 *   any other change);
 * - OWNER_ONLY tables: SELECT only.
 * No role but the owner (and the superuser) may ALTER, TRUNCATE, disable
 * triggers or create objects.
 */
final class DatabaseRoles
{
    /** Audit tables: rows are inserted and read, never changed or removed. */
    public const APPEND_ONLY = [
        'activity_log',
        'analytics_exports',
        'audit_anchors',
        'login_histories',
        'record_access_logs',
        'record_match_events',
        'search_logs',
    ];

    /** Append-only except the place columns, which follow their parent. */
    public const FOLLOW_PLACE = [
        'flashpoint_assessments',
        'incident_status_histories',
        'incident_updates',
    ];

    /** Bookkeeping the application reads but never writes. */
    public const OWNER_ONLY = [
        'audit_chain_heads',
        'migrations',
    ];

    public function __construct(
        public readonly string $owner = 'buffalo_owner',
        public readonly string $app = 'buffalo_app',
        public readonly string $readonly = 'buffalo_readonly',
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('database.connections.pgsql_migrations.username'),
            (string) config('database.connections.pgsql.username'),
            (string) config('database.roles.readonly.username'),
        );
    }

    /**
     * Creates or updates the roles (cluster-wide). Run as the superuser.
     *
     * @param  array{owner: string, app: string, readonly: string}  $passwords
     */
    public function createRoles(Connection $admin, array $passwords): void
    {
        foreach (['owner' => $this->owner, 'app' => $this->app, 'readonly' => $this->readonly] as $key => $role) {
            $exists = $admin->selectOne('SELECT 1 AS found FROM pg_roles WHERE rolname = ?', [$role]) !== null;
            $admin->statement(sprintf(
                '%s ROLE %s WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION INHERIT PASSWORD %s',
                $exists ? 'ALTER' : 'CREATE',
                self::ident($role),
                $admin->getPdo()->quote($passwords[$key]),
            ));
        }
    }

    /**
     * Database-level and schema-level privileges and default privileges for
     * the connection's database, then ownership of existing objects and
     * table privileges. Idempotent. Run as the superuser.
     */
    public function provisionDatabase(Connection $admin): void
    {
        $database = self::ident((string) $admin->getDatabaseName());
        [$owner, $app, $readonly] = [self::ident($this->owner), self::ident($this->app), self::ident($this->readonly)];

        $admin->statement("REVOKE ALL ON DATABASE {$database} FROM PUBLIC");
        $admin->statement("GRANT CONNECT ON DATABASE {$database} TO {$owner}, {$app}, {$readonly}");
        // No temporary tables for the application or the read-only role.
        $admin->statement("REVOKE TEMPORARY ON DATABASE {$database} FROM {$app}, {$readonly}");

        $admin->statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        $admin->statement("GRANT USAGE, CREATE ON SCHEMA public TO {$owner}");
        $admin->statement("GRANT USAGE ON SCHEMA public TO {$app}, {$readonly}");
        $admin->statement("REVOKE CREATE ON SCHEMA public FROM {$app}, {$readonly}");

        $admin->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$app}");
        $admin->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO {$app}");
        $admin->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT ON TABLES TO {$readonly}");
        $admin->statement("ALTER DEFAULT PRIVILEGES FOR ROLE {$owner} IN SCHEMA public GRANT SELECT ON SEQUENCES TO {$readonly}");

        $this->reassignOwnership($admin);
        $this->applyTablePrivileges($admin);
    }

    /**
     * Narrows buffalo_app's table privileges (and grants the base ones on
     * existing tables). Run as the owner after every migration, or as the
     * superuser. Idempotent.
     */
    public function applyTablePrivileges(Connection $connection): void
    {
        [$app, $readonly] = [self::ident($this->app), self::ident($this->readonly)];

        foreach ($this->ownedTables($connection) as $table) {
            $quoted = self::ident($table);

            $connection->statement("GRANT SELECT ON {$quoted} TO {$readonly}");
            $connection->statement("REVOKE ALL ON {$quoted} FROM {$app}");

            $privileges = match (true) {
                in_array($table, self::APPEND_ONLY, true) => 'SELECT, INSERT',
                in_array($table, self::FOLLOW_PLACE, true) => 'SELECT, INSERT, UPDATE (state_id, lga_id)',
                in_array($table, self::OWNER_ONLY, true) => 'SELECT',
                default => 'SELECT, INSERT, UPDATE, DELETE',
            };
            $connection->statement("GRANT {$privileges} ON {$quoted} TO {$app}");
        }

        foreach ($connection->select("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'S' AND pg_get_userbyid(c.relowner) = ?", [$this->owner]) as $sequence) {
            $quoted = self::ident($sequence->relname);
            $connection->statement("GRANT USAGE, SELECT ON SEQUENCE {$quoted} TO {$app}");
            $connection->statement("GRANT SELECT ON SEQUENCE {$quoted} TO {$readonly}");
        }
    }

    /**
     * Tables and views in public owned by buffalo_owner.
     *
     * @return list<string>
     */
    public function ownedTables(Connection $connection): array
    {
        return array_values(array_map(fn (\stdClass $row): string => (string) $row->relname, $connection->select(
            "SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm') AND pg_get_userbyid(c.relowner) = ?
             ORDER BY c.relname",
            [$this->owner],
        )));
    }

    /**
     * Gives buffalo_owner every schema object in public that is not part of
     * an extension (existing databases created before Phase 8A).
     */
    private function reassignOwnership(Connection $admin): void
    {
        $owner = self::ident($this->owner);
        $notExtension = "NOT EXISTS (SELECT 1 FROM pg_depend d WHERE d.objid = %s AND d.deptype = 'e')";

        $relations = $admin->select(sprintf(
            "SELECT c.oid::regclass::text AS name, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p', 'v', 'm', 'S', 'f') AND pg_get_userbyid(c.relowner) <> ? AND %s
             ORDER BY CASE c.relkind WHEN 'S' THEN 1 ELSE 0 END",
            sprintf($notExtension, 'c.oid'),
        ), [$this->owner]);

        foreach ($relations as $relation) {
            // A sequence owned by a table moves with it.
            if ($relation->relkind === 'S' && $admin->selectOne('SELECT 1 AS owned FROM pg_class WHERE oid = ?::regclass AND pg_get_userbyid(relowner) = ?', [$relation->name, $this->owner]) !== null) {
                continue;
            }

            $kind = match ($relation->relkind) {
                'v' => 'VIEW',
                'm' => 'MATERIALIZED VIEW',
                'S' => 'SEQUENCE',
                'f' => 'FOREIGN TABLE',
                default => 'TABLE',
            };
            $admin->statement("ALTER {$kind} {$relation->name} OWNER TO {$owner}");
        }

        $functions = $admin->select(sprintf(
            "SELECT p.oid::regprocedure::text AS name FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND pg_get_userbyid(p.proowner) <> ? AND %s",
            sprintf($notExtension, 'p.oid'),
        ), [$this->owner]);

        foreach ($functions as $function) {
            $admin->statement("ALTER ROUTINE {$function->name} OWNER TO {$owner}");
        }

        $types = $admin->select(sprintf(
            "SELECT t.oid::regtype::text AS name, t.typtype FROM pg_type t JOIN pg_namespace n ON n.oid = t.typnamespace
             WHERE n.nspname = 'public' AND t.typtype IN ('e', 'd') AND pg_get_userbyid(t.typowner) <> ? AND %s",
            sprintf($notExtension, 't.oid'),
        ), [$this->owner]);

        foreach ($types as $type) {
            $admin->statement(sprintf('ALTER %s %s OWNER TO %s', $type->typtype === 'd' ? 'DOMAIN' : 'TYPE', $type->name, $owner));
        }
    }

    public static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
