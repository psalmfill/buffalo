# Handover — Phase 1E: App shell, navigation and placeholder dashboards

Date: 2026-09-25

## Summary

BUFFALO now has its own shell. It includes:

- shared user data with no numeric ids;
- a configuration-driven, permission-aware sidebar;
- a header with the user's jurisdiction;
- an optional classification banner;
- a dashboard for each role, backed by real scoped queries;
- BUFFALO error pages;
- empty states.

This completes Phase 1.

`make test` (445 Pest tests with 2590 assertions, plus 5 Vitest tests), `make lint` and `make ci` pass. The Phase 1 completion checklist passed on a **fresh clone**. The checklist found two issues, both fixed (see "Fixes found by the completion checklist").

Commits:

| Step | Commit |
|---|---|
| 1E.1–1E.3 | `feat(shell): share user data, add configuration-driven navigation and system banner` (one commit: the banner is shared data from 1E.1 and is drawn by the layouts rebuilt in 1E.2) |
| 1E.4 | `feat(dashboard): add role-specific dashboards` |
| 1E.5 | `feat(shell): add error pages and empty states` |
| — | `fix(setup): seed roles and reference data during make setup` (checklist) |
| — | `fix(shell): neutral link on error pages when the session is unknown` (checklist) |
| — | `docs: add phase 1e handover and phase 1 summary` (this file, the summary, README, prompt) |

The 1E.6 tests were written with each step.

## Phase 1 completion checklist

The checklist ran against a fresh clone of this repository in a separate Compose project (`buffalo-fresh`). It served the app on `127.0.0.1:8100`, so its cookies didn't mix with the dev stack's. Most of the walkthrough used a scripted HTTP client (`scratchpad/phase1_walkthrough.py`, not committed). Each simulated user had its own cookie jar, real CSRF tokens and real TOTP codes computed from the secret enrolled over HTTP. The client drove the same routes, middleware and policies as the UI, and all 51 checks passed. Visual checks and the live-notification and network checks were done in Chrome.

| Item | Result |
|---|---|
| Fresh clone: `make setup`, `buffalo:create-super-admin`, log in, enrol 2FA, reach the super admin dashboard | ✅ After the setup fix. `make setup` completed, but the database had **0 zones, states and LGAs**: setup ran migrations and no seeders, so no state or LGA user could be created. **Fixed:** `make setup` now runs `db:seed`, which is idempotent and never creates accounts. After seeding: `buffalo:create-super-admin` (password piped in), then sign-in → redirected to `/two-factor/setup` → enrolment (confirm password, enable, secret, confirm code, "saved") → `dashboard/system`. A second sign-in with a TOTP code also landed on the dashboard. |
| Super admin creates one user per role (DDG with and without zones); each completes setup and sees the correct dashboard, navigation and label | ✅ Created over HTTP:<br>- DG;<br>- national DDG;<br>- DDG for North West + North East;<br>- Lagos state director and deputy;<br>- Lagos LGA chief;<br>- Kano state director and LGA chief.<br><br>Each was given a "show once" setup link, set a password and enrolled 2FA.<br><br>Labels seen: "National", "North East Zone, North West Zone", "Lagos State", "Demo LGA LA-1-1 LGA, Lagos State", "Kano State".<br><br>Navigation:<br>- DG: Dashboard, Users, Audit log;<br>- DDG and state roles: Dashboard, Users;<br>- LGA chiefs: Dashboard only;<br>- super admin: all five items.<br><br>The zoned DDG dashboard lists its zones (13 states, 12 LGAs). The Lagos director's LGA list holds only Lagos LGAs, with the new chief in post and the rest "Vacant". |
| A Lagos state director cannot reach any Kano user by URL (404) | ✅ Kano state director and Kano LGA chief by ULID: 404 with the `errors/status` page. The user list shows no Kano users. |
| Notifications arrive live; lockout notifies super admins | ✅ Scripted: 5 wrong passwords for the Lagos chief raised the admin's unread count from 0 to 1. **Live in Chrome:** with the admin's dashboard open, locking the Kano chief moved the bell from 1 to 2 with no reload. |
| Every action in 1D appears in the audit log; no audit, login history or access log row can be modified | ✅ The admin performed each 1D action over HTTP: edit profile, change role and jurisdiction, deactivate, reactivate, unlock, reset 2FA and issue a setup link. `/audit?event=` found `created`, `updated`, `role_jurisdiction_changed`, `deactivated`, `reactivated`, `unlocked`, `two_factor_reset` and `setup_link_issued`, with no setup link or token anywhere in the entries. In psql, `UPDATE`, `DELETE` and `TRUNCATE` on `activity_log`, `login_histories` and `record_access_logs` (each holding at least one row) were **all rejected** by the triggers ("… is append-only"). |
| Browser network tab shows zero external requests on every page | ✅ Fresh instance: `/dashboard`, `/users`, `/users/create`, a user detail (activity tab), `/audit`, `/login-history`, `/notifications`, the settings pages and a 404 each had 0 non-local resources (Performance API). Chrome's network log for the session: 500 requests, all to `127.0.0.1:8100` (app), `localhost:5183` (Vite dev server) or a `data:` URI. Map with tiles (dev instance, Kano state director): 7 tile requests to `/tiles/…`, 0 external. |
| `make test`, `make lint` and `make ci` pass | ✅ `make ci` now also runs Vitest. |

