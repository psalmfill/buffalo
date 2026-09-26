# Handover — Phase 3A: Persons core

Date: 2026-09-26

## Summary

BUFFALO now holds person records, its most sensitive data:

- **Access:** jurisdiction-scoped *and* classified (both scopes, both checked in the policy); every view is access-logged.
- **Encryption:** NIN, phone, date of birth and address are encrypted with CipherSweet, with blind indexes for exact search (NIN, phone, DOB).
- **Photos:** EXIF stripped; stored on the private `evidence` disk; streamed only through an authorised route.
- **Related records:** roles, aliases, and two-sided associations.
- **Search:** trigram name and alias search that can use the GIN indexes, plus a duplicate check on create.
- **Phase 2 links:** communities can name a traditional ruler, and religious institutions a leader.
- **Reference usage:** a real `ReferenceUsage` replaces the Phase 2 stub.

Checks:

- `make test`: 776 Pest tests (4431 assertions) plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- Every acceptance item passed in the browser and with `psql`.

Commits:

| Step | Commit |
|---|---|
| 3A.1–3A.7 | `a27a992 feat(persons): add encrypted person records, photos, roles, aliases and associations` |
| — | `docs: add phase 3a handover` (this file, README, `docs/security/encryption-keys.md`, prompt) |

**Why one feature commit:** the permissions and drift tests, the models, the migrations and the pages depend on each other, as in Phase 2.

## Acceptance checklist

| Item | Result |
|---|---|
| Create a person with NIN, phone and photo; ciphertext in the database (`psql`) | ✅ **Created:** as `chief.lagos`, "Ibrahim Kayode Olawale" in Demo LGA LA-1-1 / Ajegunle Waterside, with NIN, phone `0803 222 7788`, DOB, address, the traditional ruler role, and a JPEG carrying a GPS/EXIF marker.<br>**`psql`:** `nin`, `phone`, `date_of_birth` and `address` all hold `nacl:…` ciphertext. `blind_indexes` has `dob_bidx`, `nin_bidx` and `phone_bidx`.<br>**Photo:** Horizon generated `thumb` and `preview`. The marker is absent from the original and both conversions. Files are under `photos/{media-ulid}/`. The page showed the phone as `+2348032227788`. |
| Find by NIN, by a phone written differently, by a misspelled name | ✅ `52345678901` → found ("Searching by NIN (exact match)"). `+234 (803) 222-7788` → found. `Ibraheem Olawalle` → found. The URL stayed `/persons/search` (POST), so no search term appears in a URL. |
| Viewing creates an access log entry; the audit history shows changes without encrypted values | ✅ **Edit:** changed the NIN and the occupation.<br>**History:** shows `updated` (occupation Fisherman → Boat operator) and "Protected fields changed: NIN", with `fields: ["nin"]` and no values. A text search of the history page for both NINs, the phone, the DOB and the address found none.<br>**Access log:** `record_access_logs` has one `view` row per page view (`tab:profile`, `tab:profile`, `tab:history`). |
| A Kano user gets 404 on the Lagos person and their photo URL | ✅ As `sd.kano` (dashboard 200):<br>- the person page → 404 page;<br>- the original, `/thumb` and `/preview` photo URLs → 404;<br>- picker searches by name and by NIN → 0 results. |
| `make test` and `make lint` pass | ✅ (and `make ci`) |

## What was built

### 3A.1 — Packages and keys

- **`spatie/laravel-ciphersweet`**: config published; backend `nacl`; key from `CIPHERSWEET_KEY`.
  - **`buffalo:encryption-key`:** creates the key in `.env` for a new installation and **refuses if a key exists**. It is used by `make setup` and `scripts/ci.sh`.
  - **The package's `ciphersweet:generate-key` is disabled** (`DisabledCipherSweetGenerateKeyCommand`), because it silently overwrites an existing key outside production.
  - **Missing key:** the app throws a clear error before any encryption.
  - **Tests** use a fixed test-only key in `phpunit.xml`.
  - **Docs:** a prominent warning in the README, `.env.example` and `docs/security/encryption-keys.md`.
