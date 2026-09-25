# BUFFALO — Phase 1D: User Management, Audit and Notifications

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1b.md` and `docs/handover/phase-1c.md` for what already exists.

## Context

Users, roles, jurisdiction scoping (1B) and hardened authentication with account setup links and mandatory 2FA (1C) exist. Admins currently have no UI; everything is done through Actions and Tinker.

This step adds audit logging, the record access log, officer posting history, notification infrastructure, and the super admin screens to manage users and review audit trails.

---

## Step 1D.1 — Audit logging

`spatie/laravel-activitylog` was installed in 1B. Now configure it properly:

1. A `LogsBuffaloActivity` trait wrapping the package defaults: logs `created`, `updated`, `deleted`, `restored`; only dirty attributes; records the acting user as causer; stores the request IP and user agent in properties.
2. A global attribute exclusion list that is never logged on any model: `password`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, and any column ending in `_encrypted` or `_bidx`.
3. Apply the trait to `User`, `GeopoliticalZone`, `State`, `SenatorialDistrict`, `Lga`, `Ward`.
4. Log named events (not just model changes) for: role or jurisdiction change, deactivation and reactivation (with reason), 2FA reset, account setup link issued (record the delivery method, **never** the URL or token), and `runAsSystem` (already done in 1B).
5. **Immutable:** a PostgreSQL trigger rejects `UPDATE` and `DELETE` on the activity log table. Disable or remove the package's `activitylog:clean` command registration so it cannot be scheduled accidentally; retention policy will be decided in Phase 8.

## Step 1D.2 — Record access log (infrastructure)

Used from Phase 3 onward for reads of sensitive records. Build the mechanism now.

- `record_access_logs` table: `user_id`, `subject_type`, `subject_id`, `action` (`view`, `export`, `print`, `download`), `context` (nullable string, e.g. `search`, `grant`), `ip_address`, `user_agent`, `created_at`. Index `(subject_type, subject_id)` and `(user_id, created_at)`.
- Immutable via trigger, like the others.
- `RecordAccessLogger` service with `log(Model $subject, AccessAction $action, ?string $context = null)`.
- A `LogsReads` model trait marking a model as access-logged, plus a helper controllers call when showing a record. Test it with a fixture model.

## Step 1D.3 — Officer postings

- `officer_postings` table: `ulid`, `user_id`, `role`, `jurisdiction_level`, `state_id`, `lga_id`, `position_title` (nullable), `started_at`, `ended_at` (nullable), `created_by`, timestamps.
- Only one open posting (`ended_at` null) per user, enforced with a partial unique index.
- `CreateUser` opens the first posting. `UpdateUserJurisdiction` closes the current posting and opens a new one in the same transaction. `DeactivateUser` closes the open posting; `ReactivateUser` opens a new one with the user's current role and jurisdiction.
- Purpose: later records reference "who was the LGA chief of X on date Y". Add `OfficerPosting::holderOn(Lga|State $place, Role $role, CarbonInterface $date)`.

## Step 1D.4 — Notifications infrastructure

1. Channels: `database`, `broadcast` (Reverb), `mail`.
2. Broadcast on the private channel `users.{ulid}` (not the numeric ID; update `routes/channels.php` and the Echo client). Remove the default `App.Models.User.{id}` channel.
3. A `BuffaloNotification` base class that all notifications extend:
   - Always uses `database` and `broadcast`.
   - Adds `mail` only when `BUFFALO_MAIL_ENABLED=true` and the notification allows it.
   - Payload for database and broadcast: `type`, `title`, `body`, `url` (internal path only), `severity` (`info`, `warning`, `critical`), `created_at`.
   - **Payloads never contain sensitive content** (no names of persons of interest, no incident details). They say what happened and link to the record, which is then access-checked. Document this rule in the class docblock.
   - All notifications are queued.
4. Convert the notifications from 1C (`AccountSetupNotification`, account locked, 2FA reset) to extend `BuffaloNotification`.
5. A `NotifyRole` helper: send a notification to all active users with a role within a jurisdiction (e.g. "all state directors of state X", "all super admins"). Later phases use it for incident alerts.

## Step 1D.5 — Notification UI

- Bell icon in the app header with unread count, updated live via Echo.
- Dropdown showing the latest 10 with title, time ago, severity indicator; clicking marks as read and navigates.
- "Mark all as read".
- `/notifications` page: paginated list, filter read/unread.
- A toast appears when a `critical` notification arrives in real time.

## Step 1D.6 — User management UI (super admin)

All routes authorised through `UserPolicy`. Pages in `resources/js/pages/users/`.

**List (`/users`)**
- Columns: name, email, service number, rank, role, jurisdiction (e.g. "Ikeja LGA, Lagos"), status (active, inactive, locked, setup pending, 2FA not enrolled), last login.
- Filters: role, state, status. Search: name, email, service number (PostgreSQL `ILIKE` is sufficient for now).
- Server-side pagination (25 per page) and sorting.
- Non-super-admins with `users.view` see this list read-only, limited to their jurisdiction.

**Create (`/users/create`)**
- Fields: name, email, phone, service number, rank, role, then jurisdiction fields that change with the role: none (super admin, DG), zones multi-select (DDG, optional), state (state roles), state then LGA (LGA chief).
- Uses `JurisdictionOptions` for the dropdowns.
- After creation, a dialog to choose setup link delivery: **Send by email** (disabled with explanation if mail is off) or **Show link once** (displays it with a copy button and a warning that it cannot be shown again).

**Detail (`/users/{ulid}`)**, with tabs:
- Profile and current jurisdiction.
- Postings history.
- Login history for this user.
- Activity: audit entries where this user is the subject or the causer.

**Actions** on the detail page, each with confirmation dialog:
- Edit profile (name, email, phone, service number, rank).
- Change role/jurisdiction.
- Deactivate (reason required) / Reactivate.
- Unlock account (clears `locked_until` and counter; logged).
- Reset 2FA.
- Issue new setup link (for users who have not completed setup, or to force a password reset).

## Step 1D.7 — Audit and login history viewers

- `/audit` (permission `audit.view`): activity log with filters for causer, subject type, event, date range; shows old and new values. Read-only. Both roles holding `audit.view` (super admin, DG) have system or national reach, so no jurisdiction filtering is needed yet. If a state-level role ever receives `audit.view`, the page must refuse until jurisdiction filtering is added; add a test for this.
- `/login-history` (permission `login_history.view`): filter by user, email, event, IP, date range. Highlights repeated failures from the same IP.
- Both paginated server-side. No edit or delete controls exist anywhere.

## Step 1D.8 — Tests

- Excluded attributes never appear in activity log entries; setup link URL/token never logged.
- Activity log, login history and record access log triggers reject update and delete.
- Posting history: create, jurisdiction change, deactivate, reactivate; only one open posting; `holderOn` returns the right user for past dates.
- Broadcast channel `users.{ulid}` authorises only its owner.
- `BuffaloNotification` respects `BUFFALO_MAIL_ENABLED`; all notifications are queued.
- `NotifyRole` targets exactly the right users (role + jurisdiction + active).
- Every user management route: allowed for super admin, forbidden for every other role (except read-only list/detail within jurisdiction for `users.view` holders).
- A state director cannot view a user in another state, even by ULID.
- Validation of role/jurisdiction combinations through the HTTP layer.
- Last super admin protections through the HTTP layer.
- Audit and login history pages forbidden without the permission.

## Acceptance checklist

- [ ] In the browser, as super admin: create an LGA chief, show the setup link once, complete setup in a private window, then see the new user's login history, posting and activity entries.
- [ ] Deactivating a logged-in user signs them out immediately.
- [ ] A notification sent from Tinker appears live in the bell without refresh.
- [ ] A state director sees only their state's users, read-only.
- [ ] `make test` and `make lint` pass.

## Out of scope

Role-aware navigation and dashboards (1E). Notification preferences (later).

## Handover

Write `docs/handover/phase-1d.md` per CLAUDE.md section 9 and print it.
