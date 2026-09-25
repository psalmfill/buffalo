# BUFFALO — Phase 1C: Authentication Hardening

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1b.md` for what already exists.

## Context

Phase 1B built roles, user jurisdiction, jurisdiction scoping and the `CreateUser` action. Authentication is still the starter kit's Fortify setup: public registration, optional 2FA, passkeys, and self-service account deletion.

This step turns it into authentication suitable for an intelligence system: no self-registration, mandatory 2FA, account setup via one-time links, lockout, session limits and login history.

---

## Step 1C.1 — Remove what BUFFALO must not have

1. **Registration:** disable the Fortify registration feature, remove the register page, routes, links (including on the welcome page) and related tests.
2. **Passkeys:** remove `laravel/passkeys`, `@laravel/passkeys` and all passkey UI, routes, migrations (new migration dropping any passkey tables if they were created) and tests.
3. **Account deletion:** remove the "delete account" feature from settings.
4. **Self-service profile editing:** users may no longer change their own name or email (admins do this in 1D). Settings keep: password change, two-factor management (recovery codes only), appearance.
5. **Email verification feature:** disable. Email ownership is proven by the account setup link (1C.3).
6. **Welcome page:** `/` redirects to `/login` for guests and to the dashboard for authenticated users. Delete the welcome page.

**Commit:** `refactor(auth): remove registration, passkeys and self-service account changes`

## Step 1C.2 — Password rules

- A single `PasswordRules` definition used everywhere (setup, change, reset, `buffalo:create-super-admin`): minimum 12 characters, mixed case, at least one number, at least one symbol.
- **Do not use `uncompromised()`** (it calls an external API; forbidden by CLAUDE.md).
- `password_histories` table (`user_id`, `password` hash, `created_at`). The last 5 passwords cannot be reused. Keep only the last 5 per user.
- Set `password_changed_at` on every change.
- No forced periodic expiry.

## Step 1C.3 — Account setup flow

Accounts are created by a super admin (UI in 1D; the `CreateUser` action exists). The new user then sets their own password through a one-time link.

1. `account_setup_tokens` table: `user_id`, `token_hash` (SHA-256 of the token; the plain token is never stored), `expires_at`, `used_at`, `created_by`, timestamps.
2. `IssueAccountSetupLink` action: invalidates any previous unused token for the user, creates a new one valid for **72 hours**, and returns the plain URL.
3. Two delivery methods, both supported:
   - **Email:** `AccountSetupNotification` via the mail channel.
   - **Manual handover:** the URL is returned to the admin once for delivery by another route (the deployment may have no email). It is never retrievable again.
4. `/account/setup/{token}` (guest only):
   - Invalid, expired or used token → a generic "This link is invalid or has expired. Contact your administrator." page. Do not reveal which.
   - Valid token → set password form (with password rules shown) → marks token used, logs the user in, and sends them straight to 2FA enrolment.
5. Rate-limit the setup route by IP.
6. `CreateUser` does **not** send the link automatically; the admin chooses the delivery method in 1D.

## Step 1C.4 — Mandatory two-factor authentication

- Fortify TOTP two-factor with confirmation required.
- Middleware `EnsureTwoFactorEnrolled` on all authenticated routes except the enrolment pages, logout and the setup flow. A user without confirmed 2FA can only enrol.
- Enrolment page: QR code (generated locally; no external QR service), manual secret, confirmation code, then displays recovery codes once with a "I have saved these codes" confirmation.
- Users can regenerate recovery codes (requires password confirmation). **Users cannot disable 2FA.** Remove the disable option.
- `ResetTwoFactor` action (used by super admins in 1D): clears the user's 2FA, forcing re-enrolment at next login, invalidates their sessions, and notifies the user by email if mail is configured.
- Failed 2FA challenge attempts count toward lockout (1C.5).

## Step 1C.5 — Login security

1. **Lockout:** 5 consecutive failed attempts (password or 2FA code) lock the account for 15 minutes. Each further lockout within 24 hours doubles the duration, capped at 24 hours. Successful login resets the counter.
   - The login error message is always the same generic message, whether the email exists, the password is wrong, or the account is locked or inactive. Do not reveal account state on the login form.
   - A locked account notifies all active super admins (database channel; built properly in 1D — for now use Laravel's standard database notification).
2. Keep Fortify's per-IP rate limiting in addition to account lockout.
3. **Inactive users** cannot log in. `DeactivateUser` (from 1B) must now also destroy the user's sessions.
4. **Single active session:** controlled by `BUFFALO_SINGLE_SESSION` (default `true`). On login, store the session ID on the user; middleware logs out any other session for that user with the message "You were signed out because your account signed in elsewhere."
5. **Session limits:** idle timeout `SESSION_LIFETIME=30` minutes; absolute maximum session age 12 hours (`BUFFALO_SESSION_MAX_HOURS`) enforced by middleware. Session cookie `http_only`, `same_site=lax`, and `secure` whenever `APP_URL` is https.
6. A session idle-warning in the frontend: 2 minutes before idle timeout, show a modal with "Stay signed in" (which pings the server) and "Sign out".
7. Update `last_login_at` and `last_login_ip` on successful login.

## Step 1C.6 — Login history

- `login_histories` table: `user_id` (nullable), `email_attempted`, `event` (enum: `login_success`, `login_failed`, `two_factor_failed`, `locked`, `logout`, `session_expired`, `signed_out_elsewhere`, `setup_completed`), `ip_address`, `user_agent`, `created_at`. No `updated_at`.
- Record every event above via auth event listeners and the relevant middleware.
- **Immutable:** a PostgreSQL trigger rejects `UPDATE` and `DELETE` on `login_histories`. Test it.
- No UI yet (1D).

## Step 1C.7 — Frontend

Update the auth pages (login, 2FA challenge, 2FA enrolment, account setup, password reset, settings) to the BUFFALO name and styling. Keep the shadcn/ui components.

- **Password reset** ("forgot password") stays, but only sends an email when mail is configured and the account is active. The response is always the same generic message.
- Show password rules on every form that sets a password.
- Run `make wayfinder` after route changes.

## Step 1C.8 — Tests

- Registration routes return 404; no register link anywhere.
- Passkey routes and tables are gone.
- Account setup: valid token works once; expired, used and invalid tokens all show the same generic page; issuing a new link invalidates the old one; token stored only as a hash.
- A user without 2FA can reach only enrolment, logout and setup routes. 2FA cannot be disabled.
- Password rules on every entry point; reuse of the last 5 passwords rejected.
- Lockout thresholds, doubling, cap, reset on success; locked login shows the generic message; super admins notified.
- Inactive user cannot log in; deactivation ends existing sessions.
- Single-session enforcement on and off.
- Absolute session age enforced.
- Every login history event recorded; the table rejects updates and deletes.
- `ResetTwoFactor` forces re-enrolment and ends sessions.

## Acceptance checklist

- [ ] Walk through in the browser: super admin creates a user via Tinker using `CreateUser` and `IssueAccountSetupLink` → open link → set password → enrol 2FA → reach dashboard.
- [ ] Six failed logins lock the account, with a generic message, and a notification row exists for super admins.
- [ ] Logging in from a second browser signs out the first.
- [ ] `make test` and `make lint` pass.

## Out of scope

User management UI, audit log configuration and viewer, notification UI (1D), navigation and dashboards (1E).

## Handover

Write `docs/handover/phase-1c.md` per CLAUDE.md section 9 and print it.
