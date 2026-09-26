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

## Lookup tables (administrator-managed reference data)

- **Tables:** incident types, threat types, security agencies, religions, denominations and property types. They are managed at **Administration → Reference data** (`/reference-data`): everyone can view them, and the super admin and Director General can manage them.
- **Never deleted:** values are deactivated instead. A deactivated value stays on existing records but can't be chosen for new ones. Codes are fixed once created.
- **Seeding:** `ReferenceDataSeeder` (part of `db:seed`) creates missing defaults only. It never changes existing values, so administrators' edits survive.
- **Incident type detail fields:** each incident type has its own fields (`details_schema`, BUFFALO's own format), edited with the field builder on the type's edit page.
  - `App\Support\DynamicFields\SchemaValidator` validates a schema. Once a type is in use, keys and types are fixed, and fields and options can only be deprecated.
  - `DetailsValidator` validates a record's `details`.
  - `DynamicFields` and `DynamicFieldsView` (`resources/js/components/dynamic-fields/`) render them.
- **Form options:** `App\Support\ReferenceData\ReferenceData::options()` returns the active values for forms. It is cached in Valkey and cleared whenever a value changes.

## Local government: LGA profiles and communities

- **`/lgas`** lists the LGAs in your jurisdiction. An LGA chief goes straight to their own. Each LGA page has three tabs:
  - **Overview:** basic facts, the current LGA chief, completeness, and "Mark as reviewed".
  - **Profile:** history, economy, terrain, roads, waterways, borders, notes, plus headquarters, area and population.
  - **Communities:** a list and a map.
- **Profile completeness** counts six items: history, economic activities, terrain, major roads, borders and at least one community.
- **Review reminders:** LGA chiefs are reminded weekly (Mondays 07:00) when their profile hasn't been reviewed for `BUFFALO_PROFILE_REVIEW_DAYS` (180) days.
- **`/communities`** searches communities across your jurisdiction. The search is fuzzy (pg_trgm), and matches other names, accents and misspellings. Each community has a detail page with a map and its change history.
- **Map picker:** communities are added with it (click, drag, or type the coordinates). A location must be in Nigeria and, once LGA boundaries are imported, inside the LGA; the database enforces the boundary rule too.
- **Deleting:** only State Directors and above can delete a community, and must give a reason (audited).
- **CSV import:** at **Communities → Import CSV** (DG, State Directors and Deputies). Download the template, upload a file for one LGA, and review the preview: every row is marked new, possible duplicate or error. New rows are imported, possible duplicates only if you tick them, and errors never. The import is one transaction; files over 200 rows run in the background and notify you when done. The uploaded file is never stored.
- **Scoping:** everything here is scoped to the user's jurisdiction. The super admin has no access. Out-of-jurisdiction LGAs and communities are 404.

## Community information and classification

- **Classification** (`CLAUDE.md` section 4a) is enforced by `App\Support\Classification\ClassificationGate`, both in queries (the `HasClassification` global scope, which applies together with the jurisdiction scope) and in policies. The levels:
  - **Restricted:** all intelligence roles.
  - **Confidential:** Deputy State Directors and above.
  - **Secret:** State Directors and above.

  A record's creator always sees it. Nobody can classify a record above their own level. Level changes are audited.
- **LGA and community pages** have three more tabs:
  - **Religious institutions:** counts by religion and type are computed; institutions can be added with the map picker and shown as a map layer.
  - **Vulnerable population:** aggregate counts only, with no names and no free text; new figures are new rows, so history is kept.
  - **Security notes:** classified, with status changes. Reading a confidential or secret note is recorded in `record_access_logs`.
- **Deleting** any of these needs State Director or above and a reason.

## Persons (Phase 3A)

> **Field encryption key.** NIN, phone number, date of birth and address of
> persons are encrypted with `CIPHERSWEET_KEY` (created once by
> `make setup`). **Losing this key makes all encrypted person data
> unrecoverable: back it up separately from the database, and never commit
> it.** See [docs/security/encryption-keys.md](docs/security/encryption-keys.md).

- **Persons** (Intelligence → Persons) are scoped to the user's jurisdiction and classified, and every view of a person is recorded in `record_access_logs`.
- **Search:** by name or alias (fuzzy, pg_trgm), by NIN, or by phone number in any format (exact match through blind indexes). Searches are POSTed, so names and numbers never appear in URLs.
- **Creating a person** first shows possible duplicates within the jurisdiction.
- **Photos** are re-encoded to remove EXIF (including GPS) and stored on the private `evidence` disk. They are only streamed through an authorised route, never by URL.
- **Aliases, roles and associations:** a person has aliases, roles (political leader, traditional ruler, religious leader, community leader), and associations with other persons, shown from both sides.
- **Links from Phase 2:** communities can name a traditional ruler, and religious institutions a leader.

## Political information, persons of interest, criminal groups (Phase 3B)

- **Political offices** are recorded on a person's page. The office type decides whether a senatorial district, LGA or ward is required. Recording an office gives the person the political leader role. A second current holder of a single seat produces a warning, not an error.
- **LGA chiefs** see offices of their LGA, plus offices with no LGA whose senatorial district contains their LGA (their senator).
- **Senatorial districts** (Local Government menu) and the LGA **Leadership** tab show current and past office holders, traditional rulers, and religious and community leaders.
- **Persons of interest** are designated from a person's page. A POI is visible only when its person is.
  - Status and risk changes always need a comment and form the POI's timeline.
  - Clearing a POI needs State Director or above.
  - Reviews fall due after `BUFFALO_POI_REVIEW_MONTHS` months. `buffalo:poi-review-reminders` runs daily and notifies LGA chiefs and State Directors of overdue reviews (counts only).
- **Criminal groups** are owned by their home LGA and are classified.
  - Areas of operation may be in any state but never make the group visible there.
  - Only persons the user can see can be added as members.
- **Views are access-logged:** POI details (context `poi`) and criminal groups.

## Audit and notifications

- All changes are recorded in `activity_log` (with IP address and browser). Passwords, 2FA secrets, encrypted fields and setup links are never recorded. `activity_log`, `record_access_logs` and `login_histories` reject `UPDATE` and `DELETE` at the database level, and `activitylog:clean` is disabled.
- `/audit` and `/login-history` are read-only viewers for super admins (and `/audit` for the Director General). Roles below national level are refused even if granted the permission.
- Officer postings (`officer_postings`) record each user's role and jurisdiction over time.
- Notifications are stored in the database and pushed live over Reverb to the private channel `users.{ulid}`. Mail is sent only when `BUFFALO_MAIL_ENABLED=true` and the notification allows it. Notifications are queued, so Horizon must be running.

## Reference data

States and geopolitical zones are seeded from `database/data/zones_states.csv`. Senatorial districts, LGAs and wards come from CSV files supplied by the project owner and are loaded with `make artisan cmd="buffalo:import-hierarchy"`; see [`database/data/README.md`](database/data/README.md) for the formats and options. Until those files exist, `make fresh` in a local environment seeds clearly synthetic districts and LGAs for Lagos and Kano, and prints a warning.

## Handover notes

Each phase or step records what was built, deviations, new packages and known issues in [`docs/handover/`](docs/handover). Phase prompts are in [`docs/prompts/`](docs/prompts).
