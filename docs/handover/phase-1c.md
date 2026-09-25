# Handover — Phase 1C: Authentication hardening

Date: 2026-09-25

## Summary

Authentication is now suitable for an intelligence system:

- no self-registration, passkeys, profile self-editing, account deletion or email verification;
- one password policy with history;
- account activation through one-time setup links;
- mandatory TOTP two-factor authentication that users cannot disable or re-key;
- escalating account lockout with super-admin notification;
- generic login and password-reset responses;
- single active session, a 30-minute idle timeout with a frontend warning, and a 12-hour absolute session limit;
- an append-only login history enforced by a database trigger.

`make test` (314 tests, 1493 assertions), `make lint` and `make ci` pass. All three browser acceptance walkthroughs passed.

Commits:

| Step | Commit |
|---|---|
| 1C.1 | `refactor(auth): remove registration, passkeys and self-service account changes` |
| 1C.2 | `feat(auth): add password policy and password history` |
| 1C.6 | `feat(auth): add append-only login history` (built before 1C.3 because 1C.3–1C.5 record events into it) |
| 1C.3 | `feat(auth): add one-time account setup links` |
| 1C.4 | `feat(auth): require two-factor authentication for every account` |
| 1C.5 | `feat(auth): add lockout, session limits and login security` |
| 1C.7 | `feat(auth): brand auth pages and make password reset responses generic` |
| — | `fix(auth): keep the application name on the account setup page` (found during the walkthrough) |
| — | `docs: add phase 1c handover` (this file, README, prompt) |

Tests for 1C.8 were written with each step.

## Acceptance checklist

| Item | Result |
|---|---|
| Browser walkthrough: `CreateUser` + `IssueAccountSetupLink` in Tinker → link → password → 2FA enrolment → dashboard | ✅ Done in Chrome: the super admin created an LGA security chief. The setup page showed the password rules. Setting the password led straight to enrolment with no password prompt. The QR code (local SVG) and manual key were shown; a TOTP code was confirmed; the recovery codes were shown and "I have saved these codes" was ticked; the dashboard loaded. The walkthrough found one bug: the setup page's `name` prop overrode the layout's app name. It is fixed, with a regression test. |
| Failed logins lock the account with a generic message; notification row for super admins | ✅ Via curl: 5 wrong passwords locked the account (`locked_until` +15 minutes, `lockout_count` 1). The correct password was then rejected with "These credentials do not match our records." An `AccountLockedNotification` row exists for the super admin, and `login_histories` has `locked`. The 5th request of the first burst got a 429 from the per-email+IP rate limiter (see the note below), so the run was spread over two minutes. |
| A login from a second browser signs out the first | ✅ curl completed password + 2FA for the same user. The Chrome session's next navigation landed on the login page with "You were signed out because your account signed in elsewhere." and a `signed_out_elsewhere` history row. |
| `make test` and `make lint` pass | ✅ 314 tests. `make ci` also passes. |

## What was built

### 1C.1 — Removals

- **Registration:** `Features::registration()` removed; `CreateNewUser`, `ProfileValidationRules`, the register page and `RegistrationTest` deleted. `/register` returns 404 for both GET and POST.
- **Passkeys:**
  - `Features::passkeys()` and the Fortify passkeys config and limiter removed.
  - `PasskeyUser`/`PasskeyAuthenticatable` removed from `User`.
  - All passkey UI deleted, `@laravel/passkeys` uninstalled, and `.well-known/passkey-endpoints` removed.
  - Migration `2026_09_26_000001_drop_passkeys_table`.
  - **`laravel/passkeys` stays in `composer.lock`, because Fortify 1.40 hard-requires it.** It is listed in `composer.json` `extra.laravel.dont-discover`, so its service provider never loads and it registers no routes. A test asserts there are no passkey routes or table.
- **Account deletion and profile editing:** `ProfileController`, its requests, the profile page and the delete-user component are gone. `/settings` redirects to `/settings/security`. Settings now hold password change, recovery codes and appearance.
- **Email verification:** the feature and `MustVerifyEmail` removed, the `verified` middleware dropped, and the verify page and tests deleted.
- **Welcome page:** deleted. `/` redirects guests to `/login` and users to the dashboard (route name `home` kept).
- The frontend `User` type no longer has a numeric `id` (1B hides it) and uses `ulid`.

