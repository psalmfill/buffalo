# Handover — Phase 1B: Users, roles and jurisdiction scoping

Date: 2026-09-25

## Summary

The access-control core is in place:

- six roles with one jurisdiction level each;
- BUFFALO user columns with database-enforced jurisdiction consistency;
- `spatie/laravel-permission` with one role per user, enforced in the Action layer and by a unique index;
- a fail-closed `JurisdictionContext` / `JurisdictionScope` / `JurisdictionGate` / `JurisdictionOptions`;
- `UserPolicy`;
- user Actions;
- `buffalo:create-super-admin`.

`make test` (248 tests, 1145 assertions), `make lint` and `make ci` pass. Beyond the tests, `buffalo:create-super-admin` was run on a fresh database, and the new admin logged in over HTTP through nginx and reached the dashboard and Horizon.

Commits:

| Step | Commit |
|---|---|
| 1B.1 | `feat(users): add role, jurisdiction level and permission enums` |
| 1B.2 | `feat(users): add jurisdiction, account state and audit columns to users` (also installs both Spatie packages) |
| 1B.3 | `feat(users): add roles and permissions seeder and single-role assignment` |
| 1B.4 + 1B.5 | `feat(jurisdiction): add jurisdiction context, scope, gate and options` |
| 1B.6 | `feat(users): add user policy and restrict horizon to system.horizon` |
| 1B.7 | `feat(users): add user actions and buffalo:create-super-admin command` |
| — | `docs: add phase 1b handover` (this file, README, prompt) |

Tests for 1B.8 were written with each step and committed alongside it.

## Scope matrix (verified by `tests/Feature/Jurisdiction/JurisdictionScopeMatrixTest.php`)

Scenario (`tests/Support/Jurisdiction/JurisdictionWorld.php`): zone A = states A1 (LGAs A1a, A1b) and A2 (LGA A2); zone B = state B1 (LGA B1); zone E has no states. Records are seen from the point of view of the LGA chief of A1a.

| User | Own LGA (A1/A1a) | Same state, other LGA (A1/A1b) | Same state, state-level (A1/–) | Same zone, other state (A2/A2) | Other zone (B1/B1) |
|---|---|---|---|---|---|
| super_admin | – | – | – | – | – |
| director_general | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, no zones (national) | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, zone A | ✅ | ✅ | ✅ | ✅ | – |
| DDG, zone B | – | – | – | – | ✅ |
| DDG, zones A + B | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, zone with no states | – | – | – | – | – |
| state_director (A1) | ✅ | ✅ | ✅ | – | – |
| deputy_state_director (A1) | ✅ | ✅ | ✅ | – | – |
| lga_security_chief (A1a) | ✅ | – | – | – | – |
| inactive DG | – | – | – | – | – |
| locked DG | – | – | – | – | – |
| soft-deleted DG | – | – | – | – | – |
| user with no role | – | – | – | – | – |
| state_director role with a national jurisdiction (inconsistent) | – | – | – | – | – |

