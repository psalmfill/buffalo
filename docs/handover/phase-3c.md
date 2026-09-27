# Handover — Phase 3C: Cross-jurisdiction matching, access requests and grants

Date: 2026-09-27

## Summary

Phase 3C lets officers learn that a person is recorded elsewhere without learning who or where, and ask for temporary read-only access:

- **Matching:** `PersonMatchService` checks searches and new-person forms against persons in other jurisdictions. It returns **hits** that reveal only the owning state, a category and the match strength, plus an encrypted, short-lived hit token.
- **Access requests:** created only from a hit token. The owning State Director decides, or a delegated Deputy State Director. Undecided requests escalate to national oversight and then expire.
- **Delegation:** a State Director delegates approval to one of their deputies, for a period. The delegation ends automatically when either party's posting changes or either is deactivated.
- **Grants:** read-only and time-limited (`profile` or `full`), revocable, and never above the holder's clearance. The jurisdiction scope admits granted persons. Every view is logged with context `grant`.
- **Oversight:** DG and DDGs see requests, grants and match events, including suppressed ones, with per-officer counts. State Directors see searches of their records. Dashboards show access figures.

Checks:

- `make test`: 910 Pest tests (5312 assertions), including 51 new cross-jurisdiction tests, plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- The Phase 3 completion checklist passed in the browser, with a few items checked through Tinker (see below).

Commits:

| Step | Commit |
|---|---|
| 3C.1–3C.7 | `d3ec893 feat(cross-jurisdiction): add person matching, access requests, delegations and grants` |
| — | `fix(dashboard): name out-of-zone officers in the soft-limit list`, with a test that grants never expose linked records through their own scopes |
| — | `docs: add phase 3c handover and phase 3 summary` (this file, the summary, README, prompt) |

## Phase 3 completion checklist

Dev data used:

- **Kano record:** "Aminu Dogo Kabiru" (Demo LGA KN-1-1, NIN `71234567890`, POI).
- **Confidential Kano record:** "Sadiq Lawan" (NIN `71234567891`).
- **New dev user:** `dsd.kano@buffalo.local` ("Hauwa Sani", Deputy State Director, Kano).

| Item | Result |
|---|---|
| Lagos LGA chief searches a name and NIN recorded in Kano: sees one hit showing only "Kano State · Person of interest · Record found" | ✅ As `chief.lagos`, NIN `71234567890` gave 0 local results and the panel "Records in other jurisdictions": **Kano State · Person of interest · Record found** with *Request access*. Nothing else appears (no name, ULID, LGA, photo or offence); a test walks the props to prove it.<br>⚠️ **A name search gives no hit, by design** (see Deviations): a *Likely match* needs name plus date of birth, and only the new-person form supplies a date of birth. |
| Requests `full`, urgent; Kano SD gets a live critical notification, sees the requester's history, approves 7 days narrowed to `profile` | ✅ Request "Full record, 14 days, urgent" with a reason and reference. `sd.kano` had the notification (bell count 1; stored with severity `critical`, title "Urgent access request"). The request page showed the requester (role, LGA, state), the matched record's name, and "Requester's requests, last 90 days". Approved with 7 days and Profile; the decision reads "Profile, 7 days", with access until 04/10/2026. |
| Lagos chief sees the record read-only with the grant banner; NIN and POI tabs hidden; every view logged | ✅ Banner: "You are viewing this record under temporary access granted by Aisha Kano Director, Kano State. Read-only. Expires … (Profile)". Only the Profile tab; no NIN, phone, address, LGA or state; no edit, delete or photo actions. `record_access_logs` holds 2 rows with context `grant` (two page views). |
| Kano SD revokes the grant; the record disappears for the Lagos chief immediately | ✅ Revoked with a reason; the request shows "Revoked 27/09/2026, 07:20:11". The chief now gets 404 on the person page (the next view, as in the tests). |
| Unanswered urgent request escalates after 2 hours | ✅ In tests: time travel for urgent (2 h) and normal (48 h), then expiry after 7 days. In the browser, instead of changing config: a second, normal request was backdated 49 h in the DB and `buffalo:escalate-access-requests` reported "escalated: 1". The DG's **Escalated** tab listed it. |
| Kano SD delegates to the deputy, who can then decide; changing the deputy's posting ends the delegation | ✅ Delegated in the Delegations tab (Current: Hauwa Sani, no end date, Active). Tinker: `dsd.kano->can('decide', $request)` → `true`. `UpdateUserJurisdiction` moved her to Lagos, and the delegation ended (`revoked_at` set, `end_reason = posting_closed`, inactive). She was then moved back to Kano. |
| A `confidential` Kano record: no hit for a Lagos LGA chief, a hit for the Lagos SD | ✅ Chief: "No matching records in other jurisdictions"; `sd.kano` got a *SuppressedMatchAlert*. The Lagos SD (Tinker, through the service): one hit, `{"state":"Kano","category":"Person record","strength":"Record found"}`. |
| DG oversight shows all requests, grants and match events, including suppressed ones | ✅ Oversight tab: officer Tunde Lagos Chief with 2 hits (2 today) and 1 suppressed; both requests (escalated; approved and urgent); the revoked grant. Filters: owning state, status, from and to. |
| `make test`, `make lint` and `make ci` pass | ✅ |

