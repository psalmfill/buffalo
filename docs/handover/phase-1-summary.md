# Phase 1 summary — Foundation

Date: 2026-09-25

Phase 1 delivered BUFFALO's foundation:

- the administrative hierarchy;
- users, roles and fail-closed jurisdiction scoping;
- hardened authentication with mandatory 2FA;
- tamper-proof audit trails;
- notifications;
- user management;
- a role-aware application shell.

There is no operational intelligence data yet; that starts in Phase 2.

At the end of Phase 1, `make test` (445 Pest tests with 2590 assertions, plus 5 Vitest tests), `make lint` and `make ci` pass. The completion checklist passed on a fresh clone (details in [`phase-1e.md`](phase-1e.md)).

## What each step delivered

| Step | Delivered | Handover |
|---|---|---|
| 0 | Docker stack pinned to image versions:<br>- PostgreSQL 18 + PostGIS 3.6;<br>- Valkey, Horizon, scheduler, Reverb;<br>- Mailpit and a Vite dev server.<br><br>Also: offline Leaflet + PMTiles map, `make` targets, CI in `scripts/ci.sh`. | [phase-0](phase-0.md) |
| 1A | Geopolitical zones, 37 states, senatorial districts, LGAs and wards: migrations, models, the zones/states CSV seeder and a strict `buffalo:import-hierarchy`. Clearly synthetic Lagos and Kano LGAs are seeded only in `local` until the owner's CSVs arrive. Larastan level 7. | [phase-1a](phase-1a.md) |
| 1B | Six roles, one jurisdiction per user (enforced in the database), `spatie/laravel-permission` with one role per user. Fail-closed `JurisdictionContext`, `JurisdictionScope`, `JurisdictionGate` and `JurisdictionRules`. `UserPolicy`, user Actions and `buffalo:create-super-admin`. | [phase-1b](phase-1b.md) |
| 1C | Authentication:<br>- no self-registration or passkeys;<br>- password policy with history;<br>- one-time setup links (72 h, hashed);<br>- mandatory TOTP 2FA that can't be disabled;<br>- escalating lockout;<br>- single session, 30-minute idle timeout and 12-hour maximum;<br>- generic auth responses;<br>- append-only `login_histories`. | [phase-1c](phase-1c.md) |
| 1D | Audit and user administration:<br>- `activity_log` with a global exclusion list and IP/UA, plus named events;<br>- `record_access_logs`; both logs immutable through database triggers;<br>- officer postings, answering "who held this post on date X";<br>- queued notifications (database, broadcast on `users.{ulid}`, optional mail) and the bell;<br>- super-admin user management;<br>- audit and login-history viewers. | [phase-1d](phase-1d.md) |
| 1E | Shell and dashboards:<br>- shared user data (no numeric ids);<br>- configuration-driven navigation and a header with the jurisdiction;<br>- classification banner;<br>- role dashboards over scoped queries;<br>- BUFFALO error pages (out-of-jurisdiction = 404);<br>- empty states;<br>- Vitest. | [phase-1e](phase-1e.md) |

## Final permission matrix

Defined in `RolesAndPermissionsSeeder::matrix()` and referenced only through `App\Enums\Permission`.

| Permission | super_admin | director_general | deputy_director_general | state_director | deputy_state_director | lga_security_chief |
|---|---|---|---|---|---|---|
| `users.view` | ✅ | ✅ | ✅ | ✅ | ✅ | – |
| `users.manage` | ✅ | – | – | – | – | – |
| `audit.view` | ✅ | ✅ | – | – | – | – |
| `login_history.view` | ✅ | – | – | – | – | – |
| `system.horizon` | ✅ | – | – | – | – | – |
| `system.settings` | ✅ | – | – | – | – | – |

Gates and policies layered on top. A permission alone is never enough:

| Gate or policy | Rule |
|---|---|
| `Gate::before` | Blocked accounts (inactive, locked, soft-deleted) are denied everything. |
| `UserPolicy` view/viewAny | `users.view` **and** the user record inside the actor's jurisdiction. |
| `UserPolicy` manage actions | `users.manage` (super admin only), plus last-super-admin protection. |
| `viewAudit` | `audit.view` **and** national or system reach. A state-level role given `audit.view` is still refused. |
| `viewLoginHistory` | `login_history.view` **and** national or system reach. |
| `viewHorizon` | `system.horizon`. |
| `viewDashboard` | A role whose stored jurisdiction is consistent (`JurisdictionRules` is not "nothing"). |

The navigation shows Users, Audit log, Login history and Queue monitor by these permissions.

## Jurisdiction scope matrix

The source of truth is `JurisdictionRules`, applied by `JurisdictionScope` (global scope via `HasJurisdiction`) and `JurisdictionGate`. It is verified by `JurisdictionScopeMatrixTest` and `JurisdictionGate` tests.

