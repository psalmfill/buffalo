# Phase 3 summary — Persons, political information, cross-jurisdiction records

Date: 2026-09-27

Phase 3 is complete:

- **3A:** persons core. Encrypted person records, photos, roles, aliases and associations; links from Phase 2 (traditional rulers, religious leaders).
- **3B:** political offices, senatorial district and LGA leadership pages, persons of interest, criminal groups.
- **3C:** cross-jurisdiction matching, access requests, delegation and grants.

Detailed handovers: `phase-3a.md`, `phase-3b.md` and `phase-3c.md`.

The final checks passed: 910 Pest tests (5312 assertions) plus 13 Vitest tests, lint, CI, and the Phase 3 completion checklist (see `phase-3c.md`).

## Tables added in Phase 3

**Rules shared by the intelligence tables** (persons, POIs, offices, groups):

- `HasJurisdiction` and `LogsBuffaloActivity`;
- a ULID in URLs;
- `state_id` / `lga_id` stored directly, with the composite FK pattern;
- authors, timestamps and soft deletes (deleting needs a reason).

| Table | Phase | Notes |
|---|---|---|
| `persons` | 3A | One row per individual, owned by the recording LGA. Classified (default `restricted`).<br>**Encrypted with CipherSweet:** `nin`, `phone`, `date_of_birth`, `address`. CHECKs reject plaintext.<br>**Blind indexes** (`blind_indexes` table): `nin_bidx`, `phone_bidx`, `dob_bidx`.<br>**`search_name`:** generated column with a trigram GIN index (`persons_search_name_trgm`).<br>Composite FKs to `lgas` and `communities`. Photos in `media` (private `evidence` disk, streamed through a policy). |
| `blind_indexes`, `media` | 3A | Support tables for CipherSweet and spatie/laravel-medialibrary. |
| `person_roles` | 3A | Unique per person and role; roles are deactivated, never deleted. `person_of_interest` is set only by the POI actions. |
| `person_aliases` | 3A | `search_alias`, a generated column with a trigram GIN index. Unique per person, ignoring case and accents. |
| `person_associations` | 3A | One row per pair (a unique index on `LEAST`/`GREATEST`); no self-links. Directional relationships invert when read from the other side. |
| `political_offices` | 3B | Office type rules as CHECKs; composite FKs district → state, LGA → state, ward → LGA. `LgaVisibilityRule`: LGA chiefs also see district-level offices covering their LGA. Visible only if the holder is visible. |
| `persons_of_interest` | 3B | One per person. Composite FK `(person_id, lga_id, state_id)` → `persons` (ON UPDATE CASCADE). Status and risk timeline through named audit events. Review dates and daily reminders (counts only). Visible only if the person is visible. |
| `criminal_groups` | 3B | Classified. Home LGA fixed once created. Trigram name search. |
| `criminal_group_areas` | 3B | Areas of operation in any LGA of any state; they never make the group visible there. |
| `criminal_group_members` | 3B | Unique per group and person. Members whose person the viewer can't see are hidden and not counted. |
| `record_match_events` | 3C | Every shown or suppressed cross-jurisdiction hit: user, subject, owning state and LGA, match type, context, `suppressed`. **Immutable** (UPDATE/DELETE rejected by trigger). |
| `approval_delegations` | 3C | State Director → Deputy State Director, per state: `starts_at`, optional `ends_at`, reason, `revoked_at`/`revoked_by`, `end_reason`. `HasJurisdiction` at state level. |
| `record_access_requests` | 3C | Created only from a hit token.<br>**Columns:** requester and their posting at the time, subject, owning state and LGA, match event, reason (≥ 30 characters), reference, scope, urgency, requested days (7/14/30), status, escalation, decision, granted days and scope.<br>**CHECKs:** granted ≤ requested; scope never widened; rejection needs a note.<br>A partial unique index allows one open request per requester and subject. |
| `record_access_grants` | 3C | One per approved request. Holder, subject, scope, granter, `starts_at`, `expires_at`, `revoked_at`/`revoked_by` and reason, `expiry_reminded_at`, `expiry_notified_at`. |

**Also added:** `communities.traditional_ruler_id` and `religious_institutions.leader_person_id` (3A).

## Permissions (end of Phase 3)

Key: **DG** — Director General; **DDG** — Deputy Director General; **SD** — State Director; **DSD** — Deputy State Director; **LGA** — LGA Security Chief. Super admin holds none of these.

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `persons.view`, `persons.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `persons.delete` | ✓ | ✓ | ✓ | | |
| `political.view`, `political.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `political.delete` | ✓ | ✓ | ✓ | | |
| `poi.view`, `poi.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `poi.clear`, `poi.delete` | ✓ | ✓ | ✓ | | |
| `criminal_groups.view`, `criminal_groups.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `criminal_groups.delete` | ✓ | ✓ | ✓ | | |
| `access_requests.create` | | | ✓ | ✓ | ✓ |
| `access_requests.decide` | | | ✓ | ✓ ¹ | |
| `access_requests.oversee` | ✓ | ✓ | | | |
| `delegations.manage` | | | ✓ | | |

¹ Effective only while the DSD holds an active delegation from their State Director.

Every permission is always combined with jurisdiction and classification in the policies. Writes on persons use the user's **own** jurisdiction (`JurisdictionGate::canAccessOwn`); a grant never allows a write.

## Matching rules and thresholds as implemented

All cross-jurisdiction matching runs in `App\Support\CrossJurisdiction\PersonMatchService`. It is the only class outside `App\Support\Jurisdiction` that calls `runAsSystem()`, and an architecture test enforces that.

**When it runs:**

