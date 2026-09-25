# Handover — Phase 0: Project setup and infrastructure

Date: 2026-09-25

## Summary

Phase 0 is complete. A fresh clone runs with `make setup` (verified from a clean clone in about 90 s). The stack runs PostgreSQL 18/PostGIS 3.6, Valkey, Horizon, the scheduler, Reverb, Mailpit, a Vite dev server with HMR, and an offline Leaflet/PMTiles map. `make test` (49 tests), `make lint` and `scripts/ci.sh` (via `make ci`) all pass, and the `prod` Docker target builds.

Commits:

| Step | Commit |
|---|---|
| 0.1 | `chore: create laravel application from react starter kit` |
| 0.2 | `chore(docker): add development environment` |
| 0.3 | `chore: add horizon, reverb, and code quality tooling` |
| 0.4 | `feat(map): add offline map component and tile fetch script` |
| 0.5 | `docs: add readme and infrastructure tests` |
| — | `docs: add phase 0 handover` (this file) |

## What was built

### Step 0.1 — Laravel application

- Created with the currently documented method, the Laravel installer (`laravel/installer` 5.32.0):
  `laravel new app --react --database=pgsql --phpunit --no-node --no-boost --no-interaction`
  It ran in the `composer:2.10.3` container, in a temporary directory, and was moved into the repository root. `CLAUDE.md` and `docs/` were preserved.
- Result: Laravel 13.33.0, Inertia 3, React 19, TypeScript, Tailwind 4, shadcn/ui, Fortify, Wayfinder.
- PostgreSQL is the default. The `sqlite` connection, the SQLite database file, and the `post-create-project-cmd` step that created it were removed. `phpunit.xml` uses `pgsql` / `buffalo_test`.
- Cache, session and queue default to `redis` (`REDIS_CLIENT=phpredis`, `REDIS_HOST=valkey`).
- `APP_NAME=BUFFALO`. Timezone `Africa/Lagos`, via `config/app.php` `env('APP_TIMEZONE', 'Africa/Lagos')` and `php.ini`.
- Composer `config.platform.php` is pinned to `8.4.26` and `require.php` to `^8.4`, so dependencies resolve for PHP 8.4, not the PHP 8.5 in the Composer image.
- The starter kit's authentication pages and Fortify configuration are unchanged.

### Step 0.2 — Docker environment

- `docker/php/Dockerfile`, multi-stage, from `php:8.4.26-fpm-trixie`:
  - `base`: extensions pdo_pgsql, pgsql, intl, zip, gd (FreeType/JPEG/PNG/WebP), exif, bcmath, pcntl, opcache, and redis (phpredis 6.3.0 via PECL). Composer is copied from `composer:2.10.3`. Runs as the non-root user `buffalo` with `UID`/`GID` build args (default 1000); an existing GID is reused, e.g. GID 20 on macOS. The FPM `/ping` healthcheck uses `cgi-fcgi`.
  - `dev`: Xdebug 3.5.3 installed, `XDEBUG_MODE=off` by default.
  - `ci`: `dev` plus Node.js from `node:24.21.0-trixie-slim`, used by `make ci`.
  - `vendor` → `frontend` (from `node:24.21.0-alpine3.24`) → `prod`: `composer install --no-dev`, optimised classmap, Wayfinder generation, `npm ci && npm run build`, and OPcache timestamp validation off.