- **`spatie/laravel-medialibrary`**: `config/media-library.php` is trimmed and locked down:
  - disk and conversions disk are `evidence`;
  - no optimiser binaries; only the Image and WebP generators;
  - conversions are queued after commit.
  - **`App\Models\Media`** adds a `ulid` (used in URLs), hides numeric keys and drops the package's `original_url`/`preview_url` attributes.
  - **`EvidencePathGenerator`** stores files under `{collection}/{media-ulid}/`.
- **Photos** (`PhotoSanitiser`):
  - the type is decided from content (`getimagesize`): JPEG, PNG or WebP;
  - at most 6000 px per side and 10 MB;
  - **re-encoded with GD**, which removes EXIF (including GPS), XMP, IPTC and PNG text chunks, and anything hidden in the file;
  - EXIF orientation is applied first;
  - the uploaded file name is never kept (`photo.jpg`).
- **Architecture test:** no `getUrl(`, `getFullUrl(` or `getTemporaryUrl(` in `app/` or `resources/`.

### 3A.2 — Persons

- **Migration `2026_10_01_000003_create_persons_tables`**, table `persons` with the prompt's columns plus:
  - composite FKs `(lga_id, state_id)` → `lgas` and `(community_id, lga_id)` → `communities`;
  - CHECKs on gender, classification, age 0–130, and non-blank names;
  - **CHECKs that `nin`, `phone`, `date_of_birth` and `address` are NULL or CipherSweet ciphertext**, so a raw query can never store them in clear;
  - `search_name` as a **generated column** (`buffalo_search_text()`: unaccent, lowercase, trim, single spaces) with a trigram GIN index.
- **`App\Models\Person`**:
  - traits: `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads`, `InteractsWithMedia`, `UsesCipherSweet`;
  - **blind indexes** `dob_bidx`, `nin_bidx` and `phone_bidx` (keyed hash, 256 bits, exact match only). NULL values are not indexed, and clearing a field removes its index;
  - **phone and NIN mutators** normalise to `+234XXXXXXXXXX` and 11 digits, or throw;
  - **saving guard:** a partially loaded person can't be saved (it would NULL the encrypted columns);
  - **audit:** encrypted fields are excluded from the activity log. A named event `protected_fields_changed` records *which* fields were set or changed, never their values;
  - **hidden** from serialisation: the protected fields, `search_name` and numeric ids;
  - `name` accessor, `currentPhoto()` (newest in `photos`), `holdsRole()`.
- **Access logging:** every person page view (each tab) writes `record_access_logs` with context `tab:<name>`.

### 3A.3 — Roles, aliases, associations

- **`person_roles`**:
  - unique per `(person_id, role)`;
  - an unchecked role is **deactivated**, not deleted;
  - `person_of_interest` is rejected by the request and the Action, and is preserved untouched on edit.
- **`person_aliases`**: `search_alias` is a generated column with a trigram GIN index; a partial unique index prevents duplicates per person (case- and accent-insensitive); ULID; soft deletes.
- **`person_associations`**:
  - stored once per pair: a partial unique index on `(LEAST, GREATEST)`, plus a CHECK against self-links; ULID; soft deletes;
  - a row reads "associated person is the person's *relationship*"; seen from the other side, directional relationships invert (employer ↔ employee);
  - both persons must be visible to the user to link them. Links to persons the viewer can't see are not shown and can't be removed by that viewer;
  - adding or removing a link logs named events on **both** persons.
- **History:** the person's History tab shows the person's entries plus those of their roles and aliases.

### 3A.4 — Links from Phase 2