- **Contexts:** in `none` context, nothing is visible. In `system` context, everything is.
- **Gate:** `JurisdictionGate::canAccess()` returns exactly this table for every user × record pair (a separate test).
- **Users:** the same rules apply, with `visibleToSuperAdmin = true`:
  - super admins, the DG and a national DDG see all users;
  - a state director sees users whose `state_id` is their state (including that state's LGA chiefs);
  - an LGA chief sees users of their LGA;
  - a zone-restricted DDG sees users in the states of their zones;
  - blocked, role-less and inconsistent users see no users.
- **Mutation check:** I broke the rules three times (state roles unrestricted, the nested group removed, blocked users no longer blocked). The suite failed each time with 7, 1 and 5 failures, then passed again once restored.

## What was built

### 1B.1 — Enums (`app/Enums`)

- `Role` with `label()`, `jurisdictionLevel()` and `allowsZones()`.
- `JurisdictionLevel` with `label()`.
- `Permission`: every permission name. Code never uses permission string literals.

### 1B.2 — Users table and model

- Migration `2026_09_25_100001_add_jurisdiction_to_users_table`: all requested columns, soft deletes, and every FK indexed and `restrictOnDelete`.
  - **`users_jurisdiction_consistency_check`**: `system`/`national` require no state and no LGA; `state` requires a state and no LGA; `lga` requires both. Any other level value is rejected.
  - **`users_lga_same_state_foreign`**: a composite FK `(lga_id, state_id) → lgas (id, state_id)`, the same pattern as 1A.
  - `failed_login_count >= 0`.
  - Existing rows are backfilled with a ULID and `system` level. Those users have no role, so they see and can do nothing.
- `user_zones`: a pivot with a unique pair and restricted FKs.
- `User` model:
  - It **implements `JurisdictionScoped` and uses `HasJurisdiction`** (`$visibleToSuperAdmin = true`), plus `HasRoles`, `HasUlidRouteKey` and `SoftDeletes`.
  - Casts, relationships `state`, `lga`, `zones`, `creator`, `updater`, `deactivator`, and helpers `getRole()`, `hasBuffaloRole()`, `isAccessBlocked()` (inactive, soft-deleted or locked).
  - Jurisdiction, role and account-state columns are **not mass-assignable**; only the Actions set them.
  - `#[Hidden]` removes `id`, `password`, `remember_token`, all two-factor columns, `failed_login_count`, every FK ID, and the loaded `roles`/`permissions`/`pivot`. `GeopoliticalZone` also hides `pivot`.
- `UserFactory`:
  - Role states `superAdmin()`, `directorGeneral()`, `ddg($zones)`, `stateDirector($state)`, `deputyStateDirector($state)` and `lgaChief($lga)`, plus `inactive()` and `locked()`. Each produces a valid combination.
  - The default state is a role-less `system` user.

### 1B.3 — Roles and permissions

- `spatie/laravel-permission` 8.3.0 with teams off (the default). Config is at `config/permission.php`.
- `RolesAndPermissionsSeeder`:
  - `matrix()` has one clearly labelled array per module (`users`, `audit`, `system`); `permissionsByRole()` derives the per-role lists.
  - It creates all roles and permissions and **syncs** each role's permissions, which also repairs drift. It is idempotent, never touches users, and is safe in production.
  - `DatabaseSeeder` runs it first. Feature tests run it in `beforeEach` (`tests/Pest.php`).
- One role per user: `AssignRole` always calls `syncRoles`, and migration `…_enforce_single_role_per_model` adds `UNIQUE (model_id, model_type)` on `model_has_roles`, so a second role is impossible at the database level too.

### 1B.4 — Jurisdiction context (`app/Support/Jurisdiction`)

- `spatie/laravel-activitylog` 5.1.1 is installed with its default migration and config. Full audit configuration is 1D.
- `JurisdictionContext`: modes `user(User)`, `system()` and `none()`; `mode()`, `currentUser()`, `actingAs($user, $callback)` and `runAsSystem($reason, $callback)`.
  - It is bound with `scoped()` in `AppServiceProvider`, so there is a fresh instance per HTTP request and per queued job. The default is `none` for HTTP and `system` for console and queue workers.
  - `actingAs` and `runAsSystem` restore the previous mode in `finally`.
  - `runAsSystem` requires a non-empty reason and writes an activity entry (`log_name` `jurisdiction`, event `system_bypass`, properties `reason` and `previous_mode`, causer = the acting user) **before** running the callback, so it is recorded even when the callback throws. If activity logging is disabled it refuses to run.
- `SetJurisdictionContext` middleware is appended to the `web` group. It sets `user(...)` when authenticated and `none` otherwise. It is placed **before `SubstituteBindings`** in the middleware priority list (after authentication), so route-model binding of scoped models is already scoped. A test proves a state director gets a 404 for an out-of-state user's ULID.

### 1B.5 — Scope, gate and options

- **`JurisdictionRules`** is the single source of truth. It turns (user, visible-to-super-admin) into a `JurisdictionRestriction`: `Nothing`, `Everything`, `States(ids)` or `Lga(id)`. It fails closed for a blocked user, a missing role, or a role whose level doesn't match the stored jurisdiction. A DDG whose zones contain no states gets nothing, never everything.
- **`JurisdictionScope`** (applied by `App\Models\Concerns\HasJurisdiction`) always adds one nested `where (...)` group: `1 = 0` for no user, the restriction, then `AccessGrantResolver::addGrantedRecords()` inside the same group. Models declare optional `$jurisdictionStateColumn`, `$jurisdictionLgaColumn` (null means state-level records) and `$visibleToSuperAdmin`, and must implement `JurisdictionScoped`.
- **`AccessGrantResolver`** (in `Contracts/`) with `addGrantedRecords()` (adds `orWhere` clauses only) and `hasGrant()`. `NullAccessGrantResolver` is bound for now; Phase 3 replaces it.
- **`JurisdictionGate::canAccess(User, Model)`** evaluates the same restriction against a record's attributes, plus `hasGrant()`. It uses the given user explicitly (not the context) and throws for non-scoped models.
- **`JurisdictionOptions`**: `states($user)` and `lgas($user, ?State)` return only active places within the user's jurisdiction. Super admins, blocked and role-less users get none.
- **Architecture test** (`JurisdictionArchitectureTest`): scans `app`, `bootstrap`, `config`, `database` and `routes` and fails on any of these outside `app/Support/Jurisdiction`:
  - `withoutGlobalScope(...JurisdictionScope...)`;
  - `withoutGlobalScopes(` (which would remove it too);
  - switching the context to `system()` directly.

  An `arch()` rule also requires the support classes to be `final`.

### 1B.6 — Policies

- `App\Policies\UserPolicy` (auto-discovered):
  - `viewAny` needs `users.view`.
  - `view` needs `users.view` plus `JurisdictionGate`.
  - `create` needs `users.manage`.
  - `update`, `changeRole`, `deactivate`, `reactivate` and `resetTwoFactor` need `users.manage` plus `JurisdictionGate`.
  - `deactivate` also refuses self-deactivation, already-inactive targets and the last active super admin. `changeRole` refuses the last active super admin. `reactivate` only applies to inactive users.
- `Gate::before` in `AppServiceProvider` **denies every ability to blocked accounts** (inactive, soft-deleted or locked), whatever their role.
- `App\Support\Users\SuperAdmins` counts active super admins with a plain query-builder join on the role tables. The count must not depend on the viewer's context, and it returns no records. It supports `lockForUpdate` for the Actions.
- **Horizon:** the `viewHorizon` gate checks `system.horizon`, and `HorizonServiceProvider::authorization()` is overridden. Horizon's default also admits anyone when `APP_ENV=local`; now only super admins get in, in every environment (tested with the local environment).

### 1B.7 — Actions and first super admin (`app/Actions/Users`)

- **`UserJurisdiction`**: a value object holding role, state, LGA and zones. `validate()` checks, with field-keyed `ValidationException` messages:
  - role/level rules;
  - active state and LGA;
  - the LGA belongs to the given state;
  - zones only for a DDG.

  `attributes()` derives the columns; an LGA user's state is always taken from the LGA.
- **`CreateUser`**: validates the profile (email lowercased and unique, service number unique) and the jurisdiction. In one transaction it:
  - creates the user with `Str::password(64)` (unusable) and `email_verified_at` set to now (admin-created accounts);
  - sets `created_by`/`updated_by`;
  - assigns the role and syncs DDG zones.
- **`UpdateUserJurisdiction`**: validates, refuses to take the super-admin role from the last active super admin (with a row lock), updates columns, syncs the role, and clears zones for non-DDG roles.
- **`DeactivateUser`**: requires a reason of at most 1000 characters. It refuses self-deactivation, an already-inactive user, and the last active super admin (with a row lock). It records when, why and by whom, and clears the remember token. It never deletes.
- **`ReactivateUser`**: clears the deactivation fields, the lock and the failed-login count.
- **`buffalo:create-super-admin`**:
  - Makes sure roles and permissions exist (runs the seeder), so it works on a fresh production database.
  - Prompts for name and email (or takes `--name`/`--email`) and refuses an existing email, including soft-deleted and differently-cased ones.
  - Prompts for the password twice, hidden; at least 12 characters until 1C.
  - Creates the user through `CreateUser`, sets the password and `password_changed_at`, and warns about 2FA enrolment.
  - The password is never accepted as an argument.
- No seeder creates users; a test runs the whole `DatabaseSeeder` and asserts zero users.

## Files created or changed

- **Created:**
  - Enums: `app/Enums/{Role,JurisdictionLevel,Permission}.php`.
  - Jurisdiction support: `app/Support/Jurisdiction/{ContextMode,JurisdictionContext,JurisdictionRules,JurisdictionRestriction,RestrictionType,JurisdictionScope,JurisdictionGate,JurisdictionOptions,NullAccessGrantResolver,AuthenticationUserProvider}.php` and `app/Support/Jurisdiction/Contracts/{JurisdictionScoped,AccessGrantResolver}.php`.
  - `app/Support/Users/SuperAdmins.php`, `app/Models/Concerns/HasJurisdiction.php`, `app/Http/Middleware/SetJurisdictionContext.php`, `app/Http/Requests/Auth/TwoFactorLoginRequest.php`, `app/Policies/UserPolicy.php`.
  - Actions: `app/Actions/Users/{AssignRole,UserJurisdiction,CreateUser,UpdateUserJurisdiction,DeactivateUser,ReactivateUser}.php`.
  - Command: `app/Console/Commands/CreateSuperAdminCommand.php`.
  - Seeder: `database/seeders/RolesAndPermissionsSeeder.php`.
  - Config: `config/{permission,activitylog}.php`.
  - Migrations: `2026_09_25_100001_add_jurisdiction_to_users_table`, `2026_09_25_100002_create_user_zones_table`, `2026_09_25_134147_create_permission_tables`, `2026_09_25_134147_create_activity_log_table`, `2026_09_25_134148_enforce_single_role_per_model`.
  - Tests: `tests/Feature/Jurisdiction/*`, `tests/Feature/Users/*`, `tests/Support/Jurisdiction/{ScopedFixtureRecord,JurisdictionWorld}.php`.
  - Docs: `docs/prompts/phase-1b.md`, this file.
- **Changed:**
  - Models and factories: `app/Models/User.php`, `app/Models/GeopoliticalZone.php` (hide `pivot`), `database/factories/UserFactory.php`.
  - Providers: `app/Providers/AppServiceProvider.php` (context binding, grant resolver, `buffalo` auth provider, `Gate::before`, removed `uncompromised()`), `app/Providers/FortifyServiceProvider.php` (2FA request binding), `app/Providers/HorizonServiceProvider.php`.
  - `app/Actions/Fortify/CreateNewUser.php`, `bootstrap/app.php` (middleware and priority), `config/auth.php` (driver `buffalo`), `database/seeders/DatabaseSeeder.php`.
  - Tests: `tests/Pest.php`, `tests/Feature/Settings/ProfileUpdateTest.php` (the deletion test now expects a soft delete).
  - `README.md` (first super admin), `composer.json`/`.lock`.

## Deviations and decisions

1. **Authentication bypass (necessary, sanctioned, inside the support namespace).** Making `User` jurisdiction-scoped and fail-closed means login, session restore, remember-me, password reset and the 2FA challenge cannot find users in `none` mode. So:
   - `config/auth.php` uses the new `buffalo` user provider (`App\Support\Jurisdiction\AuthenticationUserProvider`), which looks users up without the scope. It only resolves the account authenticating itself.
   - Fortify's `TwoFactorLoginRequest` used `$model::find()`, so it is subclassed in `app/Http/Requests/Auth` to go through the provider.
   - The starter-kit login and 2FA tests, a new login test, and a real login over HTTP all pass.
2. **Passkey login now fails.** The passkeys package loads the user through a scoped relation. Passkeys are forbidden by `CLAUDE.md` and are removed in 1C, so I did not work around this.
3. **Registration still exists** (Fortify, starter kit) because removing it is a 1C login change. `CreateNewUser` now creates a **role-less `system`-level user**, which the scope and `Gate::before`/policies deny everything. **1C must remove registration** (`CLAUDE.md` forbids self-registration).
4. **Account self-deletion still exists** (Settings → delete account). Users are now soft-deleted, and the starter-kit test was updated accordingly. The feature should be removed with the settings rework (1C/1D), because accounts are admin-managed.
5. **`Password::uncompromised()` removed** from the production password defaults in `AppServiceProvider`. `CLAUDE.md` forbids this external call. 1C defines the final password rules.
6. **Single role enforced at the database level too** (unique index on `model_has_roles`), in addition to the Action.
7. **`Gate::before` denies blocked users globally**, and **Horizon's local-environment bypass was removed**. Both go beyond the prompt as defence in depth.
8. **`actingAs` / `runAsSystem` are instance methods**, not static ones. Use `app(JurisdictionContext::class)->actingAs(...)` or inject the context.
9. **`runAsSystem` refuses to run when activity logging is disabled**, so a bypass can never go unrecorded.
10. **Role/jurisdiction mismatch fails closed.** If stored data were ever inconsistent (for example a `state_director` role with a `national` level), the user sees nothing. `JurisdictionRules` checks this at runtime.
11. **`changeRole` policy ability added.** It is not in the prompt's list, but the last-super-admin role rule needs a policy hook for 1D. `UpdateUserJurisdiction` enforces the same rule.
12. **Admin-created accounts are email-verified** at creation (`email_verified_at` = now), because air-gapped deployments may have no mail. Revisit in 1C/1D if email verification is wanted.
13. **Extra hidden attributes on `User`**: every FK ID and the loaded role/permission relations, not only the fields listed in the prompt.
14. **The users migration backfills existing rows** as role-less `system` users, which see nothing, instead of failing.
15. **`SuperAdmins` counts via the query builder.** This isn't a scope bypass in the sense of the rule (it counts accounts and returns no records), but it's listed here for completeness.

Every place that reads users without the jurisdiction scope:

- `AuthenticationUserProvider` (inside the support namespace);
- `JurisdictionContext::runAsSystem` (audited);
- `SuperAdmins` (count only);
- the one-off migration backfill.

`grep` confirms there are no others, and the architecture test enforces this.

## New packages

| Package | Version | Licence | Why |
|---|---|---|---|
| spatie/laravel-permission | 8.3.0 | MIT | Named in the prompt: roles and permissions |
| spatie/laravel-activitylog | 5.1.1 | MIT | Named in the prompt: `runAsSystem` audit entries; full audit in 1D |

Both pull in `spatie/laravel-package-tools` (MIT). No npm changes.

## Known issues and notes for 1C

- **Login must reject blocked accounts.** Inactive, locked and soft-deleted users can still authenticate today; once in, they see nothing and every ability is denied. 1C should refuse them at login, and also maintain `failed_login_count`, `locked_until`, `last_login_at`/`last_login_ip` and `password_changed_at`.
- **1C must remove** registration, passkeys (package, routes, UI, `PasskeyUser`/`PasskeyAuthenticatable` on `User`, the `passkeys` table) and account self-deletion, and enforce mandatory TOTP 2FA (`buffalo:create-super-admin` already warns about it).
- **Password rules** are still minimum 12 characters in the command and the starter-kit defaults elsewhere. 1C defines them.
- **Sessions of deactivated users** are not destroyed (the remember token is cleared). With `Gate::before` and the scope they can do nothing, but 1C/1D should end their sessions.
- **Sync queue.** A job dispatched with the `sync` driver during an HTTP request runs inside that request's context. Real queue workers start each job in `system` mode.
- **Audit.** `CreateUser`/`UpdateUserJurisdiction`/`DeactivateUser` are not yet written to the activity log (full audit is 1D). `config/activitylog.php` still has the package default `clean_after_days = 365`, and the package's `activitylog:clean` command exists. 1D must make sure audit records are never deleted (`CLAUDE.md` section 6).
- **Your `CLAUDE.md` edits** are still uncommitted in the working tree; I left them out of my commits.

## For the next steps

- New location-bearing models: `implements JurisdictionScoped`, `use HasJurisdiction`, store `state_id` (and `lga_id`), and add the composite FK `(lga_id, state_id) → lgas (id, state_id)`.
- Policies: check the permission **and** `app(JurisdictionGate::class)->canAccess($user, $record)`.
- Queued jobs acting for a user: `app(JurisdictionContext::class)->actingAs($user, fn () => ...)`.
- Legitimate cross-jurisdiction reads (Phase 3 match check): `runAsSystem('reason', fn () => ...)`, which is logged. Access grants: implement `AccessGrantResolver` and bind it in place of `NullAccessGrantResolver`.
- Form options: `JurisdictionOptions::states()/lgas()`.
- Permissions: add `Permission` enum cases and a new labelled module array in `RolesAndPermissionsSeeder::matrix()`.
- The first account: `make artisan cmd="buffalo:create-super-admin"`.