The scratch stack and its volumes were removed afterwards (`docker compose down -v` for `buffalo-fresh` only). The dev stack was not touched.

## What was built

### 1E.1 — Shared Inertia data

- **`HandleInertiaRequests` now shares:**
  - `auth.user`, from `CurrentUserPresenter`: `ulid`, `name`, `email`, `role` {value, label}, `jurisdiction` {level, state {ulid, name}, lga {ulid, name}, zones [names]} and `jurisdictionLabel`;
  - `auth.permissions`: sorted permission names;
  - `app` {`name`, `banner`, `sessionIdleMinutes`}, via `HandleInertiaRequests::appData()`, which error pages reuse;
  - `notifications.unreadCount` and `sidebarOpen` (unchanged).

  The old `name`, `session.lifetime` and `auth.can` props were removed.
- **One label format everywhere:** `JurisdictionLabel` produces "System", "National", "North East Zone, North West Zone", "Lagos State" and "Ikeja LGA, Lagos State". The FCT reads "Federal Capital Territory", with no "State" suffix. It is used by `User::jurisdictionLabel()` and the posting labels.
- **Frontend:**
  - `useAuth()` returns `{user, permissions, can}`; `useCurrentUser()` is for app-shell components;
  - `can(permissions, permission)` lives in `lib/permissions.ts`;
  - types are in `types/auth.ts`, including `PERMISSIONS`, which mirrors `App\Enums\Permission` (tested), and `types/app.ts`.
- **Tests:** shared props (and page props) contain no key named `id` or ending in `_id`, for every role and for guests.

### 1E.2 — Navigation and header

- **`resources/js/config/navigation.ts`:** `NAVIGATION` lists sections, and each item has a title, a lucide icon, a Wayfinder route, the required permission and an optional `external` flag.
  - Each item is one line (the table is exempt from Prettier with `// prettier-ignore`, so adding an item stays a one-line change).
  - `visibleNavigation(permissions)` filters items and drops empty sections.
  - Items: Overview → Dashboard; Administration → Users (`users.view`), Audit log (`audit.view`), Login history (`login_history.view`), Queue monitor (`system.horizon`, opens Horizon in a new tab).
- **Header** (`app-sidebar-header.tsx`): sidebar toggle, BUFFALO logo placeholder and name, breadcrumbs on large screens, the jurisdiction label, the notification bell and the user menu (name, email, role, Settings, Log out).
- **Sidebar:** navigation only.
- **Removed:** unused starter-kit shell files (`app-header`, `app-header-layout`, `nav-user`, `nav-footer`, and the split and card auth layouts).