- `docker/php/php.ini`, `opcache.ini`, `fpm-pool.conf`, `xdebug.ini`. `fpm-pool.conf` and `xdebug.ini` were not in the prompt's file list; they hold the FPM ping healthcheck and the Xdebug settings.
- `compose.yml`: services app, nginx, postgres, valkey, horizon, scheduler, reverb. Named volumes postgres_data, valkey_data, evidence_data (mounted in app/horizon/scheduler) and map_tiles (read-only in nginx). Healthchecks for postgres (`pg_isready -h 127.0.0.1`), valkey, app (FPM ping) and horizon (`horizon:status`). horizon/scheduler/reverb use `restart: always`. No ports are published.
- `compose.override.yml`: source mounts, ports bound to `127.0.0.1` only (nginx 8000, postgres 5432, valkey 6379, mailpit 8025, vite 5173, all configurable in `.env`), and the `mailpit` and `vite` services.
- `docker/nginx/default.conf`:
  - Laravel FPM front controller; only `index.php` is executable.
  - Reverb proxy on `^~ /app/` (WebSocket upgrade) and `^~ /apps/`. The trailing slashes stop `/appearance` and similar routes from matching.
  - `/tiles/` via `alias`, with byte ranges (`206` verified), `expires 30d`, and no CORS headers.
  - Security headers on every response (`add_header_inherit merge`), `client_max_body_size 100M`, hidden files denied, and `server_tokens off`.
  - Upstreams resolve per request through Docker DNS (`resolver 127.0.0.11`), so restarted containers keep working.
- `docker/postgres/init/01-extensions.sql` and `02-test-database.sql`.
- `evidence` disk: `local`, root `EVIDENCE_ROOT` (default `/var/lib/buffalo/evidence`), `visibility` private, `serve` false, `throw` true.
- `.env.example` documents every variable; `.dockerignore`; `Makefile` with all requested targets plus `wayfinder`, `ci` and `help`.

### Step 0.3 — Infrastructure packages and code quality

- Horizon 5.50.0 at `/horizon`. The `viewHorizon` gate returns `app()->environment('local')`; Phase 1 replaces it with `super_admin`.
- Reverb 1.12.0 with broadcasting connection `reverb`:
  - Laravel publishes to `reverb:8080` inside the Docker network. The browser connects through nginx using `VITE_REVERB_HOST/PORT/SCHEME` (`localhost:8000`, `http`).
  - `REVERB_ALLOWED_ORIGINS` (default `localhost,127.0.0.1`) replaces the default `allowed_origins: ['*']`. A foreign Origin is rejected; verified.
  - The Echo client is in `resources/js/lib/echo.ts` and only enables `ws`/`wss` transports, so pusher-js never loads fallbacks from `js.pusher.com`.