- **Columns:** `communities.traditional_ruler_id` and `religious_institutions.leader_person_id` (nullable FKs to `persons`).
- **Validation:** `PersonWithRole` requires a person the user can view (both scopes plus the policy) holding the role actively. The current value stays valid if the role was later deactivated.
- **Hidden persons:** if the linked person isn't visible to the viewer, the page says "A person you cannot view is recorded". The form keeps the link unless the user chooses **Replace**; an absent field means unchanged.
- **Audit:** changes are logged as `traditional_ruler_changed` / `leader_changed`, with **ULIDs only** (or "not visible"), because the record's history may be read by users who can't see the person.
- **Form:** both Phase 2 forms have the picker (`LinkedPersonField` → `PersonPicker`).

### 3A.4b — Reference usage and index-friendly search

- **`ReferenceUsageRegistry`** replaces `NoReferenceUsage`:
  - modules register checks (`column()`, `jsonArray()`, or a closure);
  - Community Information registers religions and denominations (`ReferenceUsageChecks`);
  - checks are yes/no across all jurisdictions and count soft-deleted rows.
- **`PersonSearch`**:
  - filters with `%` (similarity ≥ 0.3) and `<%` (word similarity ≥ 0.5, for a partial name such as a surname) on names and aliases;
  - thresholds are set per transaction (`set_config(…, true)` = `SET LOCAL`);
  - results are ordered by the best `similarity`/`word_similarity` of the name or any alias.
- **EXPLAIN test:**
  - **Default planner, realistic volume:** with 50,000 persons it uses `persons_search_name_trgm`.
  - **Full scans ruled out:** the name and alias conditions use `persons_search_name_trgm` and `person_aliases_search_alias_trgm`. See the deviations.
- **Composite FK pattern** as in Phase 2.

### 3A.5 — Permissions

`persons.view` and `persons.manage` go to every intelligence role; `persons.delete` to the DG, DDG and State Director. The super admin gets none. Roles, aliases, associations and photos use `update`.

### 3A.6 — UI

- **Navigation:** new **Intelligence → Persons** section.
- **`/persons`** (search page):
  - one box for name, NIN or phone; the kind of search is detected and shown;
  - filters for role, state, LGA, gender and classification;
  - results show photo thumbnail, name, roles, LGA and classification;
  - an empty search shows the 20 most recently updated persons, and results are capped at 50 with a hint;
  - a separate area is reserved for 3C hit notices (`data-test="cross-jurisdiction-hits"`).
- **`/persons/create`**: all fields; roles as checkboxes with notes; photo. Before saving, **possible duplicates** open in a dialog with reasons (same NIN, same phone, similar name, same date of birth) and two choices: "This is the same person — open existing record" or "Create new record anyway". The server re-checks (`confirm_new`).
- **`/persons/{ulid}`** tabs:
  - **Profile:** decrypted protected fields marked "(encrypted)", roles (former ones struck through), aliases;
  - **Aliases:** add and remove;
  - **Associations:** picker, relationship and notes; shown from both sides; removal;
  - **Photos:** grid, current badge, upload;
  - **History.**
  - `PersonController::TABS` leaves room for 3B and Phase 5 tabs.
