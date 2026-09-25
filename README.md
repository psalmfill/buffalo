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
7. Runs the migrations and the seeders: roles and permissions, zones and states, and the hierarchy (the owner-supplied files if present; otherwise, only when `APP_ENV=local`, synthetic LGAs for Lagos and Kano). The seeders never create accounts and are safe to run again.

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
| Horizon | <http://localhost:8000/horizon> | Super admins only (`system.horizon`); "Queue monitor" in the sidebar |
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
make test   # Pest, against PostgreSQL (never SQLite), then Vitest (frontend unit tests)
make lint   # Pint --test, Larastan (level 7), ESLint, Prettier --check, tsc --noEmit
make fix    # Pint and Prettier in write mode
make ci     # the full CI pipeline, locally
```

CI (`.github/workflows/ci.yml`) only runs [`scripts/ci.sh`](scripts/ci.sh), which contains all the CI logic. In order, it runs: Composer install, npm install, frontend build, Pint, Larastan, ESLint, Prettier, typecheck, Vitest, Pest.

Vitest tests live next to the code as `resources/js/**/*.test.ts` and run in Node (`vitest.config.ts`). They import the Wayfinder helpers, so run `make wayfinder` first if routes changed.

## Development notes

- **Wayfinder routes.** The React code imports type-safe route helpers generated from Laravel routes (`resources/js/routes`, `resources/js/actions`, git-ignored). The `vite` container has no PHP, so after changing routes or controllers run `make wayfinder`. Vite picks up the change automatically.
- **Xdebug** is installed in the dev image but off. Set `XDEBUG_MODE=debug` in `.env` and run `make up`. Xdebug connects to `host.docker.internal:9003`, triggered by the `XDEBUG_TRIGGER` cookie or query parameter.
- **No external requests.** Fonts (Inter), Leaflet assets and everything else are bundled by Vite. Do not add CDN links, web fonts or third-party APIs (see `CLAUDE.md`).
- **Evidence files** go to the `evidence` disk (`/var/lib/buffalo/evidence` in the containers). It is never served directly or symlinked into `public/`.

## Accounts and sign-in

- There is no self-registration. A super admin creates each account at **Users → New user** (`/users/create`) and chooses how the **one-time setup link** (valid 72 hours) reaches the user: **Send by email** (only when `BUFFALO_MAIL_ENABLED=true`) or **Show link once**, for handover by another route. The link is never shown again; issue a new one from the user's page if needed. The user chooses a password and must enrol in **two-factor authentication** (TOTP) before using BUFFALO.
- Passwords: at least 12 characters with upper and lower case, a number and a symbol; the last 5 cannot be reused.
- 5 failed attempts (password or 2FA code) lock an account for 15 minutes, doubling for each further lock within 24 hours (maximum 24 hours). Super admins are notified.
- Sessions: 30-minute idle timeout (with a warning 2 minutes before), 12-hour maximum, one active session per account (`BUFFALO_SINGLE_SESSION`).
- Every sign-in event is recorded in the append-only `login_histories` table.
- Deactivating a user (a reason is required) signs them out on their next request. Super admins can also edit profiles, change role/jurisdiction, unlock accounts, reset 2FA and issue new setup links; every action is audited.

## Interface

- **Navigation** is data: `resources/js/config/navigation.ts` lists sections and items, each with the permission that shows it. Adding a page to the sidebar is one entry. Hiding a link is never a security control; every route is authorised on the server.
- **Shared page data:** `auth.user` (ULIDs and names, role, jurisdiction and `jurisdictionLabel`), `auth.permissions` and `app` (`name`, `banner`, `sessionIdleMinutes`). Use `useAuth()` and `can()` in React. No numeric ids are ever shared (tested).
- **Dashboard:** `/dashboard` shows a page per role: system overview (super admin), national (DG/DDG), state (state directors) or LGA (LGA chiefs). Cards for later phases say "Available from Phase N".
- **System banner:** set `BUFFALO_BANNER_TEXT` (and `BUFFALO_BANNER_LEVEL`: `info`, `restricted`, `confidential` or `secret`) to show a banner at the top of every page, including sign-in and error pages.
- **Error pages:** 403, 404, 419, 500 and 503 are BUFFALO pages that never say why access was denied. Records outside a user's jurisdiction are 404.

## Audit and notifications

- All changes are recorded in `activity_log` (with IP address and browser). Passwords, 2FA secrets, encrypted fields and setup links are never recorded. `activity_log`, `record_access_logs` and `login_histories` reject `UPDATE` and `DELETE` at the database level, and `activitylog:clean` is disabled.
- `/audit` and `/login-history` are read-only viewers for super admins (and `/audit` for the Director General). Roles below national level are refused even if granted the permission.
- Officer postings (`officer_postings`) record each user's role and jurisdiction over time.
- Notifications are stored in the database and pushed live over Reverb to the private channel `users.{ulid}`. Mail is sent only when `BUFFALO_MAIL_ENABLED=true` and the notification allows it. Notifications are queued, so Horizon must be running.

## Reference data

States and geopolitical zones are seeded from `database/data/zones_states.csv`. Senatorial districts, LGAs and wards come from CSV files supplied by the project owner and are loaded with `make artisan cmd="buffalo:import-hierarchy"`; see [`database/data/README.md`](database/data/README.md) for the formats and options. Until those files exist, `make fresh` in a local environment seeds clearly synthetic districts and LGAs for Lagos and Kano, and prints a warning.

## Handover notes

Each phase or step records what was built, deviations, new packages and known issues in [`docs/handover/`](docs/handover). Phase prompts are in [`docs/prompts/`](docs/prompts).
