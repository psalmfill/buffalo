# BUFFALO — Phase 1E: App Shell, Navigation and Placeholder Dashboards

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1d.md` for what already exists.

## Context

Phase 1A–1D delivered the hierarchy, users, roles, jurisdiction scoping, hardened authentication, audit logging, notifications and the user management screens. The app still uses the starter kit's generic shell and a single dashboard with a map.

This step gives BUFFALO its own identity, role-aware navigation and a placeholder dashboard for each role, completing Phase 1.

---

## Step 1E.1 — Shared Inertia data

In `HandleInertiaRequests`, share:

- `auth.user`: `ulid`, `name`, `email`, `role` (value and label), `jurisdiction` (level, plus state and LGA `ulid` and `name` where applicable, plus zone names for a zoned DDG), and a `jurisdictionLabel` string such as "National", "North West Zone, North East Zone", "Lagos State", "Ikeja LGA, Lagos State".
- `auth.permissions`: array of permission names the user holds.
- `app.name`, `app.banner` (see 1E.3), `app.sessionIdleMinutes`.
- Never share numeric IDs. Add a test that walks the shared props for every role and fails if any key named `id` or ending in `_id` appears.

Frontend helpers: a `useAuth()` hook and a `can(permission)` function. These control **visibility only**; every route remains protected server-side.

## Step 1E.2 — Navigation

Replace the starter kit's navigation with a configuration-driven sidebar: one TypeScript file listing sections and items, each item with label, icon (lucide), route (Wayfinder helper) and required permission. Items without permission are hidden.

Phase 1 items:

| Section | Item | Permission |
|---|---|---|
| Overview | Dashboard | (all) |
| Administration | Users | `users.view` |
| Administration | Audit log | `audit.view` |
| Administration | Login history | `login_history.view` |
| Administration | Queue monitor (Horizon, opens in new tab) | `system.horizon` |

Later phases add Intelligence, Incidents, Flashpoints, Reports sections by adding entries to this file. Structure it so that is a one-line change per item.

Header: BUFFALO name and logo placeholder, the user's `jurisdictionLabel`, notification bell (from 1D), user menu (settings, sign out).

## Step 1E.3 — System banner

- `BUFFALO_BANNER_TEXT` and `BUFFALO_BANNER_LEVEL` (`info`, `restricted`, `confidential`, `secret`), both optional.
- When set, a thin full-width banner shows at the very top of every page, including login, e.g. "RESTRICTED — Authorised users only. All activity is logged."
- Colours by level must be readable in light and dark mode.

## Step 1E.4 — Dashboards

A single `/dashboard` route that renders a role-specific page component. All content is placeholder, since no operational data exists yet, but the layout should be the real one later phases fill in.

| Role | Dashboard content now |
|---|---|
| super_admin | System overview: users by role, users by status (active, inactive, locked, setup pending), failed logins in the last 24 hours, lockouts in the last 7 days, recent audit events (last 10), queue health (Horizon status: running/paused/inactive). No map, no intelligence content. |
| DG, DDG | National header, map of Nigeria (zoned DDG: note the assigned zones), placeholder cards: "Incidents (last 30 days)", "Active flashpoints", "Pending escalations", "Cross-state access requests", each showing "Available from Phase N". |
| state_director, deputy_state_director | State header, map, list of LGAs in the state with their LGA chief (from current postings; "Vacant" if none), same placeholder cards scoped to the state. |
| lga_security_chief | LGA header, map, their LGA details (district, headquarters), placeholder cards for their LGA. |

- All counts come from real queries through the jurisdiction scope, not hard-coded.
- Map: centre on the state's or LGA's `centroid` when available; otherwise national view. (Centroids are empty until boundary import, so national view is expected for now.)
- Placeholder cards are one reusable component, so later phases replace the placeholder with real content per card.

## Step 1E.5 — Error pages and polish

- Inertia-rendered 403, 404, 419 (with "Your session expired, please sign in again") and 500 pages in BUFFALO styling. They reveal nothing about why access was denied or whether a record exists: a record outside the user's jurisdiction returns **404**, not 403. Verify the model binding for jurisdiction-scoped models produces 404.
- Page `<title>` format: `Page Name — BUFFALO`.
- Empty states for all tables built so far (users, audit, login history, notifications).
- Loading states for Inertia page transitions (progress bar).

## Step 1E.6 — Tests

- Shared props contain no numeric IDs for any role.
- Each role receives the correct dashboard component with correct scoped counts (e.g. state director's LGA list contains only their state's LGAs, and the right chief from postings).
- Super admin dashboard shows no intelligence placeholders.
- Navigation config: a frontend unit test or snapshot ensuring items appear only with their permission. (Add Vitest only if no frontend test runner exists; report the package and licence per CLAUDE.md.)
- Banner appears on login and app pages when configured, and is absent when not.
- Out-of-jurisdiction user detail returns 404.

## Phase 1 completion checklist

This is the end of Phase 1. Verify and report each:

- [ ] Fresh clone: `make setup`, `buffalo:create-super-admin`, log in, enrol 2FA, reach the super admin dashboard.
- [ ] Super admin creates one user per role (DDG with and without zones); each completes setup and sees the correct dashboard, navigation and jurisdiction label.
- [ ] A Lagos state director cannot reach any Kano user by URL (404).
- [ ] Notifications arrive live; lockout notifies super admins.
- [ ] Every action in 1D appears in the audit log; no audit, login history or access log row can be modified.
- [ ] Browser network tab shows zero external requests on every page.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-1e.md` per CLAUDE.md section 9. Also write `docs/handover/phase-1-summary.md`: a concise overview of everything Phase 1 delivered, the final permission matrix, the jurisdiction scope matrix, and open issues carried into Phase 2. Print both.