## What was built

### 3C.1 — Matching service

**Migration `2026_10_03_000001_create_cross_jurisdiction_tables`** creates four tables:

- `record_match_events`
- `approval_delegations`
- `record_access_requests`
- `record_access_grants`

Their columns are in the [Phase 3 summary](phase-3-summary.md#tables-added-in-phase-3). `record_match_events` rejects `UPDATE` and `DELETE` (trigger `buffalo_reject_audit_change`).

**`App\Support\CrossJurisdiction`:**

- **`MatchCriteria`:** built from a search term (`fromSearchTerm`) or the person form (`fromPersonInput`). It holds the names, aliases, NIN, normalised phone and date of birth.
- **`PersonMatchService::match(criteria, user, context, record = true)`:**
  1. Finds candidates inside `runAsSystem()`:
     - NIN and phone through the blind indexes;
     - names and aliases through the trigram `%` operator, with transaction-local thresholds (`PersonSearch::withThresholds`).
  2. Classifies each candidate:
     - `exact`: NIN or phone matches;
     - `strong`: best name similarity ≥ 0.75 **and** the same date-of-birth blind index;
     - `possible`: similarity ≥ 0.6. Never shown.
  3. Splits the results:
     - Records the user can see (through their own scopes) are returned as `visible`, never as hits.
     - National users get no hits. This includes DDGs with zones, because they see nationally for their zones' purposes; see Deviations.
     - Records above the user's clearance are **suppressed**. The event is recorded with `suppressed = true` and notified at most once per day per record:
       - `confidential`: the owning State Director;
       - `secret`: the DG and the DDGs covering the state.
     - Only `exact` and `strong` are shown, strongest first, at most 10.
  4. Applies the daily limits:
     - **Soft (20):** one notification a day to the DG and covering DDGs.
     - **Hard (50):** no more hits that day; the UI says so; an audit event is written.
  5. Records a `record_match_events` row per shown or suppressed hit. `record = false` re-checks silently (used on save).
- **`HitToken`:** `Crypt::encryptString` of {person, user, match event, match type, expiry}. It is rejected if tampered, expired (120 minutes) or used by another user.
- **`Hit`:** `{token, state, category, strength}`.
  - Category is "Person of interest" only if a POI record exists that the searcher's clearance would allow; otherwise "Person record".

**Where hits appear:**

- **`/persons/search`:** prop `crossJurisdiction` (`null` for users without `access_requests.create`).
- **The new-person duplicate check (`/persons/similar`):** hits are listed in the duplicate dialog. Saving despite a hit needs a justification (`hit_justification`, ≥ 10 characters). It is audited as `created_despite_hit` after a silent re-check.

**Owner digest:** `buffalo:match-digest` runs daily at 06:30. It sends each owning State Director and delegated deputy the day's hits on their records, grouped by requesting state and officer. It contains no record details.

### 3C.2 — Delegation

- `approval_delegations`: state, delegator, delegate, `starts_at`, optional `ends_at`, reason, `revoked_at`/`revoked_by`, `end_reason`.
- A delegate holds at most one open delegation.
- **`ManageDelegations`:**
  - `create`: State Director only; the deputy must be an active DSD of the same state.
  - `revoke`.
  - `endFor(User)`: called from `OfficerPostings::closeOpen`, so any role or jurisdiction change or deactivation of either party ends the delegation, with `end_reason = posting_closed`.
- Both parties are notified (`DelegationChanged`).

### 3C.3 — Access requests

- **`RequestAccess`:**
  - The request must come from a valid token whose match event belongs to the requester and was not suppressed.
  - It is refused while an open request for the same subject exists, or while a grant is active.
  - Stored: reason (≥ 30 characters), optional reference, scope, urgency, requested days (7/14/30).
  - It notifies the deciders. Urgent requests are `critical`.
- **Deciders (`AccessRequestApprovers`):**
  - the owning State Director, or a DSD with an active delegation (both need `access_requests.decide`);
  - for **escalated** requests also the DG and the DDGs covering the owning state (`access_requests.oversee`).
  - Nobody decides their own request, and a decider must be cleared for the record.
- **`DecideAccessRequest`:**
  - `approve` may shorten the duration and narrow `full` → `profile`, never widen. It locks the row and creates the grant from now for N days.
  - `reject` requires a note.
- **`CancelAccessRequest`:** the requester, while open.
- **`buffalo:escalate-access-requests`** runs every 5 minutes:
  - pending urgent requests escalate after 120 minutes, normal ones after 48 hours;
  - escalated requests expire after 7 days, and the requester is told.

### 3C.4 — Grants

- **`GrantAccessResolver`** (bound in `AppServiceProvider`; replaces `NullAccessGrantResolver`) ORs the user's active granted person ids into the jurisdiction group of `persons`. Classification is untouched, so a grant never overrides it.
- **`JurisdictionGate`:**
  - `canAccess` = own jurisdiction OR grant;
  - new `canAccessOwn` (own only) and `viaGrantOnly`.
- **Writes use own jurisdiction only:**
  - `PersonPolicy::update`/`delete`.
  - The new `PersonPolicy::link` ability, used for:
    - associations (both ends);
    - group members;
    - traditional ruler and leader links;
    - `PersonWithRole`.
  - `PoliticalOfficePolicy::create` needs `update` on the person.
- **Person page under a grant** (`PersonController::showUnderGrant`):
  - **`profile`:** name fields, gender, date of birth or age, nationality, occupation, description, current photo, aliases, roles.
  - **`full`:** also NIN, phone and address, plus tabs POI, political offices, group memberships (name and type only) and associations (name and relationship only). These come from `GrantedPersonView` and are filtered by the **holder's** clearance.
  - Never shown: LGA, state or community, the photo gallery, the history tab or any action.
  - Grant-only users can fetch only the current photo.
- **`buffalo:expire-access-grants`** runs every 15 minutes:
  - it reminds the holder 24 hours before expiry;
  - it records and announces expiry (`access_grant_expired`).

  Access itself ends at `expires_at`, whether or not the job has run.
- **`RevokeGrant`:** permitted for the granter, the owning State Director, a delegated deputy, the DG and a DDG covering the state. A reason is required and the holder is notified.

### 3C.5 — Permissions

| Permission | Roles |
|---|---|
| `access_requests.create` | State Director, Deputy State Director, LGA Security Chief |
| `access_requests.decide` | State Director, Deputy State Director (only effective with an active delegation) |
| `access_requests.oversee` | Director General, Deputy Director General |
| `delegations.manage` | State Director |

Super admin has none, and has no access to requests, grants or persons.

### 3C.6 — UI

- **Navigation:** Intelligence → **Access requests** (shown with any of the three `access_requests.*` permissions).
- **`/access-requests`:** tabs by role:
  - **My requests** and **Grants** (held);
  - **Awaiting my decision** and **Searches of our records** (holders of `access_requests.decide`; only a State Director or a delegated deputy sees rows);
  - **Delegations** (State Directors);
  - **Escalated** and **Oversight** (DG/DDG).
- **`/access-requests/{ulid}`:**
  - the request, the match strength and the decision;
  - for deciders: the requester's role and posting, and their 90-day request history;
  - approve and reject forms;
  - revoke.
- **`HitsPanel`** on the persons search and in the duplicate dialog, and **`RequestAccessDialog`**, which explains both scopes.
- **Person page:** grant banner (`data-test="grant-banner"`), and read-only lists for the full-scope tabs.
- **Dashboards (LGA, state, national):** an access figures card:
  - own open requests and active grants;
  - requests awaiting decision and active grants issued;
  - for national users: escalated requests, hits today and suppressed matches today.

### 3C.7 — Tests

`tests/Feature/CrossJurisdiction/` has 51 tests; fixtures are in `tests/Support/CrossJurisdiction/CrossFixtures.php`.

- **`MatchServiceTest` (19):**
  - match types and thresholds (configurable), aliases, phone normalisation, DOB requirement, possible never shown;
  - `EXPLAIN` shows `persons_search_name_trgm` over 50,000 rows;
  - visible vs hits; national users get no hits;
  - confidential and secret suppression, with recipients and a once-a-day dedupe; soft-deleted persons are never matched;
  - the hit props walk; token expiry, tampering and a foreign user;
  - events recorded and immutable;
  - soft and hard limits; digest content and recipients;
  - the architecture test: `runAsSystem(` outside `app/Support/Jurisdiction` only in `PersonMatchService`.
- **`AccessRequestsTest` (21):**
  - delegation rules, including the delegation ending on deactivation or reposting of either party;
  - token-only creation, one open request, urgent = critical;
  - deciders (pending, escalated, own, clearance);
  - approve with shorten and narrow, never widen; reject needs a note; cancel; view rights;
  - escalation and expiry timings for both urgencies; index tabs by role.
- **`GrantsTest` (11):**
  - visibility to the holder only; classification is not overridden;
  - profile vs full props (walked);
  - `grant` access logs;
  - every write refused (update, delete, photo, alias, association both ways, POI, office, group member);
  - reminder and expiry; revocation rights;
  - the intra-state case (an LGA chief and a neighbouring LGA in the same state, decided by that State Director);
  - super admin; linked records are never visible through their own scopes.

## Deviations and conflicts

- **Name-only searches never give hits.** The prompt's rules make `strong` require the same date of birth, and the search box has no date of birth field. So a name typed in the search box can reach at most `possible`, which is never shown. The checklist's "searches a name and NIN" therefore gives a hit only for the NIN. The new-person form, which has a date of birth, does produce name hits. If name-only hits are wanted from search, it needs a decision: either add an optional date of birth to the search, or show `strong` on name alone.
- **Suppression is applied before the strength filter**, in the prompt's order. A `possible` (name-only) match with a record above clearance is therefore also recorded and notified as suppressed.
- **Zoned DDGs are treated as national for hits** (no hits). They cannot request access (no `access_requests.create`). They oversee and decide escalated requests only for states in their zones.
- **Requests, grants, match events and delegations are not `HasJurisdiction`.** They are two-sided (requester and owner), so the policies and controller queries decide visibility. Delegations *are* `HasJurisdiction` at state level.
- **Users are jurisdiction-scoped, so the parties to a request are loaded through a sanctioned lookup.** Those parties are the requester, decider, holder and granter. The lookup is the new `AccessRequestParties` in `App\Support\Jurisdiction`, alongside `AccessRequestApprovers`, `GrantedPersonView` and `GrantAccessResolver`, all documented as sanctioned bypasses. Without it, the owning State Director could not see who was asking (found by the tests).
- **Extra columns:** `approval_delegations.end_reason`, `record_access_grants.expiry_reminded_at` and `expiry_notified_at` (so reminders and expiry notices go out once).
- **`DelegationRequest` authorises in the Form Request** (before validation), unlike the project's usual "controller authorises" pattern. Its rules are relative to the director's state, and would otherwise answer non-directors with messages about deputies.
- **Grant-only person pages hide the LGA and state entirely**, and the photo gallery and history tabs. The prompt's scope table does not list them.
- **Escalation in the browser** was tested by backdating a request instead of changing config, which would need a restart of the scheduler and Horizon.

## Bugs found and fixed during testing

- `DecideAccessRequest::lockOpen` read the status through the enum cast, so every decision failed with "no longer open". It now reads the raw value.
- The owning State Director's request page returned 500, and the decision notification failed, because the out-of-state requester was invisible through the user scope. Fixed with `AccessRequestParties`.
- The national dashboard's "over the soft limit" list looked officers up through the user scope, so a zoned DDG would have seen "—" for officers outside the zone. It now uses `AccessRequestParties::users`.
- The delegation form defaulted the start to UTC, one hour behind Lagos time. It now uses local time.
- Under a profile grant, NIN, phone and address showed "—", which looked like "not recorded". They are now hidden.

## New packages

None.

## Known issues and notes

- **"Immediately" on revocation means the next request.** An already-open page stays on screen until the user navigates. Pushing a live "access ended" event over Reverb is a small follow-up.
- **Oversight lists are capped** (100 requests, 200 events, 100 grants, 50 officers) with no pagination.
- **The hit category relies on the POI table only.** Other sensitive roles (for example criminal group membership) are not signalled in the category.
- **Tests and single sessions:** `CrossFixtures::signIn()` resets `users.session_token` before `actingAs`. Otherwise the single-session policy signs out the second request by the same in-memory user (the known "302 after `flushSession`" quirk).
- **Dev database changes:**
  - users `dsd.kano@buffalo.local` (Hauwa Sani) and the test password and TOTP secret on `chief.lagos`, `sd.kano`, `dsd.kano` and `dg` (test values; see the session's scratch scripts);
  - persons Aminu Dogo Kabiru (POI) and Sadiq Lawan (confidential) in Demo LGA KN-1-1;
  - two requests (one approved and revoked, one escalated), one delegation (ended by the reposting test), match events;
  - permissions seeded, Horizon restarted.

  `make fresh` resets the data.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next phase (4)

- Only persons are grantable. If agencies' coverage reports or organizations need cross-jurisdiction access, extend `GrantAccessResolver` per model and add the subject type to the requests' `subject_type` CHECK.
- Any new model linked to persons that is scoped through `whereHas('person')` would become visible under a grant. Give such models their own `state_id`/`lga_id` (as POIs and offices have) unless that is intended.
- New writes that take a person must use `can('link', $person)` or `can('update', $person)`, never `view`.