### 1C.2 — Password policy (`App\Support\Auth\PasswordRules`)

- At least 12 characters, letters with mixed case, a number and a symbol, and not one of the last 5 passwords (`App\Rules\NotRecentlyUsedPassword`, which checks the history and the current password). No `uncompromised()`; a test proves validation makes no HTTP request.
- `descriptions()` feeds every password form, and `passwordRulesAttribute()` feeds the HTML `passwordrules` hint. `Password::defaults()` points at the same rule.
- **`App\Actions\Auth\SetUserPassword`** is the only place a user-chosen password is stored. It sets `password_changed_at`, writes to `password_histories` and keeps the last 5. It is used by settings, Fortify reset, account setup and `buffalo:create-super-admin`. No forced expiry.

### 1C.3 — Account setup (`/account/setup/{token}`)

- `account_setup_tokens` stores only the SHA-256 `token_hash` (64-character random token), plus `expires_at` (72 hours), `used_at` and `created_by`.
- **`IssueAccountSetupLink($user, $actor)`** expires the user's unused tokens and returns the URL once. It refuses blocked accounts.
- **`SendAccountSetupLink`** issues a link and emails it (`AccountSetupNotification`, sent synchronously so the plain link never sits in a queue).
- `CreateUser` sends nothing.
- `AccountSetupController`:
  - Guest-only and throttled per IP (`account-setup`, 10 per minute).
  - The user is resolved through the unscoped authentication provider, because a guest request is in jurisdiction mode `none`.
  - Unknown, expired, used and inactive-account links all render the same `auth/account-setup-invalid` page.
- **`CompleteAccountSetup`** locks the token row, validates the password (including history), sets it, marks the token used and records `setup_completed`. The controller then logs the user in, marks the password as confirmed, and redirects to 2FA enrolment.

### 1C.4 — Mandatory 2FA

