# Handover — Phase 1D: User management, audit and notifications

Date: 2026-09-25

## Summary

What Phase 1D adds:

- **Audit trail:** a complete, tamper-proof audit trail of changes and reads.
- **Officer postings:** a record of each officer's postings over time.
- **Notifications:** live in-app notifications, with optional email.
- **Administration screens:**
  - super-admin user management;
  - read-only user lists for those holding `users.view`, limited to their jurisdiction;
  - audit and login-history viewers.

`make test` (390 tests, 1973 assertions), `make lint` and `make ci` pass. The browser acceptance walkthrough passed. It found two bugs, both fixed with regression tests (see "Fixes found during acceptance").

Commits:

| Step | Commit |
|---|---|
| 1D.1 + 1D.3 | `feat(audit): add audit logging and officer posting history` (combined: postings are opened and closed by the same actions that log the named events) |
| 1D.2 | `feat(audit): add record access log infrastructure` |
| — | `chore(lint): allow the access-logging trait before its first model` (see known issues) |
| 1D.4 | `feat(notifications): add notification infrastructure` |
| 1D.5 | `feat(notifications): add notification bell and notifications page` |
| 1D.6 + 1D.7 | `feat(users): add user management UI and audit viewers` (combined: the user detail page uses the viewers' gates and tables, so neither commit would stand alone) |
| — | `fix(notifications): retry queued deliveries instead of dropping them` (acceptance) |
| — | `fix(audit): withhold numeric ids from audit entries in the browser` (acceptance) |
| — | `docs: add phase 1d handover` (this file, README, prompt) |

The 1D.8 tests were written with each step.

## Acceptance checklist

| Item | Result |
|---|---|
| Super admin creates an LGA chief, shows the link once, completes setup, then sees login history, posting and activity | ✅ In Chrome: `/users/create` showed State and LGA fields once the LGA Security Chief role was picked. After creation, the delivery dialog offered email or **Show link once**. The link appeared with a warning and a copy button. After **Done** the link was no longer in the DOM, `history.state` or browser storage. The admin was signed out, and the link then led to setting a password, 2FA enrolment, recovery codes and the dashboard. (The browser extension cannot open a private window, so a signed-out session in the same browser stood in for it.) Back as the admin, the user's page showed: **Login history** with setup completed / signed in / signed out; **Postings** with LGA Security Chief, Demo LGA KN-1-1, Current; **Activity** with created, `setup_link_issued` (`{"delivery":"manual"}`, no URL or token) and updated entries. |
| Deactivating a logged-in user signs them out immediately | ✅ The LGA chief was signed in over curl, in a separate session. The admin deactivated them in the UI (the reason is required). The curl session's next request was redirected to `/login`. `login_histories` got `session_expired`, the user row has `is_active=false` and the reason, and the posting was closed. |
| A notification sent from Tinker appears live in the bell | ✅ The unread count went from 0 to 1 over Reverb without a reload, and the dropdown lists the notification. The first attempt exposed a lost-job bug, now fixed (see below). |
| A state director sees only their state's users, read-only | ✅ A Kano state director (over curl): `/users` listed only the two Kano users, with `can.create=false`. The detail page offered only the profile and postings tabs, with every action flag `false`. An out-of-state user's ULID returned 404. `/users/create`, `/audit` and `/login-history` returned 403. |
| `make test` and `make lint` pass | ✅ `make ci` also passes. |

## What was built

### 1D.1 — Audit logging

**Audit tooling:**

- `app/Support/Audit/BuffaloLogActivityAction` is registered as the package's log action in `config/activitylog.php`. On every entry it:
  - removes excluded attributes from `attribute_changes` and `properties`;
  - adds `ip_address` and `user_agent`.
- `AuditExclusions` is the global list:
  - `password`, `remember_token`, `two_factor_secret` and `two_factor_recovery_codes`;
  - anything matching `*_encrypted` or `*_bidx`.

  Models can add to it with `auditExcept()`.
- The `LogsBuffaloActivity` trait (`app/Models/Concerns`) logs created, updated, deleted and restored, with dirty attributes only and the causer. It is applied to `User` and to every hierarchy model.
- `AuditLog::event()` records named events:
  - `role_jurisdiction_changed`: old and new role and jurisdiction label;
  - `deactivated` and `reactivated`: with the reason;
  - `two_factor_reset`;
  - `unlocked`;
  - `setup_link_issued`: **delivery method only**, never the URL or token.

**Immutability:**

- Migration `2026_09_27_000001` creates the trigger function `buffalo_reject_audit_change()`. It rejects `UPDATE` and `DELETE` on `activity_log`, and is shared with `record_access_logs`.
- `activitylog:clean` is replaced by `DisabledCleanActivitylogCommand`, which refuses to run.

### 1D.2 — Record access log

- The `record_access_logs` table records:
  - user, action (`AccessAction`), and subject type and id;
  - ULID, IP address, user agent and `created_at`.

  The table is immutable (the same trigger).
- `RecordAccessLogger` and the `LogsReads` trait. Models in Phase 3 add the trait; controllers call `$model->logRead()` or use the logger directly.
- Tested with the `tests/Support/Audit/AccessLoggedFixture` model, since no production model reads sensitive records yet.

### 1D.3 — Officer postings

- The `officer_postings` table:
  - columns: `user_id`, `role`, `jurisdiction_level`, `state_id`, `lga_id`, zones (JSON), `started_at`, `ended_at` and `created_by`;
  - CHECK constraints per level;
  - the partial unique index `officer_postings_one_open_per_user` (at most one open posting per user);
  - existing users were backfilled.
- The `OfficerPostings` helper is used by four actions:
  - `CreateUser` opens the first posting;
  - `UpdateUserJurisdiction` closes it and opens a new one, and does nothing when nothing changed;
  - `DeactivateUser` closes it;
  - `ReactivateUser` opens a new one.
- `OfficerPosting::holderOn($role, $jurisdiction, $date)` answers "who held this post on that date", including past dates.

### 1D.4 — Notifications

- **Channels:**
  - `database` and `broadcast` always;
  - `mail` only when `BUFFALO_MAIL_ENABLED=true` (`config('buffalo.mail_enabled')`) **and** the notification's `allowsMail()` returns `true`.
- **`BuffaloNotification` base class:**
  - implements `ShouldQueue` and `ShouldBeEncrypted`;
  - payload is exactly `type`, `title`, `body`, `url` (internal path only, enforced), `severity` and `created_at`;
  - `$tries = 3` with backoff `[10, 60]`.
- **Converted notifications:**
  - `AccountSetupNotification` (mail only; carries `setupUrl`);
  - `TwoFactorResetNotification`;
  - `AccountLockedNotification`.
- **Broadcasting:**
  - private channel `users.{ulid}` (`User::receivesBroadcastNotificationsOn()`); the default numeric `App.Models.User.{id}` channel is removed;
  - channel authorisation is by the XSRF cookie, via a custom handler in `resources/js/lib/echo.ts`.
- **`NotifyRole`** (`app/Support/Notifications`) sends to active users of a role within a given jurisdiction. It resolves recipients through `NotificationRecipients` (see deviations).

### 1D.5 — Notification UI

- The bell in the app header shows the live unread count via `useEchoNotification`, a dropdown of the latest 10, **Mark all as read**, and a toast for `critical` severity.
- The `/notifications` page has an All/Unread filter and pagination.
- `notifications.unreadCount` is a shared Inertia prop.

### 1D.6 — User management UI

**Routes:** `routes/users.php`, all named `users.*`:

| Route | What it does |
|---|---|
| `GET /users` | List:<br>- 25 per page;<br>- filters: role, state, status (active, inactive, locked, setup pending, 2FA not enrolled);<br>- ILIKE search on name, email and service number;<br>- sortable by name, email, service number, rank, last login and created date. |
| `GET /users/create`, `POST /users` | Create. Jurisdiction fields depend on the role (`resources/js/components/users/jurisdiction-fields.tsx`, using `JurisdictionOptions::assignableStates/assignableLgas`). After creation the page redirects to the user and opens the delivery dialog. |
| `GET /users/{ulid}` | Detail. Tabs:<br>- Profile and Postings for anyone who may view the user;<br>- Login history only with `viewLoginHistory`;<br>- Activity only with `viewAudit`. |
| `PUT /users/{ulid}` | Edit profile. |
| `PUT /users/{ulid}/jurisdiction` | Change role and jurisdiction. |
| `POST /users/{ulid}/deactivate`, `/reactivate` | Reason required. |
| `POST /users/{ulid}/unlock` | Audited. |
| `POST /users/{ulid}/reset-two-factor` | Resets the user's 2FA. |
| `POST /users/{ulid}/setup-link` | JSON only, throttled 10/min. `manual` returns `{url}` with `Cache-Control: no-store`. `email` returns `{sentTo}`, or 422 when mail is disabled. |

**Authorisation:** every action goes through `UserPolicy`, which gained `unlock` and `issueSetupLink`. Holders of `users.view` get read-only index and show within their jurisdiction. Everyone else gets 403 or 404.

**Validation:** Form Requests in `app/Http/Requests/Users/`. `UserJurisdictionRequest` builds a `UserJurisdiction` from ULIDs, so role and jurisdiction validation is the same as in `CreateUser`.

**Presenters:** `app/Http/Presenters/UserPresenter` and `ActivityPresenter`. No numeric ids, secrets or tokens reach the browser.

**Frontend:**

- pages `resources/js/pages/users/{index,create,show}.tsx`;
- shared components `filter-bar`, `native-select`, `pagination` and `users/*`;
- `resources/js/lib/http.ts` (`postJson`, `xsrfToken`).

### 1D.7 — Audit and login-history viewers

- **Pages:**
  - `/audit` (`AuditLogController`): filters by causer (ULID), subject type, event and date range. Shows old and new values and the named-event properties.
  - `/login-history` (`LoginHistoryController`): filters by user (ULID), event, email, IP address and date range. Flags `repeated_failures` (≥ 3 failures from one IP in 24 hours) and lists `suspiciousIps` at the top.
- **Access:** gates `viewAudit` and `viewLoginHistory` (`app/Support/Audit/AuditAccess`) require the permission **and** system or national reach. A state-level role given `audit.view` is still refused (tested).
- **Read-only:** GET routes only, paginated (tested).
- **Sidebar:** links to Users, Audit log and Login history, shown by the shared `auth.can` flags (see deviations).

## Fixes found during acceptance

1. **Lost notifications:**
   - **Symptom:** the first Tinker notification reached the bell over broadcast, but its database copy never arrived.
   - **Cause:** a newly spawned Horizon worker hit a fatal autoload error while unserializing the job. The error was transient, seemingly caused by the macOS bind mount in development. Horizon runs `tries => 1`, so the retry after `retry_after` failed at once with `MaxAttemptsExceededException`, and the notification was silently lost.
   - **Fix:** `BuffaloNotification` now sets `$tries = 3` and `$backoff = [10, 60]`, so a crashed delivery is retried. Security alerts such as account-locked must not vanish. Tested.
   - **Trade-off:** a crash after a successful send could deliver one channel twice.
2. **Numeric ids in the audit UI:**
   - **Symptom:** the Activity tab's "created" diff showed `id`, `state_id`, `lga_id`, `created_by` and `updated_by`.
   - **Fix:** `ActivityPresenter` now withholds `id`, `*_id` and `*_by` keys (recursively). The stored audit record is unchanged. Tested on `/audit`.

## Deviations from the prompt

- **`ReactivateUser` requires a reason** (`(User $user, string $reason, ?User $actor)`), matching deactivation. The prompt asked for a reason on both events; the 1B signature had none.
- **`NotificationRecipients`** (`app/Support/Jurisdiction`) is a second sanctioned scope bypass, next to `AuthenticationUserProvider`. Recipient lookup must work when there is no acting user (queue jobs, lockout during login). It returns only active users of a role in an explicit jurisdiction. The architecture test still forbids `withoutGlobalScope(s)`, `newModelQuery(` and `newQueryWithoutScopes(` outside `app/Support/Jurisdiction`. This work also removed a `newModelQuery()` bypass that 1C's `RecordFailedLogin` had used.
- **`JurisdictionOptions::assignableStates/assignableLgas`**: the existing options are scoped to the viewer's jurisdiction. A super admin has system reach with no intelligence scope, so assignable options are a separate method, available only to `users.manage` holders.
- **`AuditAccess` gates** add a "national or system reach" condition on top of the permissions. This implements "refuse if a state-level role ever holds `audit.view`".
- **A minimal sidebar** (Users, Audit log, Login history) was added ahead of 1E's role-aware navigation, so the pages are reachable. 1E should replace `useMainNavItems()` in `app-sidebar.tsx`.
- **`BUFFALO_MAIL_ENABLED`** (default `true` in `.env.example`) replaces 1C's mailer detection as the single "is mail configured" switch (`MailConfiguration::isConfigured()`).
- **The setup-link endpoint returns JSON** rather than an Inertia response. This keeps the plain link out of Inertia page props, history state and flashed session data (tested).
- **Two combined commits** (1D.1+1D.3 and 1D.6+1D.7), for the reasons given in the commit table.

## New packages

None. `spatie/laravel-activitylog` and `spatie/laravel-permission` were already installed.

## Known issues and notes

- **No `critical` notification class exists yet.** The critical toast is implemented but was not seen live. The first real critical notifications come with incidents (Phase 5).
- **`LogsReads` has no production user until Phase 3.** `phpstan.neon` ignores `trait.unused` for that one file; remove the ignore when Phase 3 uses the trait.
- **Stored audit diffs still contain numeric ids and raw values**, but the presenter hides them. Phase 7 exports must reuse `ActivityPresenter` or apply the same rule.
- **Dev accounts left over from acceptance:** these are dev database accounts, not seed data.
  - `admin@buffalo.local` now has a known password and a 2FA secret set via Tinker.
  - `musa.bello@buffalo.local` (LGA chief, deactivated).
  - `sd.kano@buffalo.local` (Kano state director).
- **Unrecognised account:** an account `admin@buffalo.com` exists in the dev database, with a successful login at 16:14 today. It was not created during this session's acceptance and was left untouched.
- **The Horizon autoload crash** is assumed to be a development bind-mount issue. Production images copy the code in, so it should not recur, and the retry fix covers it either way.

## For the next step (1E)

- **Navigation:**
  - replace the interim `useMainNavItems()` with role-aware navigation;
  - `auth.can` already carries `viewUsers`, `viewAudit` and `viewLoginHistory`;
  - add new flags there rather than checking roles in React.
- **Reusable components:** use `FilterBar`, `NativeSelect` and `Pagination` for list pages, and `postJson` for JSON actions.
- **Notifications:** anything new must extend `BuffaloNotification`, keep the payload free of sensitive content, and use internal paths for `url`. Use `NotifyRole` for role-based fan-out.
- **Audit:** new location-bearing models need `HasJurisdiction` **and** `LogsBuffaloActivity`. Models holding persons or classified records need `LogsReads` (Phase 3).
