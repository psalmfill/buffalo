# BUFFALO — Phase 0: Project Setup and Infrastructure

Read `CLAUDE.md` in the repository root before starting. All rules there apply.

## Context

This is the first phase. The repository is empty apart from `CLAUDE.md` and `docs/prompts/`. Nothing has been built yet.

The host machine may not have PHP, Composer or Node installed. Use Docker containers to run Composer and npm when they are not available on the host (for example `docker run --rm -v "$PWD":/app -w /app composer:2 ...`).

## Goal

A complete, reproducible development environment. A new developer must be able to clone the repository, run `make setup`, and get the application running with PostgreSQL/PostGIS, Valkey, Horizon, the scheduler, Reverb, Mailpit, a Vite dev server, and a working offline map, with all tests and linters passing.

Work through the steps below in order. **Commit at the end of each step.**

---

## Step 0.1 — Laravel application

1. Create the application from the official Laravel **React starter kit** (Inertia + React + TypeScript + Tailwind + shadcn/ui), using the currently recommended installation method. Verify the correct command before running it.
2. The Laravel project root must be the repository root. Because the directory is not empty, create the project in a temporary directory and move its contents in, preserving `CLAUDE.md` and `docs/`.
3. Keep the starter kit's authentication pages as they are. Phase 1 will rework authentication; do not modify it now.
4. Configure for PostgreSQL (`DB_CONNECTION=pgsql`). Remove any SQLite database file and SQLite defaults.
5. Configure cache, session and queue to use the `redis` driver with `REDIS_CLIENT=phpredis`, pointed at the `valkey` service.
6. Set `APP_NAME=BUFFALO`, timezone `Africa/Lagos`.

**Commit:** `chore: create laravel application from react starter kit`

## Step 0.2 — Docker environment

Create this structure:

```
docker/
  php/Dockerfile
  php/php.ini
  php/opcache.ini
  nginx/default.conf
  postgres/init/01-extensions.sql
  postgres/init/02-test-database.sql
compose.yml
compose.override.yml
.dockerignore
Makefile
```

### `docker/php/Dockerfile`

Multi-stage, based on the official `php:8.4-fpm` image (pin an exact version tag).

- PHP extensions: `pdo_pgsql`, `pgsql`, `intl`, `zip`, `gd` (with JPEG, PNG, WebP), `exif`, `bcmath`, `pcntl`, `opcache`, and `redis` (phpredis via PECL).
- Composer copied from the official `composer:2` image (pinned).
- Runs as a non-root user whose UID/GID are set by build args (`UID`, `GID`, default 1000) so files created in the container are owned by the host developer.
- Targets:
  - `dev`: includes Xdebug installed but **disabled by default** (enabled via `XDEBUG_MODE` env var).
  - `prod`: a stage that installs Composer dependencies with `--no-dev --optimize-autoloader` and copies built frontend assets from a Node build stage. It only needs to build successfully now; production configuration is Phase 9.

### `compose.yml` (base services)

| Service | Image / build | Purpose / command |
|---|---|---|
| `app` | build `docker/php`, target `dev` | PHP-FPM |
| `nginx` | `nginx` stable Alpine, pinned | Serves the app; proxies Reverb WebSocket paths (`/app` and `/apps`) to the `reverb` service; serves map tiles from `/tiles/` |
| `postgres` | `postgis/postgis`, pinned to a current PostgreSQL major with PostGIS 3.x | Database; mounts `docker/postgres/init` to `/docker-entrypoint-initdb.d` |
| `valkey` | `valkey/valkey`, pinned Alpine tag | Cache, sessions, queues |
| `horizon` | same image as `app` | `php artisan horizon` |
| `scheduler` | same image as `app` | `php artisan schedule:work` |
| `reverb` | same image as `app` | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

Requirements:

- Named volumes: `postgres_data`, `valkey_data`, `evidence_data`, `map_tiles`.
- `evidence_data` mounted in `app`, `horizon` and `scheduler` at `/var/lib/buffalo/evidence`.
- `map_tiles` mounted **read-only** in `nginx` at `/usr/share/nginx/tiles`.
- Healthchecks for `postgres` (`pg_isready`), `valkey` (`valkey-cli ping`), and `app`. Services that depend on the database wait until it is healthy.
- `horizon`, `scheduler` and `reverb` restart automatically if they exit.
- No service publishes ports in `compose.yml`.

### `compose.override.yml` (development, loaded automatically)