| Context | Criteria |
|---|---|
| Persons search box (`/persons/search`) | NIN or phone from an 11-digit or phone-shaped term; otherwise the name. No date of birth. |
| New-person form duplicate check (`/persons/similar`) | Names, aliases, NIN, phone, date of birth. Re-checked silently on save; saving despite a hit needs a justification (audited `created_despite_hit`). |

**Match types:**

| Type | Rule | Shown as |
|---|---|---|
| `exact` | Same NIN or same normalised phone (blind index) | "Record found" |
| `strong` | Best trigram similarity of name or alias ≥ **0.75** *and* the same date of birth (blind index) | "Likely match" |
| `possible` | Similarity ≥ **0.6** without the above | Never shown |

**Name search:** trigram `%` against `persons_search_name_trgm` and `person_aliases_search_alias_trgm`, with `pg_trgm.similarity_threshold` set per transaction (`SET LOCAL`). An `EXPLAIN` test proves the index is used over 50,000 rows.

**Filters, in order:**

1. Records the user can already see are returned as normal results, never as hits.
2. National users (DG, all DDGs) get no hits.
3. **Classification suppression:** a record above the searcher's clearance never produces a hit. The event is recorded with `suppressed = true`, and notified once per record per day:
   - `confidential`: the owning State Director;
   - `secret`: the DG and the DDGs covering the owning state.
4. Only `exact` and `strong` are shown, strongest first, at most **10**.

**Other rules:**

- **Hit payload:** `{token, state, category ("Person of interest" / "Person record"), strength}`.
  - No name, ULID, LGA, photo or offence; a test walks the props.
  - The token is `Crypt`-encrypted and carries the person, user, match event, type and expiry. It is valid for **120 minutes**, for the same user only.
- **Daily limits per officer:**
  - **Soft limit (20):** one notification a day to the DG and covering DDGs.
  - **Hard limit (50):** no more hits that day. The UI says so, and the event is audited.
- **Owner digest:** daily at 06:30, to the owning State Director and any delegated deputy. It gives counts by requesting state and officer, with no record details.

**Configuration** (`config/buffalo.php` → `cross_jurisdiction`):

| Setting | Env | Default |
|---|---|---|
| Strong similarity | `BUFFALO_MATCH_STRONG_SIMILARITY` | 0.75 |
| Possible similarity | `BUFFALO_MATCH_POSSIBLE_SIMILARITY` | 0.6 |
| Soft limit | `BUFFALO_MATCH_SOFT_LIMIT` | 20 |
| Hard limit | `BUFFALO_MATCH_HARD_LIMIT` | 50 |
| Escalate urgent after (min) | `BUFFALO_ESCALATE_URGENT_MINUTES` | 120 |
| Escalate normal after (min) | `BUFFALO_ESCALATE_NORMAL_MINUTES` | 2880 |
| Max hits, token minutes, escalated expiry (days), grant reminder (h) | — | 10, 120, 7, 24 |

## Requests, decisions and grants

- **Deciders:**
  - the owning State Director, or a Deputy State Director with an active delegation;
  - once **escalated**, also the DG and the DDGs covering the owning state.

  Nobody decides their own request, and the decider must be cleared for the record.
- **Escalation:** pending urgent requests escalate after 2 hours, normal ones after 48 hours (`buffalo:escalate-access-requests`, every 5 minutes). Escalated requests expire after 7 days.
- **Approval:** may shorten the duration and narrow `full` → `profile`, never widen. A grant starts at approval and lasts the granted number of days.
- **Grants:**
  - read-only and never above the holder's clearance;
  - revocable by the granter, the owning SD, a delegated DSD, the DG and covering DDGs;
  - `buffalo:expire-access-grants` (every 15 minutes) reminds holders 24 hours before expiry and announces expiry;
  - every view is logged in `record_access_logs` with context `grant`.

### Grant scope table

| Scope | Holder sees | Never shown |
|---|---|---|
| `profile` | Name, other names, gender, date of birth or approximate age, nationality, occupation, description, current photo, aliases, roles | NIN, phone, address; LGA, state, community; photo gallery; history; POI, offices, groups, associations; any action |
| `full` | Everything in `profile`, plus NIN, phone, address; POI details and status history; political offices (current and past); group memberships (group name and type only, not linked); associations (other person's name and relationship only, not linked). Groups and associated persons above the holder's clearance are left out. | LGA, state, community; photo gallery; history; links to other records; any action |

A grant makes only the **person** visible through the jurisdiction scope. POI, office and group rows keep their own scopes, so they appear only inside the grant view on the person page (tested).

## Open issues for Phase 4

- **Name-only hits from the search box:** not possible under the current rules, because `strong` needs a date of birth. The owner should decide whether to add an optional date of birth to the search, or to allow `strong` on name alone.
- **Only persons are grantable.** If coverage reports, organizations or agencies need cross-jurisdiction access, extend `GrantAccessResolver` per model and the `subject_type` CHECKs.
- **Models linked to persons:** any new model scoped only through `whereHas('person')` would become visible under a grant. Give it its own `state_id`/`lga_id` unless that is intended. New writes that take a person must check `link` or `update`, never `view`.
- **Revocation is effective on the next request.** Pushing an "access ended" event over Reverb would close open pages at once.
- **Oversight lists are capped** (100–200 rows) without pagination.
- **Search thresholds** (0.3 / 0.5 for local search, 0.6 duplicates, 0.75 strong) are untuned; tune them with real data.
- **Carried over:**
  - duplicate person and POI records cannot be merged;
  - offices cannot be re-assigned;
  - no marker clustering;
  - `clearHistory()` on sign-out, and key backup and rotation (Phase 8).
- **Reference data:** the dev database still uses synthetic districts and LGAs until the owner supplies the CSVs.