- **Photo route `/persons/{ulid}/photos/{media_ulid}/{thumb|preview?}`**:
  - checks the person policy, that the media belongs to that person, and the collection;
  - headers: `Cache-Control: private, no-store`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff`, and CSP `default-src 'none'; sandbox`;
  - serves the (clean) original until the conversion exists.
- **Encrypted history:** person pages use Inertia history encryption (`Inertia::encryptHistory()`), so names and protected fields aren't readable in the browser's history state.

### 3A.7 — Tests

In `tests/Feature/Persons/`:

- **`PersonEncryptionTest`:**
  - values are ciphertext in the database, and the database refuses clear text;
  - blind indexes give exact matches only, and are removed when a value is cleared;
  - phone normalisation;
  - activity log entries carry neither values nor blind indexes;
  - a partially loaded person cannot be saved;
  - serialisation hides the protected fields;
  - `search_name` on create and update.
- **`PersonPhotoTest`:**
  - EXIF/GPS stripped from the original and both conversions, stored on `evidence` under the ULID;
  - content validation (a PHP or text file named `.jpg`, SVG, GIF and oversize files are rejected);
  - the current photo and previous photos;
  - streaming headers;
  - 404 outside jurisdiction or classification, for a foreign media item, and for an unknown conversion;
  - the `getUrl` architecture test, and no URL attributes on media.
- **`PersonsTest`:**
  - the jurisdiction matrix, and the classification matrix with the creator exception;
  - the policy for every role (own LGA, other LGA, other state);
  - create and edit, LGA and community rules, validation, and no flashing of protected input;
  - POI blocked and preserved; role deactivation and reactivation; LGA fixed; clearance on edit;
  - the duplicate check (by name, alias, NIN, phone and DOB, with out-of-jurisdiction persons excluded), and confirmation on create;
  - search (NIN, three phone formats, misspelled name, surname only, alias) and filters; the recent list; GET search redirects;
  - EXPLAIN index use;
  - an access log entry per view;
  - no protected values or blind indexes in other pages' props, and none in the application log;
  - deletion rules with a reason;
  - aliases and history.
- **`PersonLinksTest`:**
  - associations: both sides with inversion, no self-link or duplicate pair (in both the app and the database), both persons visible, hidden links, removal and re-linking;
  - the ruler and leader role requirements, and a hidden ruler kept;
  - the picker (visible persons only, role filter, POST only);
  - reference usage, including in other jurisdictions and for soft-deleted records; module registration.

## Deviations and conflicts

- **Blind indexes live in the package's `blind_indexes` table**, one row per record and index name (`dob_bidx`, `nin_bidx`, `phone_bidx`), not in columns on `persons`. This is how `spatie/laravel-ciphersweet` works.
  - A **fast keyed hash** is used: the key protects it, and a slow hash adds nothing while the key is secret.
  - NULL values are **not** indexed (the package would index them).
  - On soft delete the package removes the person's blind index rows; `restore()` rebuilds them.
- **`AuditExclusions::SUFFIXES` (`_encrypted`, `_bidx`) from Phase 1 does not apply.** The package keeps ciphertext in the plain column names (`nin`, …), so the exclusion is per model (`Person::auditExcept()`).
- **`search_name` and `search_alias` are generated columns** rather than "maintained on save" in PHP: they can never drift, even after raw updates.
- **Name search also uses `<%` (word similarity).** With `%` alone a surname-only search misses: similarity("okonkwo", full name) is below 0.3. Both operators are served by the same GIN index.
- **The EXPLAIN test** proves the persons index is chosen by the default planner at 50,000 rows. For aliases, narrow rows make a sequential scan cheaper at any volume a test can create, so the test rules out full scans and asserts both trigram indexes serve the conditions. In `psql`, with 50,000 aliases, a plain `search_alias % 'okonkwo'` query already chose `person_aliases_search_alias_trgm`.
- **11-digit numbers starting with 0** are valid local phone numbers (`0803…`), so they are searched as **both** NIN and phone. The prompt's "11 digits → NIN" would miss `08031234567`.
- **Searches, the duplicate check and the picker are POST**, not GET, so names, NINs and phone numbers never appear in URLs, nginx access logs or browser history. `GET /persons/search` redirects to `/persons`, and results are capped at 50 rather than paginated.
- **Aliases and associations have a ULID and soft deletes** (the prompt lists neither): ULIDs because removal URLs must not expose ids; soft deletes because of `CLAUDE.md` ("never hard-delete operational records"). Uniqueness is enforced by partial indexes on non-deleted rows.
- **Roles are deactivated, not deleted**, when unchecked. `person_roles` has no `updated_by`, per the prompt; the activity log records who changed it.
- **Photos are re-encoded** rather than having only EXIF removed. This is safer (it also removes XMP, IPTC and hidden payloads) and costs a little JPEG quality (quality 90). Other decisions:
  - a 6000 px limit per side, because GD decodes in memory;
  - conversions are JPEG;
  - there is **no photo deletion** (not requested).
- **Photo streams are not access-logged.** Search results load thumbnails for up to 50 persons, which would flood `record_access_logs`. Every *person page* view is logged, including tab changes. Please confirm.
- **A person's LGA is fixed once created**, like communities, because moving it changes who can see the record. The community can change within the LGA.
- **Date of birth alone counts as a possible duplicate**, as the prompt says. It will be noisy in large LGAs; NIN and phone matches are ranked first.
- **Extra hardening beyond the prompt:**
  - the ciphertext CHECK constraints;
  - `dontFlash` for `nin`, `phone`, `address` and `date_of_birth` (validation errors would otherwise store them in the Valkey session);
  - Inertia history encryption on person pages;
  - `X-Content-Type-Options` and a sandbox CSP on photos;
  - JSON responses marked `private, no-store`.
- **Deletion** uses `DeleteWithReason` (event `person_deleted`) and `DeleteRecordRequest`. As with every Phase 2 delete, **the Deputy State Director cannot delete**, which matches the prompt's table.

## New packages

| Package | Version | Licence | Why |
|---|---|---|---|
| `spatie/laravel-ciphersweet` | 1.9.0 | MIT | Named in the prompt |
| `paragonie/ciphersweet` | 4.10.0 | ISC | Dependency of the above |
| `paragonie/sodium_compat` | 2.5.2 | ISC | Dependency of the above |
| `spatie/laravel-medialibrary` | 11.23.8 | MIT | Named in the prompt |
| `spatie/image` | 3.9.6 | MIT | Dependency (conversions, GD driver) |
| `spatie/image-optimizer` | 1.10.0 | MIT | Dependency; unused (no optimisers configured) |
| `spatie/temporary-directory` | 2.4.0 | MIT | Dependency |
| `maennchen/zipstream-php` | 3.2.2 | MIT | Dependency (zip downloads; unused) |
| `composer/semver` | 3.5.0 | MIT | Dependency |

No npm packages.

## Known issues and notes

- **Browser history on sign-out:** Inertia's encrypted-history key lives in `sessionStorage` until the tab closes; signing out doesn't call `clearHistory()` yet. Add it with the Phase 8 session hardening.
- **Key backup and rotation** are not implemented (Phase 8). **Back up the dev key** if the dev data matters.
- **No merging of duplicate persons** (a later phase).
- **Search thresholds** (0.3 similarity, 0.5 word similarity, 0.6 for duplicates) are constants in `PersonSearch`; tune them with real data.
- **Dev database changes:**
  - `CIPHERSWEET_KEY` in `.env` (created by `buffalo:encryption-key`);
  - person "Ibrahim Kayode Olawale" (Demo LGA LA-1-1, traditional ruler, with a photo, NIN since changed to `52345678999`);
  - the new permissions were seeded and Horizon restarted;
  - `make fresh` resets the data but **keeps the key**.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next steps

- **3B (POI, political offices):**
  - Set the `person_of_interest` role through `PersonRole` directly (the 3A forms preserve it).
  - Register threat types with `ReferenceUsageRegistry::jsonArray(ThreatType::class, 'persons_of_interest', 'offence_categories')`, which matches **numeric ids** in the JSON array; add a variant if you store ULIDs.
  - Add tabs to `PersonController::TABS` and `TAB_LABELS` in `pages/persons/show.tsx`.
- **3C (cross-jurisdiction):**
  - `PersonPolicy::update`/`delete` use `JurisdictionGate::canAccess`, which will include grants; **grants are read-only**, so they must then require the user's own jurisdiction.
  - The national match check can reuse `PersonSearch` (names and aliases) and `Person::blindIndexFor()` / `whereBlind` (NIN, phone, DOB) inside `JurisdictionContext::runAsSystem()`, the one documented bypass.
  - Show hits in the reserved area of `pages/persons/index.tsx`, and extend `SimilarPersons` for cross-jurisdiction duplicates.
- **Phase 5 (evidence):** reuse the `evidence` disk config, `App\Models\Media`, `EvidencePathGenerator` and the streaming pattern of `PersonPhotoController`, and register incident usage checks in `ReferenceUsageRegistry`.