- Pest 5.2.1 with the Laravel plugin. All 12 starter-kit test files were converted with `pestphp/pest-plugin-drift`, which was then removed. `RefreshDatabase` is applied to `tests/Feature` in `tests/Pest.php`.
- Larastan 3.12.2 at level 6 (`phpstan.neon`). Pint 1.32.1 with the `laravel` preset (`pint.json`).
- ESLint 10 (flat config: `@eslint/js`, `typescript-eslint`, `eslint-plugin-react-hooks`, `eslint-config-prettier`) and Prettier 3 (`.prettierrc.json`, Tailwind class sorting). TypeScript `strict` was already on.
- npm scripts: `lint`, `lint:fix`, `format`, `format:check`, `typecheck`, `build`, `dev`.
- `scripts/ci.sh`, in order: Composer install, npm ci, build, Pint, Larastan, ESLint, Prettier, typecheck, idempotent creation of `buffalo_test` plus extensions (CI service containers can't run our init scripts), Pest. It stops at the first failure.
- `.github/workflows/ci.yml` runs only `scripts/ci.sh`, with `postgis/postgis` and `valkey/valkey` service containers. `make ci` runs the same script locally in the `ci` image against a clean copy of the working tree.

### Step 0.4 — Offline assets and map

- Removed external resources:
  - The Bunny Fonts loader (`laravel-vite-plugin/fonts` `bunny()` and the `@fonts` Blade directive).
  - The "Repository" and "Documentation" nav links in the sidebar and header.
  - The starter-kit welcome page, which linked to laravel.com, laracasts.com and cloud.laravel.com. It is now a minimal BUFFALO landing page with the same Log in / Register / Dashboard links.
  - Horizon's Bunny Fonts `<link>`s, via an override at `resources/views/vendor/horizon/layout.blade.php`.
- Inter comes from `@fontsource-variable/inter`, imported in `resources/css/app.css`. Tailwind `--font-sans` is `'Inter Variable'`.
- Map: `leaflet` 1.9.4, `protomaps-leaflet` 5.1.0 and `pmtiles` 3.2.1, with no `react-leaflet`.
- `resources/js/components/map/BuffaloMap.tsx`:
  - Creates the map in a ref'd div and removes it on unmount.
  - Takes `center` (default `[9.08, 8.68]`), `zoom` (default 6) and `className`.
  - `maxBounds` is Nigeria's bounding box plus 10%, with `maxBoundsViscosity` 1.
  - Reads the PMTiles header first. If `/tiles/nigeria.pmtiles` is missing or unreadable, the map keeps a plain background and shows "Map tiles not installed — run `make tiles`."
  - Leaflet CSS and marker images are bundled. The marker URLs are set explicitly because Leaflet's CSS-based path detection breaks with Vite's hashed filenames.
  - Uses the light or dark Protomaps flavour to match the app theme.
  - Attribution is plain text ("Protomaps © OpenStreetMap contributors"), with no external links.
  - Constants live in `resources/js/components/map/nigeria.ts`, which keeps React Fast Refresh working for the component.
- **protomaps-leaflet assets:** labels are drawn on canvas with the system `sans-serif` font. The library only fetches fonts or sprites if you call its `Font()` or `Sheet` helpers with URLs, and BUFFALO does not. No extra assets need bundling.
- The dashboard shows the map full width and 500px tall below the three placeholder tiles.
- `scripts/fetch-map-tiles.sh` runs `protomaps/go-pmtiles:v1.31.2 extract <url> --bbox=2.6,4.2,14.7,13.9 --maxzoom=14` into the `buffalo_map_tiles` volume. It writes a `.partial` file and then renames it. It prints usage (including https://maps.protomaps.com/builds/) when no URL is given. Its header states that it is the only part of the system allowed to use the internet. `*.pmtiles` is git-ignored.

### Step 0.5 — Health check and documentation

- `tests/Feature/Infrastructure/HealthCheckTest.php`: `/up` returns 200. It was also confirmed through nginx with `curl`.
- `EvidenceDiskTest.php`: the disk is private, `serve` is false and it has no `url`. Its root is outside `public/` and outside the app tree. No `filesystems.links` entry targets it, and no symlink under `public/` resolves into it.
- `DatabaseExtensionsTest.php`: the driver is `pgsql`, and `postgis`, `pg_trgm` and `unaccent` are installed.
- `README.md`.

## Files created or changed

- **Created:**
  - Docker: `compose.yml`, `compose.override.yml`, `.dockerignore`, `Makefile`, `docker/**`.
  - Scripts and CI: `scripts/ci.sh`, `scripts/fetch-map-tiles.sh`, `.github/workflows/ci.yml`.
  - Frontend tooling: `eslint.config.js`, `.prettierrc.json`, `.prettierignore`.
  - Laravel config and providers: `config/{broadcasting,horizon,reverb}.php`, `routes/channels.php`, `app/Providers/HorizonServiceProvider.php`, `resources/views/vendor/horizon/layout.blade.php`.
  - Frontend source: `resources/js/lib/echo.ts`, `resources/js/components/map/{BuffaloMap.tsx,nigeria.ts}`.
  - Tests: `tests/Pest.php`, `tests/Feature/Infrastructure/*`.
  - Docs: `README.md`, `docs/prompts/phase-0.md`, this file.
- **Changed from the starter kit:**
  - Backend config: `.env.example`, `.gitignore` (removed the `/CLAUDE.md` ignore; added `*.pmtiles`), `composer.json`/`.lock`, `package.json`/`-lock`, `phpunit.xml`, `phpstan.neon`, `vite.config.ts`, `config/{app,cache,database,filesystems,queue,session}.php`, `bootstrap/{app,providers}.php`.
  - Frontend: `resources/css/app.css`, `resources/views/app.blade.php`, `resources/js/{app.tsx,types/global.d.ts,types/vite-env.d.ts}`, `resources/js/components/{app-header,app-sidebar}.tsx`, `resources/js/pages/{welcome,dashboard}.tsx`, `resources/js/pages/settings/profile.tsx` (formatting only).
  - Tests: all starter-kit tests, converted to Pest.
- **Removed:** `.github/workflows/tests.yml` (the kit's SQLite/PHP 8.5 workflow, replaced by `ci.yml`) and `database/database.sqlite`.

## Deviations from the prompt, and why

1. **No `docs/prompts/` directory existed.** The repository contained only `CLAUDE.md`. I saved this phase prompt as `docs/prompts/phase-0.md` and ran `git init` (branch `main`).
2. **The starter kit no longer ships ESLint/Prettier.** The 2026 kit uses `vite-plus` 0.3.0 (pre-1.0), whose `vp` command wraps Vite, oxlint and oxfmt. `CLAUDE.md` fixes ESLint, Prettier and `tsc`, so `vite-plus` was removed:
   - `npm run dev`/`build` now call plain Vite 8.
   - ESLint and Prettier were added, with Prettier options matching the kit's oxfmt settings.
   - The kit's Composer scripts `setup`, `dev` and `ci:check`, which called the removed `vp` scripts or duplicated the Docker workflow, were removed.
3. **ESLint 10 instead of 9, without `eslint-plugin-react`.** npm reports ESLint 9 as end-of-life, and `eslint-plugin-react` 7.37.5 doesn't support ESLint 10. TypeScript and `eslint-plugin-react-hooks` 7 (which includes React Compiler rules) cover what matters.
4. **Larastan is at level 6, as the prompt specifies.** The kit shipped level 7. Raising it back is a one-line change if you prefer.
5. **Guzzle was downgraded from 8.2.0 to 7.15.5, and `guzzlehttp/psr7` from 3.1.0 to 2.13.1.** Reverb 1.12.0, through `pusher/pusher-php-server` 7.3.0, requires `psr7` ^2.6. Laravel 13 accepts both.
6. **Wayfinder in Docker.** The kit's Vite plugin runs `php artisan wayfinder:generate`, but the `node` image has no PHP. The plugin command is now read from `WAYFINDER_COMMAND`, which is `true` (a no-op) in the `vite` container. Routes are generated in the app container by `make setup`, `make up` and `make wayfinder`. **After changing routes or controllers, run `make wayfinder`.** Outside Docker (CI, `make ci`) the plugin behaves normally.
7. **PostGIS runs as `linux/amd64` on Apple Silicon.** `postgis/postgis` publishes no arm64 images, so `compose.yml` sets `platform: linux/amd64` and Docker emulates it.
8. **PostGIS tag precision.** `postgis/postgis:18-3.6-alpine` is the most specific tag published; there are no patch-level tags. The digest is recorded below. Pin by digest if exact reproducibility is required.
9. **Extra files and targets:** `docker/php/fpm-pool.conf`, `docker/php/xdebug.ini`, the Dockerfile `ci` target, and the `make ci`, `make wayfinder` and `make help` targets.
10. **Welcome page replaced.** It isn't an authentication page, but it was full of external links.
11. **Echo uses `@laravel/echo-react`,** which `php artisan install:broadcasting --reverb` installs by default, along with `laravel-echo` and `pusher-js`.

## New packages and licences

Packages named in the prompt: `laravel/horizon`, `laravel/reverb`, `pestphp/pest`, `pestphp/pest-plugin-laravel`, `leaflet`, `protomaps-leaflet`, `pmtiles`, `@fontsource-variable/inter`, and `eslint`/`prettier`. The others are listed with a reason.

### Composer

| Package | Version | Licence | Why |
|---|---|---|---|
| laravel/horizon | v5.50.0 | MIT | Named |
| laravel/reverb | v1.12.0 | MIT | Named. Pulls in `pusher/pusher-php-server` 7.3.0 (MIT), `laravel/sentinel` 1.1.0 (MIT) and the ReactPHP libraries (MIT). |
| pestphp/pest | v5.2.1 | MIT | Named |
| pestphp/pest-plugin-laravel | v5.0.1 | MIT | Named |
| pestphp/pest-plugin-drift | v5.0.0 | MIT | Temporary: converted the tests to Pest, then removed. Not installed. |

Already in the kit and unchanged: laravel/framework v13.33.0, inertiajs/inertia-laravel v3.4.0, laravel/fortify v1.40.0, laravel/wayfinder v0.1.21, laravel/tinker v3.0.2, laravel/chisel v0.1.1, larastan/larastan v3.12.2, laravel/pint v1.32.1, laravel/pail v1.2.7, laravel/pao v1.1.5, laravel/sail v1.68.0 (unused; our own Docker setup replaces it), fakerphp/faker v1.24.1, mockery/mockery 1.6.15, nunomaduro/collision v8.9.5.

### npm

| Package | Version | Licence | Why |
|---|---|---|---|
| leaflet | 1.9.4 | BSD-2-Clause | Named |
| protomaps-leaflet | 5.1.0 | BSD-3-Clause | Named. Transitive dependencies: @protomaps/basemaps (BSD-3), @mapbox/vector-tile (BSD-3), pbf (BSD-3), @mapbox/point-geometry (ISC), potpack (ISC), rbush (MIT), color2k (MIT). |
| pmtiles | 3.2.1 | BSD-3-Clause | Named. Pinned to 3.x (not 4.5.0) so it dedupes with the version protomaps-leaflet 5.1.0 requires (^3.1.0). |
| @types/leaflet | 1.9.22 | MIT | TypeScript types for Leaflet |
| @fontsource-variable/inter | 5.3.0 | **OFL-1.1** | Named. Not MIT/BSD/Apache/ISC, but the prompt names it. OFL permits bundling and self-hosting. |
| @laravel/echo-react | 2.5.0 | MIT | Echo client, the Reverb installer's default |
| laravel-echo | 2.5.0 | MIT | Echo core, required by the above |
| pusher-js | 8.6.0 | MIT | Reverb speaks the Pusher protocol; Echo's client transport |
| eslint | 10.11.0 | MIT | Named |
| @eslint/js | 10.0.1 | MIT | ESLint recommended rules |
| typescript-eslint | 8.70.1 | MIT | TypeScript support for ESLint |
| eslint-plugin-react-hooks | 7.1.1 | MIT | Hooks and React Compiler rules |
| eslint-config-prettier | 10.1.8 | MIT | Turns off ESLint rules that conflict with Prettier |
| globals | 17.12.0 | MIT | Browser and Node globals for ESLint |
| prettier | 3.9.9 | MIT | Named |
| prettier-plugin-tailwindcss | 0.8.1 | MIT | Tailwind class ordering; replaces the kit's oxfmt `sortTailwindcss` |

Removed: `vite-plus` 0.3.0 (see deviation 2).

Key existing versions: react 19.3.0, @inertiajs/react 3.7.1, vite 8.3.1, tailwindcss 4.3.3, typescript 5.9.3, laravel-vite-plugin 3.2.0, @laravel/vite-plugin-wayfinder 0.1.10.

## Docker images (exact tags and digests)

| Image | Used in | Digest (at time of pull) |
|---|---|---|
| `php:8.4.26-fpm-trixie` | Dockerfile base | `sha256:cf32e68365ffa74d326a5cbb2f5889035edaafbf646a56b82e1082dbcf10e8a6` |
| `composer:2.10.3` | Dockerfile, Composer binary | `sha256:9715c7f69044da2a212a5fbde29ee7da24e364d426560ae6367b060236f847d7` |
| `node:24.21.0-alpine3.24` | `vite` service, Dockerfile `frontend` | `sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1` |
| `node:24.21.0-trixie-slim` | Dockerfile `ci` (Node for glibc) | — |
| `nginx:1.30.5-alpine3.24` | nginx (stable) | `sha256:bf3201ab56f23e5954646379c775d511fc466e9f11376d9725361064ad07ed35` |
| `postgis/postgis:18-3.6-alpine` | postgres, CI service (PostgreSQL 18.6, PostGIS 3.6.4) | `sha256:ffcf0c4b904e41b9779f8098007fb5a9484025319c18c70cf8e1bcebb742b9b7` |
| `valkey/valkey:9.1.2-alpine3.24` | valkey, CI service | `sha256:48332870af354a799964c0012ae1194a0bf2bf894eb508f945810596dc2d8d11` |
| `axllent/mailpit:v1.31.2` | mailpit (dev) | `sha256:74d609a42ec279aa63c6b4622a6fa9b5408d1ad5b1d76a1c4be40a265ce0863d` |
| `protomaps/go-pmtiles:v1.31.2` | `scripts/fetch-map-tiles.sh` | `sha256:06574f01f55a78f78f887bc7ebf729a5c093c0d6e17d9876300cfcb0758b59d3` |
| `alpine:3.24.2` | `scripts/fetch-map-tiles.sh` (atomic rename) | `sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6` |

PECL: `redis-6.3.0`, `xdebug-3.5.3`. The GitHub Actions steps are pinned by commit SHA; Composer is 2.10.3.

## External-URL check (Step 0.4.3)

- Sources: `grep -rnoE "https?://[a-zA-Z0-9.-]+" resources public` (excluding `localhost`, `127.0.0.1` and `www.w3.org`) now finds only the source-code comment in `resources/js/hooks/use-clipboard.ts` (`usehooks-ts.com`, credit for the code). The minifier strips it.
- Built bundle: `grep -rhoE "https?://..." public/build` after `npm run build` finds only strings that are never requested with BUFFALO's configuration:
  - SVG/XML/MathML namespace URIs (`www.w3.org`).
  - Documentation links inside error messages (react.dev, simplewebauthn.dev, pusher.com docs).
  - Library default attribution strings (Leaflet, Protomaps, OpenStreetMap), which BUFFALO overrides.
  - pusher-js CDN constants (`js.pusher.com`, `sockjs.pusher.com`), used only by the fallback transports we disable, and `stats.pusher.com`, used only when stats are enabled, which they aren't.
  - A `tailwindcss.com` licence banner, and `http://example.com` as a base for URL parsing inside protomaps-leaflet.
- Runtime proof: in Chrome, after logging in and loading the dashboard (map with tiles, Echo connected), `performance.getEntriesByType('resource')` listed only `localhost:8000` and `localhost:5173`. The Horizon page has no external links after the layout override.

## Acceptance checklist

| Item | Result |
|---|---|
| `make setup` on a clean clone brings up the full stack | ✅ Fresh `git clone` into a scratch directory under a separate Compose project with empty volumes: exit 0 in about 90 s. All services healthy, migrations run, 49 tests pass, lint passes. (This machine needed `FORWARD_VALKEY_PORT=6380` because another project's Redis holds 6379; see known issues.) |
| App at http://localhost:8000; login and registration pages render | ✅ `/login` and `/register` return 200 and render in the browser; logged in successfully. |
| Vite HMR works without reload | ✅ Added text to `dashboard.tsx`: it appeared in the browser, and a `window` flag set beforehand survived (no reload). Vite logged `hot updated`. |
| Mailpit UI and test email from Tinker | ✅ UI 200 at :8025. A `Mail::raw` sent from Tinker appears in the Mailpit API/UI. |
| Horizon at `/horizon` processes a dispatched job | ✅ Dashboard 200. A queued job (`Illuminate\Broadcasting\AnonymousEvent`) is shown as `completed`. |
| Reverb: broadcast received by the browser through nginx | ✅ Echo in the browser connected via `ws://localhost:8000/app/…`. A queued `Broadcast::on('buffalo-smoke')->as('smoke.test')->send()` from Tinker was processed by Horizon and received in the browser (`{"msg":"hello from horizon"}`). |
| Map renders with tiles after `make tiles`; notice without them | ✅ Without tiles: plain background plus the notice. After `make tiles url=https://build.protomaps.com/20260923.pmtiles` (575 MB transferred, 523 MB file): labelled basemap renders, no notice. |
| Zero requests to external hosts | ✅ The resource list contained only `localhost:8000` and `localhost:5173` (see above). |
| postgis, pg_trgm, unaccent in `buffalo` and `buffalo_test` | ✅ Both databases: `pg_trgm, plpgsql, postgis, unaccent`. Also covered by `DatabaseExtensionsTest`. |
| Postgres and Valkey bound to 127.0.0.1 only | ✅ `docker compose ps`: `127.0.0.1:5432->5432`, `127.0.0.1:6379/6380->6379`. Same for nginx, Mailpit and Vite. |
| Every Docker image pinned | ✅ See the table above. PostGIS has no patch-level tags (deviation 8). The only unpinned name is the locally built `buffalo-app:dev`. |
| `make test` and `make lint` pass | ✅ 49 tests (152 assertions). Pint, Larastan level 6, ESLint (0 warnings), Prettier and `tsc` are all clean. |
| `scripts/ci.sh` passes locally | ✅ Via `make ci`, in the `ci` image against a clean copy of the tree. |
| `prod` Docker target builds | ✅ `docker build --target prod`. It runs as `buffalo`, includes `public/build`, and has no dev dependencies, no Xdebug, and `opcache.validate_timestamps=0`. |

## Known issues and notes

- **Port conflicts.** On the development machine, another project's Redis container already binds host port 6379, so the local `.env` uses `FORWARD_VALKEY_PORT=6380`. `.env.example` keeps 6379. All host ports are configurable in `.env`.
- **PostGIS emulation** on Apple Silicon makes the database slower than native. First initialisation takes about 20–30 s.
- **Horizon view override.** `resources/views/vendor/horizon/layout.blade.php` is a copy of Horizon v5.50.0's layout. Re-sync it when upgrading Horizon.
- **First Vite visit.** The first page load after `npm ci` triggers Vite dependency optimisation and one automatic full reload.
- **Development credentials.** `.env.example` ships development-only DB (`buffalo`/`buffalo`) and Reverb (`buffalo-dev-key`/`buffalo-dev-secret`) credentials. Valkey has no password. The DB app user is the Postgres superuser, which the init scripts need to create extensions. Phases 8/9 must replace all of these and use a non-superuser application role.
- **Dev data.** A local development user `dev@buffalo.local` was created in this machine's `buffalo` database during verification. It is not seeded or committed.
- **Starter-kit leftovers.** `pnpm-workspace.yaml` and `laravel/sail` remain from the kit and are unused. The kit's passkey support (`@laravel/passkeys`, `laravel/passkeys`) is part of the authentication Phase 1 will rework.
- **Mailpit.** Runs with `MP_DISABLE_VERSION_CHECK` and `MP_BLOCK_REMOTE_CSS_AND_FONTS`, so its UI makes no external requests.

## For the next phase

- Authentication (Fortify, 2FA, passkeys, registration) is untouched and ready for Phase 1.
- Replace the Horizon gate in `app/Providers/HorizonServiceProvider.php` with the `super_admin` check.
- `routes/channels.php` has only the default user channel. Jurisdiction-aware channel authorisation belongs to later phases.
- New map layers: extend `BuffaloMap` with props or children; the Leaflet map instance is created once in its effect. Nigeria constants are in `components/map/nigeria.ts`.
- Evidence uploads (Phase 5) must use `Storage::disk('evidence')` and stream files through authorised controllers. `EvidenceDiskTest` guards against public exposure.
- After adding or changing routes, run `make wayfinder` so the frontend route helpers stay in sync.
