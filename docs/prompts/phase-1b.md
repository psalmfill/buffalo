# BUFFALO — Phase 1B: Users, Roles and Jurisdiction Scoping

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1a.md` for what already exists.

## Context

Phase 1A created the administrative hierarchy (zones, states, senatorial districts, LGAs, wards). The `users` table is still the starter kit's.

This step builds the access-control core: the six roles, user jurisdiction, and the `HasJurisdiction` scoping mechanism every later module depends on. **This is the most security-critical code in the system. Correctness and test coverage matter more than anything else in this step.** No user-management UI yet (that is 1D).

---

## Step 1B.1 — Enums

In `app/Enums`:

- `Role`: `super_admin`, `director_general`, `deputy_director_general`, `state_director`, `deputy_state_director`, `lga_security_chief`. Methods: `label()`, `jurisdictionLevel()` (returns the only level valid for that role, below).
- `JurisdictionLevel`: `system`, `national`, `state`, `lga`.

| Role | Required jurisdiction level |
|---|---|
| super_admin | system |
| director_general | national |
| deputy_director_general | national (optionally restricted to zones via `user_zones`) |
| state_director, deputy_state_director | state |
| lga_security_chief | lga |

## Step 1B.2 — Users table

Migration altering `users`:

| Column | Type / rule |
|---|---|
| `ulid` | unique, required |
| `phone` | nullable string |
| `service_number` | nullable, unique |
| `rank` | nullable string |
| `jurisdiction_level` | string, required (enum-cast) |
| `state_id` | nullable FK → states, restrictOnDelete |
| `lga_id` | nullable FK → lgas, restrictOnDelete |
| `is_active` | boolean, default true |
| `deactivated_at`, `deactivation_reason` | nullable |
| `deactivated_by` | nullable FK → users |
| `failed_login_count` | integer default 0 |
| `locked_until` | nullable timestamp |
| `password_changed_at` | nullable timestamp |
| `last_login_at`, `last_login_ip` | nullable |
| `created_by`, `updated_by` | nullable FK → users |
| soft deletes | |

Database check constraint enforcing jurisdiction consistency:

- `system` and `national`: `state_id` and `lga_id` both null
- `state`: `state_id` not null, `lga_id` null
- `lga`: both not null

Plus a constraint or trigger ensuring `lga_id` belongs to `state_id`.

Pivot `user_zones` (`user_id`, `geopolitical_zone_id`, timestamps, unique pair). Only valid for `deputy_director_general`; enforce in the Action layer and test it.

Update the `User` model: casts, relationships (`state`, `lga`, `zones`, `creator`, `deactivator`), ULID route key, `HasRoles` trait. Update `UserFactory` with states per role, e.g. `UserFactory::new()->stateDirector($state)`, `->lgaChief($lga)`, `->ddg(zones: [...])`, `->superAdmin()`, all producing valid jurisdiction combinations.

The `User` model must never serialise `id`, `password`, `remember_token`, two-factor columns or `failed_login_count` to arrays or JSON (use `$hidden`).

## Step 1B.3 — Roles and permissions

1. Install `spatie/laravel-permission` (MIT). Configure the `team` feature **off**.
2. Constraint: a user has exactly one role. Enforce in the Action layer (`AssignRole` action syncs, never adds) and with a test.
3. Permissions for Phase 1 only, seeded by `RolesAndPermissionsSeeder` (idempotent, syncs permissions per role):

| Permission | super_admin | DG | DDG | state_director | deputy_state_director | lga_chief |
|---|---|---|---|---|---|---|
| `users.view` | ✅ | ✅ | ✅ | ✅ | ✅ | |
| `users.manage` | ✅ | | | | | |
| `audit.view` | ✅ | ✅ | | | | |
| `login_history.view` | ✅ | | | | | |
| `system.horizon` | ✅ | | | | | |
| `system.settings` | ✅ | | | | | |

   `users.view` for non-super-admins means viewing users **within their jurisdiction** (enforced by policy).
4. Later phases add their own permissions by extending this seeder. Structure it so each module's permissions are defined in one clearly labelled array.
5. Permission names are referenced through a `Permission` enum or constants class, never as scattered string literals.

## Step 1B.4 — Jurisdiction context

First install `spatie/laravel-activitylog` (MIT) with its default migration. Only the basic setup is needed here, because `runAsSystem` below must write activity entries. Full audit configuration comes in 1D.

`app/Support/Jurisdiction/JurisdictionContext.php`, bound as a scoped singleton (reset per request and per job):

- Modes: `user(User $user)`, `system()`, `none()`.
- Default: in HTTP requests, `none` until middleware sets it; in console and queue, `system`.
- Middleware `SetJurisdictionContext` (appended to the `web` group after authentication) sets `user($request->user())` when authenticated.
- A queued job acting for a user calls `JurisdictionContext::actingAs($user, fn () => ...)`, which restores the previous mode afterwards (including on exceptions).
- `JurisdictionContext::runAsSystem(string $reason, Closure $callback)`: temporarily elevates to system mode inside an HTTP request. Every call writes an activity log entry recording the reason and the acting user. This is the **only** sanctioned bypass.

## Step 1B.5 — `HasJurisdiction` trait and scope

`app/Models/Concerns/HasJurisdiction.php` applying `JurisdictionScope`:

The model declares its columns (default `state_id` and `lga_id`). The scope behaves as follows based on the context:

| Context | Result |
|---|---|
| `none` | No rows (`whereRaw('1 = 0')`) |
| `system` | No restriction |
| user: `super_admin` | No rows, unless the model sets `protected bool $visibleToSuperAdmin = true` |
| user: DG, or DDG with no zones | No restriction |
| user: DDG with zones | `state_id` in the states of those zones |
| user: state roles | `state_id = user.state_id` |
| user: LGA chief | `lga_id = user.lga_id` |
| user: inactive or locked | No rows |

Requirements:

- The jurisdiction condition is wrapped in a nested `where` group so it can never be widened by an `orWhere` elsewhere in a query.
- **Grant extension point:** the scope calls an `AccessGrantResolver` interface to add "OR the record has an active grant for this user" inside the same nested group. Bind a `NullAccessGrantResolver` (adds nothing) for now. Phase 3 replaces it.
- `withoutGlobalScope(JurisdictionScope::class)` must not be used anywhere in application code. Add a Larastan rule or an architecture test (Pest `arch()`) that fails if it appears outside `app/Support/Jurisdiction`.
- A `JurisdictionGate` service with `canAccess(User $user, Model $record): bool` for single-record checks in policies. It applies the same rules as the scope (defence in depth: policies check even when the scope already filtered).
- A `JurisdictionOptions` service returning the states and LGAs a user may **select** in forms (e.g. an LGA chief gets only their LGA; a state director gets all LGAs in their state). This is used from Phase 2.

`User` itself uses a variant: users are visible to super admins (`$visibleToSuperAdmin = true`), and to others per the same rules. A DG sees all users; a state director sees users in their state.

## Step 1B.6 — Policies

- `UserPolicy`: `viewAny`, `view` (permission `users.view` + `JurisdictionGate`), `create`, `update`, `deactivate`, `reactivate`, `resetTwoFactor` (permission `users.manage`).
- Additional rules: a user cannot deactivate themselves; the last active super admin cannot be deactivated or have their role changed.
- Replace the Horizon gate with a check for `system.horizon`.

## Step 1B.7 — Actions and first super admin

Actions in `app/Actions/Users/` (no UI yet; used by 1C and 1D and by tests):

- `CreateUser`: validates role/jurisdiction combination, creates user with an unusable random password, assigns role, syncs zones for DDG. Runs in a transaction.
- `UpdateUserJurisdiction`: changes role and/or jurisdiction.
- `DeactivateUser` (requires reason) / `ReactivateUser`.

Artisan command `buffalo:create-super-admin`:

- Interactive: asks for name, email, password (hidden input, confirmed, validated against the password rules in 1C; for now min 12 characters).
- Refuses if the email exists.
- Prints that the user must enrol in 2FA at first login.
- Must work in production. **No seeder ever creates a user with known credentials.**

## Step 1B.8 — Tests

Use a test-only fixture: a migration and model created inside the test suite (e.g. `scoped_fixture_records` with `state_id`, `lga_id`) using `HasJurisdiction`. Do not add fixture tables to production migrations.

Required coverage:

- **Scope matrix:** for each role, create records in own LGA, another LGA in the same state, another state in the same zone, and another zone. Assert exactly the expected records are visible. Include DDG with and without zones.
- `none` context returns nothing; `system` returns everything; `runAsSystem` restores context and writes an activity entry even when the callback throws.
- `actingAs` in a queued job sees only the user's jurisdiction.
- A query adding `orWhere('id', '>', 0)` still cannot see out-of-jurisdiction records.
- Inactive and locked users see nothing.
- Super admin sees no fixture records, but does see users.
- `JurisdictionGate` agrees with the scope for every case in the matrix.
- Architecture test forbidding `withoutGlobalScope(JurisdictionScope::class)` outside the jurisdiction support namespace.
- Database constraints reject invalid jurisdiction combinations.
- `CreateUser` rejects mismatched role/jurisdiction and zones on non-DDG users; exactly one role per user.
- `UserPolicy` for every role, including self-deactivation and last-super-admin protection.
- `buffalo:create-super-admin` creates a working super admin and refuses duplicates.
- User JSON never contains `id`, `password`, or two-factor fields.

## Acceptance checklist

- [ ] All scope matrix tests pass, and the handover includes the matrix as a table.
- [ ] No production code bypasses `JurisdictionScope`.
- [ ] `buffalo:create-super-admin` works in a fresh database.
- [ ] `make test` and `make lint` pass.

## Out of scope

Login changes and 2FA (1C), user management UI, audit viewer, notifications (1D), navigation and dashboards (1E).

## Handover

Write `docs/handover/phase-1b.md` per CLAUDE.md section 9 and print it.
