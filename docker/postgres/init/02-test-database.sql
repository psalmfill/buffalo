-- Test database used by Pest (see phpunit.xml). Owned by the application
-- user (POSTGRES_USER, the user running these init scripts) and given the
-- same extensions as the main database.

SELECT format('CREATE DATABASE buffalo_test OWNER %I', current_user)
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'buffalo_test')
\gexec

\connect buffalo_test

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
