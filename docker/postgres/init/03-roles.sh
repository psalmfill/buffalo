#!/bin/sh
# Database roles (Phase 8A). Runs once, when the postgres_data volume is
# first initialised, as the superuser (POSTGRES_USER), after the extensions
# and the test database exist. Existing installations use
# `php artisan buffalo:db:provision-roles` instead (same result).
#
#   buffalo_owner     owns every schema object; used only by migrations
#   buffalo_app       the application (no DDL, append-only audit tables)
#   buffalo_readonly  SELECT only
#
# Passwords: BUFFALO_DB_OWNER_PASSWORD, BUFFALO_DB_APP_PASSWORD and
# BUFFALO_DB_READONLY_PASSWORD, or the same names with _FILE pointing at a
# Docker secret. Table privileges are narrowed after each migration.
set -eu

secret() {
    # $1: variable name. Prefer NAME_FILE (a Docker secret), then NAME.
    eval "file=\${${1}_FILE:-}"
    if [ -n "$file" ]; then
        tr -d '\r\n' < "$file"
    else
        eval "printf '%s' \"\${${1}:-}\""
    fi
}

OWNER_PASSWORD=$(secret BUFFALO_DB_OWNER_PASSWORD)
APP_PASSWORD=$(secret BUFFALO_DB_APP_PASSWORD)
READONLY_PASSWORD=$(secret BUFFALO_DB_READONLY_PASSWORD)

for value in "$OWNER_PASSWORD" "$APP_PASSWORD" "$READONLY_PASSWORD"; do
    if [ -z "$value" ]; then
        echo "03-roles.sh: a database role password is missing (BUFFALO_DB_*_PASSWORD or *_FILE)." >&2
        exit 1
    fi
done

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    -v owner_pw="$OWNER_PASSWORD" -v app_pw="$APP_PASSWORD" -v ro_pw="$READONLY_PASSWORD" <<'SQL'
SELECT format('CREATE ROLE %I', r) FROM unnest(ARRAY['buffalo_owner', 'buffalo_app', 'buffalo_readonly']) AS r
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = r)
\gexec
ALTER ROLE buffalo_owner WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION PASSWORD :'owner_pw';
ALTER ROLE buffalo_app WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION PASSWORD :'app_pw';
ALTER ROLE buffalo_readonly WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION PASSWORD :'ro_pw';
SQL

for database in "$POSTGRES_DB" buffalo_test; do
    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$database" -v db="$database" <<'SQL'
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO buffalo_owner, buffalo_app, buffalo_readonly;
REVOKE TEMPORARY ON DATABASE :"db" FROM buffalo_app, buffalo_readonly;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO buffalo_owner;
GRANT USAGE ON SCHEMA public TO buffalo_app, buffalo_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE buffalo_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO buffalo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE buffalo_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO buffalo_app;
ALTER DEFAULT PRIVILEGES FOR ROLE buffalo_owner IN SCHEMA public GRANT SELECT ON TABLES TO buffalo_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE buffalo_owner IN SCHEMA public GRANT SELECT ON SEQUENCES TO buffalo_readonly;
SQL
done
