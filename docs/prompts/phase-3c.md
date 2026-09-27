# BUFFALO — Phase 3C: Cross-Jurisdiction Matching, Access Requests and Grants

Read `CLAUDE.md` before starting, especially section 4 rule 8 (cross-jurisdiction access) and section 4a (classification). Read `docs/handover/phase-3a.md` and `docs/handover/phase-3b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Persons, persons of interest, political offices and criminal groups exist, visible only within jurisdiction and classification. The jurisdiction scope has an `AccessGrantResolver` extension point, currently bound to a null implementation.

This step lets an officer discover that a person is on record in another jurisdiction (a **hit**), request access, and view the record read-only for a limited time once the owning state approves. It is the most security-sensitive feature in BUFFALO: it deliberately looks across jurisdictions, so every part must reveal as little as possible and log everything.

### Core rules (from CLAUDE.md, restated because they are critical)

1. Hits reveal only: owning state name, record category ("Person of interest" or "Person record"), and match strength. Never names, aliases, photos, LGA, offences or ULIDs.
2. A hit is shown **only if the searcher's clearance could see the record** (classification). A grant never overrides classification.
3. Records above the searcher's clearance produce no hit. `confidential` → the owning State Director is notified; `secret` → the DG and DDGs are notified.
4. Grants are read-only, time-limited, revocable, and every view under a grant is access-logged.
5. National users (DG, DDG) already see everything within classification; they never receive hits and never need requests.
6. The same mechanism applies between LGAs of the same state (an LGA chief cannot see neighbouring LGAs).

---

## Step 3C.1 — Match service

`app/Support/CrossJurisdiction/PersonMatchService.php`. This is the **only** class outside `app/Support/Jurisdiction` allowed to query persons across jurisdictions, and it does so through `JurisdictionContext::runAsSystem()` with a clear reason string. Add an architecture test enforcing that.

**Input:** a `MatchCriteria` object: name (and optionally other names), aliases, date of birth, NIN, phone, gender. It can be built from a search query or from an unsaved person form.

**Match types:**

| Type | Rule | Cross-jurisdiction behaviour |
|---|---|---|
| `exact` | NIN blind index equal, or phone blind index equal | Hit shown ("Record found") |
| `strong` | name or alias trigram similarity ≥ 0.75 **and** date of birth blind index equal | Hit shown ("Likely match") |
| `possible` | name or alias similarity ≥ 0.6, without a DOB match | **Never shown** across jurisdictions |

Thresholds configurable in `config/buffalo.php`. Queries must use the trigram index (`%` operator with `SET LOCAL pg_trgm.similarity_threshold`), per CLAUDE.md.

**Output:** a `MatchResult` with two parts:

- `visible`: matches the user can already see (in jurisdiction and clearance, or under an active grant). These are returned as normal person references.
- `hits`: matches outside the user's visibility, after applying all filters below.

**Filtering, in this order:**

1. Exclude soft-deleted persons.
2. Exclude records the user can already see (those go in `visible`).
3. If the user is national (DG/DDG), return no hits.
4. For each remaining match, check `ClassificationGate::canSee` against a hypothetical in-jurisdiction view. If the record is above the user's clearance: suppress it, and queue a notification (`confidential` → owning State Director; `secret` → DG and all DDGs). The notification says an officer in state X searched and matched a record above their clearance, with the requester's name and state. It contains no person details. Deduplicate: at most one such notification per requester, per record, per day.
5. Keep only `exact` and `strong` matches.
6. Group by record; keep the strongest match type per record; limit to 10 hits.

**Hit tokens:** each hit carries a token encrypted with Laravel's encrypter containing `person_id`, `user_id`, `match_type`, `match_event_id` and an expiry (2 hours). Tokens are rejected if expired, tampered with, or used by a different user.

**Match events:** every hit shown creates a `record_match_events` row: `user_id`, `subject_type`, `subject_id`, `owning_state_id`, `owning_lga_id`, `match_type`, `context` (`person_search`, `person_create`, later `incident_entry`), `created_at`. Immutable (trigger), like other audit tables. Suppressed matches (rule 4) are recorded too, with `match_type` plus a `suppressed` flag, so national oversight can see them.

**Rate limiting** (configurable):
- Soft limit (default 20 hits shown per user per day): once exceeded, notify the DG and the DDGs covering the user's state, once per user per day.
- Hard limit (default 50 per day): the service returns no further hits that day and the UI shows "Daily cross-jurisdiction search limit reached. Contact your State Director." The event is logged.

**Owner digest:** a daily scheduled job sends each State Director (and any Deputy with an active delegation, see 3C.2) a digest: how many of their state's records produced hits in the last 24 hours, grouped by requesting state and officer. Record identities are included only as links, which the director can open because the records are theirs.

## Step 3C.2 — Approval delegation

`approval_delegations` table: `ulid`, `state_id`, `delegator_id` (State Director), `delegate_id` (Deputy State Director), `starts_at`, `ends_at` (nullable), `reason` (nullable), `revoked_at`, `revoked_by`, `created_by`, timestamps.

Rules:
- Only the current State Director of a state can create a delegation, and only to a current Deputy State Director of the same state.
- At most one active delegation per delegate.
- A delegation is active when `starts_at ≤ now`, not revoked, and `ends_at` is null or in the future.
- It **ends automatically** when either person's officer posting closes (role or jurisdiction change, deactivation). Hook into the existing posting actions.
- Creation, revocation and automatic ending are logged as named activity events and notify both parties.

## Step 3C.3 — Access requests

`record_access_requests` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `requester_id`, `requester_state_id`, `requester_lga_id` | requester's jurisdiction at request time |
| `subject_type`, `subject_id` | polymorphic; `Person` only for now |
| `owning_state_id`, `owning_lga_id` | |
| `match_event_id` | FK; every request originates from a hit |
| `reason` | text, required, min 30 characters |
| `reference` | nullable string (case or file reference) |
| `scope` | enum: `profile`, `full` |
| `urgency` | enum: `normal`, `urgent` |
| `requested_days` | 7, 14 or 30 |
| `status` | enum: `pending`, `escalated`, `approved`, `rejected`, `cancelled`, `expired` |
| `decided_by`, `decided_at`, `decision_note` | note required on rejection |
| `granted_days`, `granted_scope` | approver may shorten the duration or narrow `full` to `profile`, never widen |
| `escalated_at` | |
| timestamps | no soft deletes; requests are never deleted |

Rules:
- A request is created only by exchanging a valid hit token (`RequestAccess` action). The subject is resolved from the token server-side; the client never sends a person identifier.
- One open (`pending`/`escalated`) request per requester and subject. No request if the requester already holds an active grant for it.
- Requester can cancel while open.
- All status changes are named activity events.

**Who can decide** (`AccessRequestApprovers` service):
- `pending`: the owning state's State Director, or a Deputy State Director with an active delegation for that state.
- `escalated`: additionally the DG and the DDGs covering the owning state (DDGs with zones including it, or DDGs with no zones).
- Nobody may decide their own request.
- The decider must have clearance for the record's classification (always true for the roles above, but check it).

**Escalation:** a job every 5 minutes moves `pending` requests to `escalated` after 2 hours (`urgent`) or 48 hours (`normal`), configurable, and notifies the new deciders. `escalated` requests undecided after 7 more days become `expired`, and the requester is notified.

**Notifications:**
- New request → deciders; `urgent` ones use severity `critical` (live toast).
- Decision → requester.
- All use `BuffaloNotification`; payloads contain no person details, only a link to the request.

## Step 3C.4 — Grants

`record_access_grants` table: `ulid`, `request_id`, `user_id`, `subject_type`, `subject_id`, `scope`, `granted_by`, `starts_at`, `expires_at`, `revoked_at`, `revoked_by`, `revocation_reason`, timestamps.

- Approving a request creates a grant in the same transaction.
- **Resolver:** replace `NullAccessGrantResolver` with a real implementation. For `Person`, it adds "or an active grant exists for this user and record" **inside** the jurisdiction scope's nested group. It does **not** touch the classification scope; classification applies as normal (rule 2).
- POI details, political offices and group memberships are visible under a grant only through the person page and only per scope (below). The grant does not make criminal group records themselves visible.

**Scope filtering, enforced server-side in the controller that builds the person page props:**

| Scope | Visible |
|---|---|
| `profile` | name, other names, gender, date of birth or approximate age, nationality, occupation, description, current photo, aliases, roles |
| `full` | everything in `profile`, plus NIN, phone, address, POI details and status history, political offices, group memberships (group name and type only, not linked), associations (other person's name and relationship only, not linked) |

**Read-only:** policies deny every update, delete, photo upload, association change and POI change to a user whose only access is a grant. Test each.

**Access logging:** every view under a grant writes a `record_access_logs` row with context `grant`.

**Expiry and revocation:**
- A job every 15 minutes ends expired grants; the requester is notified 24 hours before expiry and at expiry.
- The approver, the owning State Director, a delegated deputy, the DG or a DDG covering the state can revoke a grant at any time with a reason. The holder is notified.

## Step 3C.5 — Permissions

| Permission | DG | DDG | State Dir. | Deputy | LGA Chief |
|---|---|---|---|---|---|
| `access_requests.create` | | | ✅ | ✅ | ✅ |
| `access_requests.decide` | | | ✅ | ✅* | |
| `access_requests.oversee` | ✅ | ✅ | | | |
| `delegations.manage` | | | ✅ | | |

\* Deputy State Directors hold the permission but can decide only while holding an active delegation (checked by the `AccessRequestApprovers` service).

`access_requests.oversee`: view all requests, grants, match events and suppressed matches within the user's national or zonal reach, decide escalated requests, revoke grants. Super admin: none.

## Step 3C.6 — UI

**Person search (`/persons`):** below normal results, a "Records in other jurisdictions" panel listing hits: owning state, category, match strength and a "Request access" button. When the hard limit is reached, show the limit message instead. National users never see this panel.

**Person create:** the duplicate check from 3A now also runs the match service. If hits exist, the officer sees them with "Request access" and "Create a new record anyway". Choosing to create anyway requires a short justification, logged as a named event (`created_despite_hit`) on the new person.

**Request form (dialog):** reason (with minimum length counter), reference, scope with a plain-language explanation of what each includes, urgency, duration.

**`/access-requests`**, tabs by permission:
- **My requests:** status, owning state, dates; cancel open requests; link to the record when a grant is active.
- **Awaiting my decision:** for State Directors and delegated deputies. The decision screen shows the requester (name, rank, role, jurisdiction), reason, reference, urgency, requested scope and duration, match type, the record (they own it, so linked normally) with its classification, and the **requester's request history for the last 90 days** (count, approved, rejected, cancelled). Actions: approve (optionally shorten or narrow), reject (note required).
- **Escalated:** for DG/DDG.
- **Grants:** active grants I hold; grants issued on my state's records (revoke action).
- **Delegations:** for State Directors: current and past delegations, create and revoke.
- **Oversight:** for DG/DDG: all requests, grants and match events (including suppressed) with filters by state, officer, status and date, and a per-officer view that highlights high hit counts.

**Person page under a grant:** a persistent banner: "You are viewing this record under temporary access granted by [name], [state]. Read-only. Expires [date and time]." Tabs outside the grant scope are hidden. No edit controls.

**Dashboards:**
- State Director / delegated deputy: pending decisions (with urgent count), active grants on the state's records.
- Requesting officers: my open requests, my active grants with expiry.
- DG/DDG: escalated requests, today's hit volume, officers over the soft limit.

Run `make wayfinder` after route changes.

## Step 3C.7 — Tests

Matching:
- Each match type, thresholds, alias matching, phone normalisation, DOB requirement for `strong`, `possible` never returned as a hit.
- `EXPLAIN` confirms trigram index use for the name query.
- Records the user can see go to `visible`, never to `hits`.
- National users get no hits.
- Classification suppression: an LGA chief matching a `confidential` record gets no hit and the owning State Director is notified once per day; a State Director matching a `secret` record in another state gets a hit (clearance allows), while an LGA chief gets none and the DG/DDGs are notified.
- Hit payloads (Inertia props) contain no names, ULIDs, LGA, photos or offences: walk the props and assert.
- Hit token: expired, tampered, and different-user tokens are rejected.
- Match events recorded, including suppressed ones; the table is immutable.
- Soft limit notifies once per day; hard limit stops hits and shows the message.
- Owner digest content and recipients.
- The architecture test restricting cross-jurisdiction person queries to `PersonMatchService`.

Delegation:
- Only the State Director can delegate, only to their own state's deputy; one active per delegate.
- Automatic ending on posting change or deactivation of either party.

Requests and grants:
- Created only from a valid token; the client cannot choose the subject.
- One open request per subject; none when a grant is active.
- Approver resolution for pending and escalated; no self-decision; deputy only with an active delegation.
- Escalation timings for both urgencies (time travel), then expiry after 7 days.
- Approval creates a grant; the approver can shorten and narrow but not widen.
- A grant makes the person visible through the scope, but a grant on a record above the holder's clearance does not (classification still applies).
- `profile` vs `full` prop filtering, walking the props.
- Every write action is denied to grant-only users.
- Grant views logged with context `grant`.
- Expiry job, 24-hour reminder, revocation by each permitted role, and denial for others.
- Intra-state case: an LGA chief requesting a record from a neighbouring LGA in the same state, decided by their own State Director.
- Super admin has no access to any of this.

## Phase 3 completion checklist

- [ ] Lagos LGA chief searches a name and NIN recorded in Kano: sees one "Record found" hit showing only "Kano State · Person of interest · Record found".
- [ ] Requests `full` access, urgent. Kano State Director receives a live critical notification, sees the requester's history, approves for 7 days narrowed to `profile`.
- [ ] Lagos chief sees the record read-only with the grant banner; NIN and POI tabs hidden; every view logged.
- [ ] Kano State Director revokes the grant; the record disappears for the Lagos chief immediately.
- [ ] Unanswered urgent request escalates after 2 hours (time travel in tests; config change in the browser).
- [ ] Kano State Director delegates to the deputy, who can then decide; changing the deputy's posting ends the delegation.
- [ ] A `confidential` Kano record produces no hit for a Lagos LGA chief, but does for the Lagos State Director.
- [ ] DG oversight shows all requests, grants and match events, including suppressed ones.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-3c.md` per CLAUDE.md section 9, and `docs/handover/phase-3-summary.md` covering all of Phase 3: tables, permissions, the matching rules and thresholds as implemented, the grant scope table, and open issues for Phase 4. Print both.