### 1E.3 — System banner

- **Settings:** `BUFFALO_BANNER_TEXT` and `BUFFALO_BANNER_LEVEL` (`config/buffalo.php`, `.env.example`). No text means no banner. The level is case-insensitive, and an unknown level falls back to `info` rather than breaking every page.
- **Placement:** the `SystemBanner` component is a thin (1.75 rem), sticky, full-width bar at the top of app, sign-in and error pages. Its colours are solid and identical in light and dark mode:

  | Level | Colours |
  |---|---|
  | info | white on slate-700 |
  | restricted | amber-950 on amber-300 |
  | confidential | white on blue-700 |
  | secret | white on red-700 |

  A `--banner-h` CSS variable moves the fixed sidebar and the full-height containers below the banner.

### 1E.4 — Dashboards

**Route and access:** `GET /dashboard` → `DashboardController`, authorised by the `viewDashboard` gate. The gate requires a role whose jurisdiction data is consistent (`JurisdictionRules`), so it fails closed. It then renders one of four pages, each built by an Action in `app/Actions/Dashboard`:

| Role | Page | Content |
|---|---|---|
| super_admin | `dashboard/system` | - users by role, plus a "No role" row (through the jurisdiction scope);<br>- users by status (active, inactive, locked, setup pending), using the same `UserStatusFilter` as the `/users` filter, with links to the filtered list;<br>- failed sign-ins in 24 h;<br>- lockouts in 7 days;<br>- the last 10 audit events (only with `viewAudit`);<br>- Horizon status (running, paused or inactive, as `horizon:status` reports it, via `QueueHealth`).<br><br>No map, no placeholders. |
| DG, DDG | `dashboard/national` | Header ("National overview" or "Zonal overview" with the assigned zones), state and LGA counts for the jurisdiction (`JurisdictionRules`), 4 placeholder cards and the map. |
| state_director, deputy_state_director | `dashboard/state` | State header (zone, LGA count, vacancies), 4 placeholder cards, the map, and the state's LGAs with the LGA Security Chief from **open officer postings** (read through the jurisdiction scope) or "Vacant". Chief names link to the user page for `users.view` holders. |
| lga_security_chief | `dashboard/lga` | LGA header, 4 placeholder cards, LGA details (senatorial district, headquarters, area, population estimate; "Not recorded" if empty) and the map. |

- **Placeholder cards** come from `PlaceholderCards::for($scope)`:
  - Incidents (last 30 days): Phase 5;
  - Active flashpoints: Phase 6;
  - Pending escalations: Phase 5;
  - Cross-state access requests: Phase 3.

  They are rendered by the single `PlaceholderCard` component, which shows "Available from Phase N" until a phase passes real content as children.
- **Map:** `MapView::for()` centres on the state's or LGA's `centroid` (zoom 8 or 10) when one exists, and otherwise gives the national view. Centroids are empty for now, so every map shows the national view (a test covers the centroid case).

### 1E.5 — Error pages and polish

- **Error pages:** `App\Http\Responses\ErrorPage`, registered in `bootstrap/app.php`, renders `errors/status` for 403, 404, 419, 500 and 503:
  - the messages are generic and never give a reason ("Your session expired, please sign in again." for 419);
  - the page has its own layout, with the banner;
  - it offers a dashboard link, a sign-out button on 403, or a sign-in link.
- **Error page exceptions:**
  - JSON callers keep JSON errors;
  - with `APP_DEBUG` on, a 500 keeps Laravel's debug page;
  - Inertia visits get an Inertia error response.
- **Out-of-jurisdiction records are 404:** the jurisdiction scope hides them from route model binding. This is tested on user detail, and the page shows nothing about the record.
- **Page titles:** "Page Name — BUFFALO".
- **Empty states:** an `EmptyState` component on the users, audit, login history, postings and notifications tables, and the state dashboard's LGA list.
- **Loading states:** Inertia's progress bar was already configured (`app.tsx`), and is unchanged.

## Fixes found by the completion checklist