Scenario: zone A = states A1 (LGAs A1a, A1b) and A2 (LGA A2); zone B = state B1 (LGA B1); zone E has no states.

**Intelligence records** (any model with `state_id`/`lga_id`):

| User | A1/A1a (own LGA) | A1/A1b | A1 state-level | A2/A2 (same zone) | B1/B1 (other zone) |
|---|---|---|---|---|---|
| super_admin | – | – | – | – | – |
| director_general | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, national (no zones) | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, zone A | ✅ | ✅ | ✅ | ✅ | – |
| DDG, zone B | – | – | – | – | ✅ |
| DDG, zones A + B | ✅ | ✅ | ✅ | ✅ | ✅ |
| DDG, zone with no states | – | – | – | – | – |
| state_director (A1) | ✅ | ✅ | ✅ | – | – |
| deputy_state_director (A1) | ✅ | ✅ | ✅ | – | – |
| lga_security_chief (A1a) | ✅ | – | – | – | – |
| inactive, locked or soft-deleted DG | – | – | – | – | – |
| no role, or role and jurisdiction inconsistent | – | – | – | – | – |

**System-administration records** (`visibleToSuperAdmin = true`: users, officer postings):

- the super admin sees all of them;
- everyone else follows the table above.

**Contexts:**

- HTTP request with no user (`none`): nothing is visible.
- Console commands and queued jobs (`system`): everything is visible. Use it only through the audited `runAsSystem`.
- A job acting for a user must set that user's context.

**Sanctioned scope bypasses** (only in `app/Support/Jurisdiction`, enforced by an architecture test):

- `AuthenticationUserProvider` (sign-in);
- `NotificationRecipients` (recipient lookup without an acting user).

**Records outside the user's jurisdiction return 404**, not 403. Phase 3 adds the "hit" notice and approved, time-limited access grants (`AccessGrantResolver` hook already in the scope).

## Security properties in place

- **Sign-in:**
  - no self-registration;
  - the first super admin comes from an Artisan command, and no default credentials exist;
  - one-time setup links: hashed, 72 h, never logged or stored in session, page props or history;
  - mandatory TOTP 2FA;
  - lockout with super-admin notification;
  - generic sign-in and reset responses.
- **Sessions:** single session per account, idle and absolute timeouts, and deactivation ends sessions at the next request.
- **Audit:**
  - every change and named event is audited (with IP and browser), never including passwords, 2FA secrets, encrypted fields or setup links;
  - `activity_log`, `login_histories` and `record_access_logs` reject `UPDATE`, `DELETE` and `TRUNCATE` in the database;
  - `activitylog:clean` is disabled.
- **No numeric ids in the browser:**
  - ULIDs in URLs, props and channel names;
  - shared props are tested for every role;
  - audit diffs are filtered by the presenter.
- **No runtime internet access:** fonts, map assets and tiles are all local, and the network log was verified.
- **Notifications:** payloads carry no sensitive content and only internal URLs, and queued deliveries are retried.

## Open issues carried into Phase 2

1. **Owner reference data:** `database/data/senatorial_districts.csv`, `lgas.csv` (and optionally `wards.csv`) are still missing.
   - Only `APP_ENV=local` can be seeded, with synthetic Lagos and Kano LGAs.
   - `make setup` (which now seeds) and `db:seed` stop with a clear message in any other environment.
   - Phase 2 "Reference data and LGA information" needs these files.
2. **Boundaries and centroids** are empty. Every map shows the national view, and the dashboard centring (`MapView`) starts working once they are imported.
3. **Database roles:** the application connects as the PostgreSQL superuser, which could disable the audit triggers. Phase 8 must add a non-superuser application role (and move the trigger owner). The dev credentials in `.env.example` must be replaced before deployment (Phase 9).
4. **Placeholder cards** await Phases 3 (cross-state access requests), 5 (incidents, escalations) and 6 (flashpoints). No `critical` notification exists yet, so the critical toast has not been seen live.
5. **`LogsReads`** (record-access logging) has no model using it until Phase 3; `phpstan.neon` ignores `trait.unused` for that file until then.
6. **Officer postings** don't record a DDG's zones (a zoned DDG's posting reads "National").
7. **Audit wording:** audit entries written before 1E use the older jurisdiction-label wording. They are immutable, so this is by design.
8. **Horizon's default is one attempt per job.** `BuffaloNotification` retries (3 attempts). New queued jobs in later phases should set their own `$tries` and backoff.
9. **Cosmetic tooling:** `make setup` prints a fixed `localhost:8000` URL, and the `make ci` target assumes the `buffalo_default` network.
10. **`CLAUDE.md`:** your working-tree edits are still uncommitted. Every phase left them out of its commits.
11. **Dev database:** it holds walkthrough accounts from 1C–1D and the unexplained `admin@buffalo.com` account noted in the 1D handover. None of this is committed. `make fresh` clears it.