- **`EnsureTwoFactorEnrolled`** (in the web group) sends a signed-in user without confirmed 2FA to `/two-factor/setup` (a 403 JSON response for API requests). It allows only the enrolment, Fortify 2FA setup, password confirmation, logout, account setup and session-ping routes.
- **Enrolment** (`/two-factor/setup`, `TwoFactorSetupController`, page `auth/two-factor-setup`):
  - It sits behind `RequirePassword`, which is already satisfied right after account setup.
  - Start → the existing setup modal (Fortify's local SVG QR code plus manual key) → confirm a code → recovery codes shown once, with an "I have saved these codes" checkbox posted to `two-factor.setup.complete`.
- **Users cannot disable or re-key 2FA:**
  - `DisableTwoFactorAuthentication` is rebound to refuse (403).
  - `EnableTwoFactorAuthentication` is rebound to refuse once 2FA is confirmed. **Fortify's own action regenerates a confirmed secret when `force=true` is posted, which would let anyone with a session take over the second factor.**
  - The settings page offers only "Generate new recovery codes" (Fortify route, password confirmation required); the new codes are shown once.
- **`App\Actions\Auth\ResetTwoFactor`** clears the secret, recovery codes and confirmation, and ends all the user's sessions. It emails `TwoFactorResetNotification` when mail is configured (`MailConfiguration`: the mailer is not `log` or `array`).
- **Session termination, independent of the session driver:**
  - `users.session_version` is incremented **atomically in SQL** by `SessionTerminator`. A first draft computed the increment from the in-memory model, which could lower the version and revive sessions; the tests caught it.
  - Each session stores the version it started with; `EnforceSessionPolicy` signs out mismatches.
  - Remember tokens are rotated.

### 1C.5 — Login security

- **Password step:** `App\Actions\Auth\AuthenticateUser` via `Fortify::authenticateUsing`. It covers both pipelines, for users with and without 2FA.
  - Unknown email, wrong password, locked, inactive or deleted all return `null`, so Fortify always shows the same generic message.
  - An unknown email still runs a hash check, so timing doesn't reveal whether an account exists.
  - The `remember` input is stripped: **remember-me is disabled**, because a remember cookie would silently re-open sessions past the idle and absolute limits. The checkbox is gone from the login page.
- **Lockout** (`RecordFailedLogin`, row-locked):
  - 5 consecutive failures (password, or a 2FA code via `TwoFactorAuthenticationFailed`) set `locked_until`.
  - The duration is 15 minutes × 2^(lock number − 1) within a 24-hour window, capped at 24 hours (tested: 15, 30, 60, … 960, 1440).
  - Attempts while locked don't count. A successful login resets the counter.
  - Locking records `locked` and sends `AccountLockedNotification` (database channel) to every active super admin. The admins are loaded via the audited `runAsSystem`, because the request is a guest request.
  - Config is in `config/buffalo.php`.
- **2FA challenge:** a user locked, deactivated or deleted during the challenge cannot complete it.
- **Rate limits:** Fortify's `login` limiter is now 5 per minute per email+IP **and** 20 per minute per IP. The `two-factor` limiter is unchanged (5 per minute per `login.id`).
- **Inactive users** cannot log in. `DeactivateUser` now calls `SessionTerminator`.
- **`EnforceSessionPolicy`** (web group) signs the user out, with a message and a history event, when:
  - the session version doesn't match or the account is blocked → `session_expired`;
  - the single-session token doesn't match (`BUFFALO_SINGLE_SESSION`, default on) → `signed_out_elsewhere`;
  - the session is older than `BUFFALO_SESSION_MAX_HOURS` (default 12) → `session_expired`.

  Sessions without a stamp (from before this change, or created with `actingAs` in tests) are bound on first sight.
- **Single-session design:** a random per-login token is stored on the user (`users.session_token`) and in the session, instead of the session ID. Fortify regenerates the session ID *after* the `Login` event, and a live session ID in the database would be a hijacking risk.
- **Session config:** `SESSION_LIFETIME=30` (idle); the cookie is `http_only`, `same_site=lax`, and `secure` by default when `APP_URL` is https (overridable with `SESSION_SECURE_COOKIE`).
- **Idle warning:** `session-idle-warning.tsx` in the app layout. Two minutes before the idle timeout it shows "Stay signed in" (POST `/session/ping`, route `session.ping`) and "Sign out". The timer restarts on every Inertia request, and when time runs out the page goes to the login screen. The lifetime is shared as the Inertia prop `session.lifetime`.
- **Successful login** (`RecordSuccessfulLogin`): resets the failure count, sets `last_login_at`/`last_login_ip`, and records `login_success`.

### 1C.6 — Login history

- `login_histories` (`user_id` nullable, `email_attempted`, `event`, `ip_address`, `user_agent` up to 1000 characters, `created_at` only).
  - A CHECK constraint limits `event` to the 8 values (`App\Enums\LoginEvent`).
  - **The PL/pgSQL trigger `login_histories_immutable` rejects UPDATE and DELETE; `login_histories_no_truncate` rejects TRUNCATE.** The model also refuses updates and deletes.
  - The function uses `CREATE OR REPLACE`, because `migrate:fresh` drops tables but not functions.
- **Where each event is recorded:**
  - `login_success`: the `Login` listener.
  - `login_failed`: `AuthenticateUser`.
  - `two_factor_failed`: the `TwoFactorAuthenticationFailed` listener.
  - `locked`: `RecordFailedLogin`.
  - `logout`: the `Logout` listener; it skips logouts forced by `EnforceSessionPolicy`, which record their own event.
  - `session_expired` and `signed_out_elsewhere`: `EnforceSessionPolicy`.
  - `setup_completed`: `CompleteAccountSetup`.
- Written through `App\Support\Auth\LoginHistoryRecorder`. No UI (1D).

### 1C.7 — Frontend

- **Branding:** a BUFFALO shield emblem (inline SVG) replaces the Laravel logo. The auth layout shows the "BUFFALO" wordmark and "Security Management and Intelligence Reporting".
- **Login:** "Sign in", with the note "Authorised users only. Access is monitored and recorded." Status messages (signed out elsewhere, session expired) appear at the top, and remember-me is removed.
- **Password rules** (`password-requirements.tsx`) appear on account setup, password reset and settings.
- **Forgot password:** it always answers `GenericPasswordResetLinkResponse::MESSAGE`, bound to both Fortify's success and failure contracts. That covers unknown, inactive and throttled cases; Fortify's default messages revealed whether an account existed. `User::sendPasswordResetNotification()` sends only when mail is configured and the account is active.
- **Wayfinder** was regenerated after the route changes.

### 1C.8 — Tests

New test files:

- `tests/Feature/Auth/RemovedFeaturesTest.php`
- `PasswordPolicyTest.php`
- `LoginHistoryTableTest.php`
- `AccountSetupTest.php`
- `MandatoryTwoFactorTest.php`
- `LoginSecurityTest.php`
- `ForgotPasswordTest.php`

Starter-kit tests were updated for the new behaviour. All items in the prompt's 1C.8 list are covered, including the source scan for register or passkey code and the database-level immutability checks.

## Files created or changed

- **Created:**
  - Auth actions: `app/Actions/Auth/{SetUserPassword,IssueAccountSetupLink,SendAccountSetupLink,CompleteAccountSetup,ResetTwoFactor,RecordFailedLogin,AuthenticateUser}.php`.
  - Fortify action overrides: `app/Actions/Fortify/{EnableTwoFactorAuthentication,DisableTwoFactorAuthentication}.php`.
  - Controllers, middleware, requests and responses: `app/Http/Controllers/Auth/{AccountSetupController,TwoFactorSetupController}.php`, `app/Http/Middleware/{EnforceSessionPolicy,EnsureTwoFactorEnrolled}.php`, `app/Http/Requests/Auth/AccountSetupRequest.php`, `app/Http/Responses/GenericPasswordResetLinkResponse.php`.
  - Listeners: `app/Listeners/{StartAuthenticatedSession,RecordSuccessfulLogin,RecordFailedTwoFactorAttempt,RecordLogout,RememberRecoveryCodesToShow}.php`.
  - Models and enum: `app/Models/{PasswordHistory,LoginHistory,AccountSetupToken}.php`, `app/Enums/LoginEvent.php`.
  - Notifications: `app/Notifications/{AccountSetupNotification,TwoFactorResetNotification,AccountLockedNotification}.php`.
  - Rule and support: `app/Rules/NotRecentlyUsedPassword.php`, `app/Support/Auth/{PasswordRules,LoginHistoryRecorder,SessionTerminator,SessionKeys,MailConfiguration}.php`.
  - Config and routes: `config/buffalo.php`, `routes/auth.php`.
  - Migrations: `2026_09_25_150048_create_notifications_table`, and `2026_09_26_000001`–`000006` (drop passkeys, password histories, login histories, setup tokens, session tracking, lockout tracking).
  - Frontend: `resources/js/pages/auth/{account-setup,account-setup-invalid,two-factor-setup}.tsx`, `resources/js/components/{password-requirements,regenerate-recovery-codes,session-idle-warning}.tsx`.
  - Tests: listed above.
  - Docs: `docs/prompts/phase-1c.md`, this file.
- **Changed:**
  - `app/Models/User.php`, `database/factories/UserFactory.php` (confirmed 2FA by default; `withoutTwoFactor()`).
  - Providers: `app/Providers/{AppServiceProvider,FortifyServiceProvider}.php`.
  - Controllers, requests and middleware: `app/Http/Controllers/Settings/SecurityController.php`, `app/Http/Requests/Auth/TwoFactorLoginRequest.php`, `app/Http/Requests/Settings/PasswordUpdateRequest.php`, `app/Http/Middleware/HandleInertiaRequests.php`.
  - Actions and commands: `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Users/DeactivateUser.php`, `app/Concerns/PasswordValidationRules.php`, `app/Console/Commands/CreateSuperAdminCommand.php`.
  - Config: `bootstrap/app.php`, `config/{fortify,session,inertia}.php`, `composer.json`, `package.json`/`-lock`, `.env.example`.
  - Routes: `routes/{web,settings}.php`.
  - Frontend: the login, reset, forgot and confirm pages, settings security, layouts, logo, types, `app.tsx`.
  - Tests: several starter-kit tests.
  - `README.md` (new "Accounts and sign-in" section).
- **Deleted:**
  - Registration, email verification, profile, welcome and passkey pages and components.
  - `CreateNewUser`, `ProfileController` and its requests, `ProfileValidationRules`.
  - `manage-two-factor.tsx`, `two-factor-recovery-codes.tsx`.
  - Starter-kit tests: `RegistrationTest`, `EmailVerificationTest`, `VerificationNotificationTest`, `ProfileUpdateTest`.

## Deviations and decisions

1. **`laravel/passkeys` cannot be removed from Composer.** Fortify requires it. It is disabled through `dont-discover`, and everything else about passkeys is removed.
2. **Single session stores a random per-login token, not the session ID** (reasons above). `users.session_token` holds it.
3. **Session termination uses a version counter** (`users.session_version`), which works with Valkey-stored sessions, instead of deleting session records.
4. **Remember-me is disabled**, so the 30-minute and 12-hour limits can't be bypassed.
5. **Fortify's 2FA enable action is overridden** so confirmed 2FA can't be re-keyed by `force=true` (a takeover risk not mentioned in the prompt).
6. **Forgot-password responses are made generic.** Fortify's default responses revealed whether an account existed.
7. **Forced sign-outs** (terminated session or blocked account) record `session_expired`; the event list has no separate value for them.
8. **Login rate limit** is per email+IP (5 per minute) plus per IP (20 per minute). The walkthrough shows the email+IP limit counts successful attempts too, so a user can make at most 5 login attempts per minute from one address.
9. **Inertia SSR is disabled by default** (`INERTIA_SSR_ENABLED`, default false). The starter kit enabled it, and in development each page render probed the Vite dev server's SSR endpoint. BUFFALO runs no SSR server.
10. **Failed logins for unknown emails** are recorded with the attempted address and no `user_id`, as the prompt's `email_attempted` column implies.
11. **Account-setup completion counts as password confirmation**, so enrolment doesn't ask for the password the user has only just chosen.
12. **Build order:** the login-history table (1C.6) was built before 1C.3, because the later steps record into it.
13. **Recovery codes** are shown once at enrolment and once after each regeneration. Fortify's `GET /user/two-factor-recovery-codes` (password-confirmed) still exists and the UI uses it to fetch the codes to display; the UI never lists existing codes otherwise.

## New packages

None. Removed: `@laravel/passkeys` (npm). `laravel/passkeys` remains as a Fortify dependency but is not loaded.

## Known issues and notes for 1D

- **User management UI (1D)** should use `CreateUser` → `IssueAccountSetupLink` (show the URL once, for manual handover) or `SendAccountSetupLink` (email), plus `ResetTwoFactor`, `DeactivateUser`/`ReactivateUser` and `UpdateUserJurisdiction`, each authorised through `UserPolicy`. An admin-triggered unlock (clearing `locked_until`) should also be added. The data is on `users` (`locked_until`, `lockout_count`).
- **Notifications:** `AccountLockedNotification` rows exist in `notifications`; 1D builds the UI.
- **Login history UI:** `login_histories` needs a viewer (`login_history.view` permission, super admin).
- **Audit:** password changes, 2FA resets and account setup are recorded in login history or on the user, but are not yet in the activity log (1D).
- **Horizon** also sits behind `EnsureTwoFactorEnrolled` and `EnforceSessionPolicy`, because it uses the web group.
- **Tests and sessions:** Laravel test requests keep the session store between requests but don't carry real cookies. Tests that switch users within one test should call `$this->flushSession()`, and session-policy tests pass stamps with `withSession()`, as in `LoginSecurityTest`.
- **Your `CLAUDE.md` edits** are still uncommitted in the working tree; I left them out of my commits.