1. **`make setup` did not seed.**
   - **Symptom:** a fresh clone had no zones, states or LGAs.
   - **Fix:** `make setup` now runs `php artisan db:seed --force`; the README documents it. The seeders upsert and never create accounts. Outside `APP_ENV=local`, seeding stops with a clear message until the owner's LGA CSVs exist. That is the intended behaviour, but it means `make setup` fails in non-local environments until then.
2. **Error page for unknown URLs.**
   - **Symptom:** these are answered before the session starts, so a signed-in user was offered "Sign in".
   - **Fix:** the button is now "Continue to BUFFALO" → `/`, which sends each user to the dashboard or to sign-in.

## Deviations from the prompt

- **One commit for 1E.1–1E.3** (see the commit table).
- **`jurisdictionLabel` format changed** to the prompt's format everywhere. Audit entries written in 1D keep the old wording ("National (…)", "Ikeja LGA, Lagos"); audit records are immutable.
- **Header layout:** the brand, jurisdiction, bell and user menu are all in the header, so the sidebar no longer has a logo or user block.
- **Extra dashboard facts, all from real queries:**
  - national: state and LGA counts;
  - state: vacancy count;
  - LGA: area and population estimate;
  - super admin: a "No role" row.
- **Placeholder cards are defined server-side**, so a test can prove the super admin receives none.
- **Users without a role, or with inconsistent jurisdiction data, get a 403 from `/dashboard`** (`viewDashboard`). The starter kit's `tests/Feature/DashboardTest.php` (a role-less user seeing the dashboard) was removed; `tests/Feature/Shell/DashboardTest.php` replaces it.
- **Error handling:** 503 was added, and a 500 keeps the debug page when `APP_DEBUG` is on.
- **`make setup` seeds** (checklist fix).

## New packages

| Package | Version | Licence | Why |
|---|---|---|---|
| `vitest` (npm, dev) | ^5.0.2 | MIT | Frontend unit tests (the navigation config test required by 1E.6); no frontend runner existed. |

`vitest` pulls in these transitive dev packages:

- `@vitest/mocker`, `@vitest/spy`, `chai`, `@types/chai`, `@types/deep-eql`, `assertion-error`, `es-module-lexer`, `estree-walker`, `magic-string`, `obug`, `std-env`, `tinybench`, `tinyexec`, `why-is-node-running`: all MIT;
- `expect-type`: Apache-2.0.

Vitest runs in Node (`vitest.config.ts`, with no jsdom). It is part of `make test` and has its own CI step.

## Known issues and notes

- **Centroids and boundaries are empty** until the boundary import, so every map shows the national view.
- **Officer postings don't record a DDG's zones**, so a zoned DDG's posting reads "National" on the Postings tab.
- **`make setup`** still prints "http://localhost:8000" whatever `APP_PORT` is, and the `make ci` target assumes the Docker network `buffalo_default`. Both are cosmetic.
- **Dev-only external origin:** the Vite dev server (`localhost:5173`) is local; production serves the built assets from the app's own origin.
- **Your dev stack:** during the dashboard review I added a banner to your local `.env` and removed it afterwards. The dev database still has the 1D walkthrough accounts listed in the 1D handover.

## For Phase 2

- **Navigation:** add each Phase 2 page as one line in `resources/js/config/navigation.ts`, with its permission. If the permission is new, add it to `App\Enums\Permission`, `RolesAndPermissionsSeeder::matrix()` and `PERMISSIONS` in `types/auth.ts`. The test in `SharedPropsTest` fails until the frontend list matches the enum. Update `ROLE_PERMISSIONS` in `navigation.test.ts`; a PHP test keeps it in step with the seeder.
- **Dashboard content:** replace a placeholder by giving its card real content (`PlaceholderCard` children) and dropping it from `PlaceholderCards` when no longer needed. State and LGA dashboards should keep reading through the jurisdiction scope.
- **Empty and error states:** use `EmptyState` for empty tables. Let authorisation failures surface as 403/404 exceptions; `ErrorPage` renders them.
