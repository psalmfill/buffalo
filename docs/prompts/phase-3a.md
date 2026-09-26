# BUFFALO — Phase 3A: Persons Core

Read `CLAUDE.md` before starting (especially sections 4, 4a, 5 and 6). Read `docs/handover/phase-2-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phases 1 and 2 are complete, including jurisdiction scoping, classification (`ClassificationGate`, `HasClassification`), audit logging, the record access log and the `AccessGrantResolver` extension point (currently a null implementation).

Phase 3 is the most sensitive module. It is split into three steps:

- **3A (this step):** the persons record itself, encryption, photos, aliases, roles and associations.
- **3B:** political offices, senatorial district pages, persons of interest, criminal groups.
- **3C:** cross-jurisdiction matching, access requests, delegation and grants.

In this step, persons are visible **only within jurisdiction**. No cross-jurisdiction behaviour yet.

---

## Step 3A.1 — Packages

1. `spatie/laravel-ciphersweet` (MIT) for field encryption with blind indexes.
   - Key from `CIPHERSWEET_KEY` in `.env`. Add a generator to `make setup` for local development.
   - Add a prominent section to `README.md` and `docs/security/encryption-keys.md`: **losing this key makes all encrypted person data unrecoverable; it must be backed up separately from the database, and never committed.** Phase 8 builds key backup procedures.
2. `spatie/laravel-medialibrary` (MIT) for person photos (and later, incident evidence).
   - All media on the private `evidence` disk. **No public URLs**: never call `getUrl()`; files are streamed only through authorised controllers. Add an architecture test forbidding `getUrl(` and `getFullUrl(` in `app/` and `resources/`.
   - Image conversions (thumbnail 200px, preview 800px) generated on the queue, also on the `evidence` disk.
   - Allowed photo types: JPEG, PNG, WebP; max 10 MB; validated by content, not extension. Strip EXIF metadata from stored images (location data in photos must not leak).

## Step 3A.2 — Persons table

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id` | owning jurisdiction (where the record was created); required |
| `community_id` | nullable; must belong to the LGA |
| `first_name`, `last_name` | required |
| `other_names` | nullable |
| `search_name` | maintained on save: `lower(unaccent(first other last))`, whitespace-normalised. Trigram GIN index. |
| `gender` | enum: `male`, `female`, `unknown` |
| `date_of_birth` | **encrypted**, blind index `dob_bidx` |
| `approximate_age` | nullable integer, for when DOB is unknown |
| `nin` | **encrypted**, blind index `nin_bidx`; exactly 11 digits when present |
| `phone` | **encrypted**, blind index `phone_bidx`; normalised to `+234XXXXXXXXXX` before encryption and indexing |
| `address` | **encrypted**, no blind index |
| `occupation` | nullable |
| `nationality` | default `Nigerian` |
| `description` | text, nullable (physical description, distinguishing marks) |
| `classification` | enum, default `restricted` |
| `is_deceased` | boolean, default false |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- Traits: `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` (**every** view of a person is access-logged, regardless of classification), `InteractsWithMedia`.
- Encrypted fields and blind indexes are excluded from activity log properties (CLAUDE.md section 5). The activity log records *that* an encrypted field changed, not its value.
- A person can have one current photo plus previous photos (media collection `photos`; newest is current).
- Search by NIN or phone uses the blind index only (exact match). Search by name uses `search_name` trigram similarity.

## Step 3A.3 — Person roles, aliases and associations

**`person_roles`**: `person_id`, `role` (enum: `political_leader`, `traditional_ruler`, `religious_leader`, `community_leader`, `person_of_interest`), `is_active`, `notes` (nullable), `created_by`, timestamps. Unique (`person_id`, `role`). A person may hold several roles. The `person_of_interest` role is managed only through the POI module in 3B; block it here.

**`person_aliases`**: `person_id`, `alias`, `search_alias` (normalised like `search_name`, trigram index), `created_by`, timestamps.

**`person_associations`**: `person_id`, `associated_person_id`, `relationship` (enum: `family`, `associate`, `accomplice`, `employer`, `employee`, `political_ally`, `rival`, `other`), `notes` (nullable), `created_by`, timestamps.
- Stored once per pair; displayed from both sides (relationship shown from each person's perspective where directional, e.g. employer/employee).
- A user can link only two persons they can both currently see.
- No self-association; no duplicate pair.

All three log activity. Changes appear on the person's audit history.

## Step 3A.4 — Links from Phase 2

- Add `traditional_ruler_id` (nullable FK → persons) to `communities`. The person must hold the `traditional_ruler` role.
- Add `leader_person_id` (nullable FK → persons) to `religious_institutions`. The person must hold the `religious_leader` role.
- Both use a person picker limited to persons the user can see.

## Step 3A.4b — Reference usage and index-friendly search

1. Replace the `NoReferenceUsage` binding (Phase 2 open issue) with a real `ReferenceUsage` implementation that reports whether a lookup value is in use, by checking the tables that reference it: `religious_institutions` (religions, denominations) now, and `persons_of_interest.offence_categories` (threat types) once 3B adds it. Design it so each module registers its own usage checks, so Phases 5 and 6 can add incident types, agencies and property types without editing a central list.
2. Person name and alias search runs nationwide in 3C, so it must use the trigram GIN indexes: filter with the `%` operator after setting `pg_trgm.similarity_threshold` for the query (`SET LOCAL` inside a transaction), then order by `similarity()`. Add a test that runs `EXPLAIN` on the search query and asserts the trigram index is used.
3. Follow the Phase 2 composite foreign key pattern `(lga_id, state_id)` → `lgas(id, state_id)` for `persons`, and `(community_id, lga_id)` for the community link.

## Step 3A.5 — Permissions

| Permission | DG | DDG | State Dir. | Deputy | LGA Chief |
|---|---|---|---|---|---|
| `persons.view` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `persons.manage` | ✅ | ✅ | ✅ | ✅ | ✅ |
| `persons.delete` | ✅ | ✅ | ✅ | | |

Super admin: none. Deletion is a soft delete with a mandatory reason, logged. All subject to jurisdiction and classification.

## Step 3A.6 — UI

Navigation: new section **"Intelligence"** with item "Persons" (`persons.view`).

**`/persons`** — search page, not a browse-everything list:
- Search box accepting a name, NIN or phone number (detect which: 11 digits → NIN; phone pattern → phone; otherwise name).
- Filters: role, state, LGA, gender, classification.
- Results: photo thumbnail, name, roles, LGA, classification badge.
- An empty search shows the 20 most recently updated persons within jurisdiction.
- Leave a clearly separated area below results where 3C will show cross-jurisdiction hit notices.

**`/persons/create`** — form with all fields; roles as checkboxes (excluding POI). Before saving, show **similar persons within jurisdiction** (trigram similarity ≥ 0.6 on name or alias, or exact NIN/phone/DOB-blind-index match) with "This is the same person — open existing record" or "Create new record anyway". Cross-jurisdiction checks are added in 3C.

**`/persons/{ulid}`** — tabs:
- **Profile:** details, photo (streamed via authorised route), roles, classification badge. Encrypted fields are displayed decrypted only on this page and only to users passing the policy.
- **Aliases**
- **Associations:** list and add/remove, with person picker.
- **Photos:** current and previous, upload new.
- **History:** activity log entries for this person.
- Leave room for tabs added in 3B (political offices, POI details, group memberships) and Phase 5 (linked incidents).

Photo route: `/persons/{ulid}/photos/{media_ulid}/{conversion?}` streams the file after policy check, with `Cache-Control: private, no-store` and `Content-Disposition: inline`.

Community and religious institution forms from Phase 2 gain the traditional ruler and leader pickers.

Run `make wayfinder` after route changes.

## Step 3A.7 — Tests

- Encryption: NIN, phone, DOB and address are ciphertext in the database; blind index lookups find exact matches; phone normalisation makes `0803…`, `+234803…` and `234803…` match.
- Encrypted values and blind indexes never appear in activity log entries, Inertia props for other pages, or application logs.
- `search_name` maintained on create and update; fuzzy name and alias search finds misspellings.
- Every person view writes a `record_access_logs` row.
- Scope and classification matrix for persons (reuse helpers).
- Photos: content-type validation, EXIF stripped, stored on `evidence`, streamed only through the authorised route, 404 for out-of-jurisdiction users, no `getUrl()` anywhere (architecture test).
- POI role cannot be assigned through 3A routes.
- Associations: both persons must be visible; no self-link; no duplicate pair; displayed from both sides.
- Traditional ruler and religious leader links require the matching role.
- Deletion rules with reason.
- In-jurisdiction duplicate warning on create.

## Acceptance checklist

- [ ] Create a person with NIN, phone and photo; confirm ciphertext in the database with `psql`.
- [ ] Find the person by NIN, by phone written differently, and by a misspelled name.
- [ ] Viewing the person creates an access log entry; the audit history shows field changes without revealing encrypted values.
- [ ] A Kano user gets 404 on the Lagos person and their photo URL.
- [ ] `make test` and `make lint` pass.

## Out of scope

Political offices, POI details, criminal groups (3B). Any cross-jurisdiction matching, hit notices, access requests (3C). Merging duplicate persons (later phase).

## Handover

Write `docs/handover/phase-3a.md` per CLAUDE.md section 9 and print it.
