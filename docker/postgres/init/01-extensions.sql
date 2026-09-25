-- Runs once, when the postgres_data volume is first initialised, against the
-- main database (POSTGRES_DB). Mounting this directory replaces the image's
-- own 10_postgis.sh, so every extension BUFFALO needs is created here.

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