- Mount the source code into `app`, `horizon`, `scheduler`, `reverb` and `nginx`.
- `nginx` published on `127.0.0.1:8000`.
- `postgres` published on `127.0.0.1:5432` and `valkey` on `127.0.0.1:6379` for local database tools. Bind to `127.0.0.1` only, never `0.0.0.0`.
- `mailpit` service (`axllent/mailpit`, pinned), UI on `127.0.0.1:8025`; Laravel mail configured to use it.
- `vite` service (official `node` LTS Alpine image, pinned) running `npm run dev` with HMR, published on `127.0.0.1:5173`. Configure `vite.config.ts` so HMR works through Docker.

### `docker/nginx/default.conf`

- Standard Laravel PHP-FPM configuration.
- WebSocket proxying to `reverb:8080` for Reverb paths, with the upgrade headers.
- `location /tiles/` serving static files from `/usr/share/nginx/tiles` with byte-range requests enabled (PMTiles depends on them), long cache headers, and correct CORS-free same-origin behaviour.
- Security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- `client_max_body_size 100M` (evidence uploads in Phase 5).
- Deny access to hidden files.

### Postgres init scripts

- `01-extensions.sql`: create extensions `postgis`, `pg_trgm`, `unaccent` in the main database.
- `02-test-database.sql`: create database `buffalo_test` owned by the app user, with the same three extensions.

### Laravel configuration

- Add an `evidence` filesystem disk: driver `local`, root `/var/lib/buffalo/evidence`, `visibility` private, `serve` false. It must never be publicly accessible or symlinked into `public/`.
- `phpunit.xml`: tests use `pgsql` with database `buffalo_test`, cache/session/queue `array`/`sync` as appropriate.
- `.env.example`: every variable needed by the Docker setup, with safe development defaults and comments. `.env` stays git-ignored.

### `Makefile`

Targets (each runs inside the correct container):

| Target | Does |
|---|---|
| `setup` | Copy `.env.example` to `.env` if missing, build images, start containers, install Composer and npm dependencies, generate app key, run migrations |
| `up` / `down` | Start / stop the stack |
| `shell` | Shell in the `app` container |
| `artisan` | `make artisan cmd="migrate"` style passthrough |
| `test` | Run Pest |
| `lint` | Pint (test mode), Larastan, ESLint, Prettier check, `tsc --noEmit` |
| `fix` | Pint and Prettier in write mode |
| `fresh` | `migrate:fresh --seed` |
| `logs` | Follow container logs |
| `tiles` | Run the map tile fetch script (Step 0.4) |

**Commit:** `chore(docker): add development environment`

## Step 0.3 — Infrastructure packages and code quality

1. Install and configure:
   - `laravel/horizon` — Horizon dashboard at `/horizon`, restricted by gate. For now the gate allows access only when `APP_ENV=local`; Phase 1 will restrict it to `super_admin`.
   - `laravel/reverb` — broadcasting connection `reverb`, frontend Echo client configured with host and port read from Vite env variables so WebSockets go through nginx.
   - `pestphp/pest` with the Laravel plugin (convert any PHPUnit example tests to Pest).
   - `larastan/larastan` at level 6, with a `phpstan.neon` at the repository root.
   - `laravel/pint` with a `pint.json` using the `laravel` preset.
2. Do **not** install the Spatie packages, Fortify changes or ciphersweet yet. Each is installed in the phase that uses it.
3. Frontend quality: ensure ESLint, Prettier and TypeScript strict mode are configured (the starter kit may already include some). Add `npm run lint`, `npm run format:check` and `npm run typecheck` scripts.
4. Continuous integration:
   - `scripts/ci.sh`: runs, in order, Composer install, npm install, frontend build, Pint check, Larastan, ESLint, Prettier check, typecheck, Pest. Exits non-zero on the first failure.
   - `.github/workflows/ci.yml`: runs `scripts/ci.sh` on push and pull request, with PostgreSQL/PostGIS and Valkey as service containers. Keep all logic in the script so it can be moved to another CI system easily.

**Commit:** `chore: add horizon, reverb, and code quality tooling`

## Step 0.4 — Offline assets and map

### Fonts and external requests

1. Find and remove every reference to external resources in the starter kit (for example font links to `fonts.bunny.net` or Google Fonts).
2. Use `@fontsource-variable/inter` (or `@fontsource/inter`) bundled through Vite, and configure Tailwind to use it.
3. Verify with a search of `resources/` and `public/` that no `http://` or `https://` URLs to external hosts remain in the built frontend. Document the check in the handover.

