# BUFFALO

Security Management and Intelligence Reporting system for Nigeria. Laravel + Inertia + React + TypeScript, PostgreSQL/PostGIS, Valkey, Horizon, Reverb, and an offline Leaflet map. It needs no internet access at runtime and runs on an air-gapped server.

Project rules for contributors (and Claude Code) are in [`CLAUDE.md`](CLAUDE.md).

## Requirements

- Docker Engine with Docker Compose v2 (`docker compose`)
- GNU Make
- Git

PHP, Composer and Node.js are **not** needed on the host. Everything runs in containers.

> On Apple Silicon, the `postgis/postgis` image only exists for `linux/amd64`, so Docker runs it under emulation. It is slower but works.

## Setup

```sh
git clone <repository-url> buffalo
cd buffalo
make setup
```

`make setup`:

1. Copies `.env.example` to `.env` if `.env` is missing.
2. Builds the PHP image.
3. Installs Composer and npm dependencies.
4. Generates the application key.
5. Generates the Wayfinder route helpers.
6. Starts the stack and waits for it to be healthy.
7. Runs the migrations.

Then create the first super admin (interactive; the password is prompted and hidden):

```sh
make artisan cmd="buffalo:create-super-admin"
```

and log in at <http://localhost:8000>. No seeder ever creates accounts: every further user is created by a super admin.

If a host port is already in use (for example 6379 by another project's Redis), change it in `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_VALKEY_PORT`, `FORWARD_MAILPIT_UI_PORT`, `VITE_PORT`) and run `make up`.

### Map tiles (one-time, needs internet)

The basemap is an offline [Protomaps](https://protomaps.com) extract of Nigeria, stored in the `map_tiles` Docker volume. It is not in the repository. Fetch it once:

```sh
make tiles url=https://build.protomaps.com/YYYYMMDD.pmtiles
```

Find a current build date at <https://maps.protomaps.com/builds/>. The extract (bounding box `2.6,4.2,14.7,13.9`, zoom 0–14) is about 550 MB. `scripts/fetch-map-tiles.sh` is the only part of BUFFALO that uses the internet. For an air-gapped server, run it on a connected machine and copy `nigeria.pmtiles` into the target's `map_tiles` volume (see the script header).

Until the tiles are installed, the dashboard map shows a plain background with the notice "Map tiles not installed — run `make tiles`."

## Services

| Service | URL / port (bound to 127.0.0.1) | Notes |
|---|---|---|
| Application (nginx) | <http://localhost:8000> | Also proxies Reverb WebSockets (`/app/`, `/apps/`) and serves tiles from `/tiles/` |
| Horizon | <http://localhost:8000/horizon> | Only accessible when `APP_ENV=local` (Phase 1 restricts it to `super_admin`) |
| Mailpit | <http://localhost:8025> | Catches all outgoing mail in development |
| Vite dev server | <http://localhost:5173> | Hot module replacement; loaded automatically by the app |
| PostgreSQL + PostGIS | `localhost:5432` | Database `buffalo`, user `buffalo`, password `buffalo`; tests use `buffalo_test` |
| Valkey | `localhost:6379` | Cache, sessions and queues |

Containers: `app` (PHP-FPM), `nginx`, `postgres`, `valkey`, `horizon`, `scheduler`, `reverb`, plus `mailpit` and `vite` in development. Named volumes: `postgres_data`, `valkey_data`, `evidence_data` (private evidence files, never served by nginx) and `map_tiles`.

## Make targets

| Target | Description |
|---|---|
| `make setup` | First-time setup (see above) |
| `make up` | Start the stack (regenerates Wayfinder routes first) |
| `make down` | Stop the stack |
| `make shell` | Bash shell in the `app` container |
| `make artisan cmd="..."` | Run an Artisan command, e.g. `make artisan cmd="route:list"` |
| `make test` | Run the Pest test suite against `buffalo_test` |
| `make lint` | Pint (test mode), Larastan, ESLint, Prettier check, `tsc --noEmit` |
| `make fix` | Apply Pint and Prettier fixes |
| `make fresh` | `migrate:fresh --seed` |
| `make logs` | Follow container logs |
| `make tiles url=...` | Fetch the offline map tiles (see above) |
| `make wayfinder` | Regenerate the TypeScript route helpers after changing routes or controllers |
| `make ci` | Run `scripts/ci.sh` in a clean copy of the working tree (Docker) |
| `make help` | List targets |

## Tests and code quality

```sh
make test   # Pest, against PostgreSQL (never SQLite)
make lint   # Pint --test, Larastan (level 7), ESLint, Prettier --check, tsc --noEmit
make fix    # Pint and Prettier in write mode
make ci     # the full CI pipeline, locally
```

CI (`.github/workflows/ci.yml`) only runs [`scripts/ci.sh`](scripts/ci.sh), which contains all the CI logic. In order, it runs: Composer install, npm install, frontend build, Pint, Larastan, ESLint, Prettier, typecheck, Pest.

## Development notes

- **Wayfinder routes.** The React code imports type-safe route helpers generated from Laravel routes (`resources/js/routes`, `resources/js/actions`, git-ignored). The `vite` container has no PHP, so after changing routes or controllers run `make wayfinder`. Vite picks up the change automatically.
- **Xdebug** is installed in the dev image but off. Set `XDEBUG_MODE=debug` in `.env` and run `make up`. Xdebug connects to `host.docker.internal:9003`, triggered by the `XDEBUG_TRIGGER` cookie or query parameter.
- **No external requests.** Fonts (Inter), Leaflet assets and everything else are bundled by Vite. Do not add CDN links, web fonts or third-party APIs (see `CLAUDE.md`).
- **Evidence files** go to the `evidence` disk (`/var/lib/buffalo/evidence` in the containers). It is never served directly or symlinked into `public/`.

## Reference data

States and geopolitical zones are seeded from `database/data/zones_states.csv`. Senatorial districts, LGAs and wards come from CSV files supplied by the project owner and are loaded with `make artisan cmd="buffalo:import-hierarchy"`; see [`database/data/README.md`](database/data/README.md) for the formats and options. Until those files exist, `make fresh` in a local environment seeds clearly synthetic districts and LGAs for Lagos and Kano, and prints a warning.

## Handover notes

Each phase or step records what was built, deviations, new packages and known issues in [`docs/handover/`](docs/handover). Phase prompts are in [`docs/prompts/`](docs/prompts).