### Map tiles

1. `scripts/fetch-map-tiles.sh`:
   - Uses the `pmtiles` CLI (run via its official Docker image if available, otherwise downloaded binary) to extract a Nigeria region from a Protomaps basemap build.
   - Bounding box: `2.6,4.2,14.7,13.9` (west, south, east, north), max zoom 14.
   - Takes the source build URL as an argument, because build file names change by date. Print usage instructions if no argument is given, including where to find current builds.
   - Writes `nigeria.pmtiles` into the `map_tiles` Docker volume.
   - This script is the **only** part of the system allowed to use the internet, and it is run once, manually, at setup time. State this clearly in its header comment.
2. `*.pmtiles` files are git-ignored.

### Map component

1. Install `leaflet`, `protomaps-leaflet` and `pmtiles` (verify current package names and licences). **Do not use `react-leaflet`.**
2. Create `resources/js/components/map/BuffaloMap.tsx`: a React wrapper around Leaflet that:
   - Creates the map in a `ref`'d div and cleans it up on unmount.
   - Loads the basemap from `/tiles/nigeria.pmtiles` using protomaps-leaflet.
   - Accepts props: `center` (default centre of Nigeria, roughly `[9.08, 8.68]`), `zoom` (default 6), `className`, and `children`-free for now. Later phases will add layers.
   - Restricts panning to Nigeria's bounds.
   - If the tile file is missing or fails to load, still renders the map with a plain background and shows a small non-blocking notice: "Map tiles not installed — run `make tiles`."
   - Bundles Leaflet's CSS and marker images locally; nothing loaded from a CDN.
   - Confirm protomaps-leaflet renders labels without fetching external font or sprite assets. If it needs any assets, bundle them locally and document how.
3. Place the map on the starter kit's dashboard page as a placeholder, full width, about 500px tall.

**Commit:** `feat(map): add offline map component and tile fetch script`

## Step 0.5 — Health check and documentation

1. Confirm Laravel's `/up` health route works through nginx.
2. Add a feature test that checks `/up` returns 200.
3. Add a feature test that checks the `evidence` disk root is not inside `public/` and that no public symlink points to it.
4. Add a feature test (or Artisan command plus test) confirming the database connection has the `postgis`, `pg_trgm` and `unaccent` extensions installed.
5. Write `README.md` covering: requirements (Docker, Docker Compose, Make), setup with `make setup`, fetching map tiles with `make tiles`, all Makefile targets, service URLs (app, Mailpit, Horizon), how to run tests and linters, and where handover notes live.

**Commit:** `docs: add readme and infrastructure tests`

---

## Acceptance checklist

Verify each item and report the result in the handover:

- [ ] `make setup` on a clean clone brings up the full stack without manual steps (apart from `make tiles`).
- [ ] App loads at `http://localhost:8000`; starter kit login and registration pages render.
- [ ] Vite HMR works: editing a React component updates the browser without reload.
- [ ] Mailpit UI loads at `http://localhost:8025` and receives a test email sent from Tinker.
- [ ] Horizon dashboard loads at `/horizon` and processes a dispatched test job.
- [ ] Reverb: a test broadcast event is received by the browser through nginx.
- [ ] Map renders on the dashboard with tiles after `make tiles`, and shows the "tiles not installed" notice without them.
- [ ] Browser network tab shows zero requests to external hosts.
- [ ] `postgis`, `pg_trgm` and `unaccent` exist in both `buffalo` and `buffalo_test`.
- [ ] Postgres and Valkey ports are bound to `127.0.0.1` only.
- [ ] Every Docker image in compose files and Dockerfiles uses a pinned version tag.
- [ ] `make test` and `make lint` pass.
- [ ] `scripts/ci.sh` passes locally.
- [ ] The `prod` Docker target builds successfully.

## Out of scope for Phase 0

Do not build any of the following; they belong to later phases:

- Changes to authentication, 2FA, user management, roles, permissions (Phase 1)
- Any BUFFALO domain migrations, models or seeders (Phase 1 onward)
- Spatie packages, ciphersweet (installed when first used)
- Production compose file, TLS, backups (Phases 8 and 9)

## Handover

Write `docs/handover/phase-0.md` as described in `CLAUDE.md` section 9, including the exact pinned versions of every Docker image and the exact package versions installed. Print it at the end.
