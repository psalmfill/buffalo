# Phase 2 summary — Reference data and LGA information

Date: 2026-09-26

Phase 2 is complete:

- **2A:** reference data and dynamic fields.
- **2B:** LGA profiles and communities.
- **2C:** community information and classification.

Detailed handovers: `phase-2a.md`, `phase-2b.md` and `phase-2c.md`.

The final checks passed: 670 Pest tests (3796 assertions) plus 13 Vitest tests, lint, CI, and the Phase 2 completion checklist in the browser.

## Tables added in Phase 2

### Reference data (2A): not jurisdiction-scoped, never deleted

These six tables are administrator-extensible lookups. They share the columns `id`, `ulid`, `name`, `code` (fixed once created), `description`, `sort_order`, `is_active`, authors and timestamps. Triggers reject `DELETE` and `TRUNCATE`; values are deactivated instead.

| Table | Extra |
|---|---|
| `incident_types` | `category`, `default_severity`, `details_schema` (jsonb, validated by `FieldDefinition`), `schema_version` |
| `threat_types` | `category` |
| `security_agencies` | `short_name`, `agency_type`, `state_id` (only for state agencies) |
| `religions` | — |
| `denominations` | `religion_id` (fixed); code unique within the religion; `UNIQUE (id, religion_id)` added in 2C |
| `property_types` | — |

### Intelligence content (2B, 2C)

**Rules shared by all five tables:**

- `HasJurisdiction` and `LogsBuffaloActivity`;
- a ULID in URLs;
- `state_id` and `lga_id` stored directly, with a composite FK `(lga_id, state_id)` → `lgas(id, state_id)`;
- authors, timestamps and soft deletes (deleting requires a reason, which is audited).

| Table | Phase | Notes |
|---|---|---|
| `lga_profiles` | 2B | One per LGA (created on first edit). `terrain` jsonb, review date and reviewer. Completeness is computed (six items). |
| `communities` | 2B | Ward must be in the LGA (composite FK). Case-insensitive unique name per LGA. `search_names` generated column with trigram GIN indexes (fuzzy search). Point location with boundary trigger. `UNIQUE (id, lga_id)` for child tables. |
| `religious_institutions` | 2C | Community required (same LGA, composite FK). The denomination must belong to the religion (composite FK). Point location with boundary trigger. |
| `vulnerable_population_stats` | 2C | **Aggregate counts only** (four counts, category, date, source; no names or notes). `community_id` null = LGA-wide. One figure per place, category and date (unique `NULLS NOT DISTINCT`). |
| `community_security_notes` | 2C | **Classified** (`classification`, default restricted). Status active / monitoring / resolved (resolved requires `resolved_at`). Reads of confidential and secret notes are logged in `record_access_logs`. |

**Also added:**

- the `lgas` columns `headquarters`, `area_km2` and `population_estimate` (edited through the profile);
- the database functions `buffalo_unaccent()`, `buffalo_community_location_in_lga()` and `buffalo_reject_reference_delete()`.

## Permissions (end of Phase 2)

Key:

- **DG** — Director General;
- **DDG** — Deputy Director General;
- **SD** — State Director;
- **DSD** — Deputy State Director;
- **LGA** — LGA Security Chief;
- **SA** — Super Admin.

Permissions decide *what* a user may do. Jurisdiction and classification decide *which records*.

| Permission | SA | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `users.view` | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `users.manage` | ✓ | | | | | |
| `audit.view` | ✓ | ✓ | | | | |
| `login_history.view` | ✓ | | | | | |
| `system.horizon`, `system.settings` | ✓ | | | | | |
| `reference_data.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `reference_data.manage` | ✓ | ✓ | | | | |
| `lgas.view`, `lga_profiles.update` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `communities.view`, `communities.manage` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `communities.import` | | ✓ | | ✓ | ✓ | |
| `communities.delete` | | ✓ | ✓ | ✓ | | |
| `religious_institutions.view`, `.manage` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `religious_institutions.delete` | | ✓ | ✓ | ✓ | | |
| `vulnerable_stats.view`, `.manage` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `vulnerable_stats.delete` | | ✓ | ✓ | ✓ | | |
| `security_notes.view`, `.manage` | | ✓ | ✓ | ✓ | ✓ | ✓ |
| `security_notes.delete` | | ✓ | ✓ | ✓ | | |

**Source of truth:** `RolesAndPermissionsSeeder::matrix()`. Drift tests keep `resources/js/types/auth.ts` and the navigation test's role table in step with it.

## Classification rules as implemented

These implement `CLAUDE.md` section 4a (`App\Support\Classification`).

1. **Levels:** `restricted` < `confidential` < `secret` (`App\Enums\Classification`).
2. **Clearance** (`ClassificationGate::maxLevelFor`):

   | Role | Maximum level |
   |---|---|
   | DG, DDG, State Director | secret |
   | Deputy State Director | confidential |
   | LGA Security Chief | restricted |
   | Super admin, no role, inactive / locked / deleted account | none (sees no classified records) |

3. **Seeing a record** (`canSee`): the record's level is at or below the user's clearance, or the user created it. With no clearance, the answer is always no, including for the creator.
4. **Assigning a level** (`canAssign`):
   - the level must be at or below the user's clearance;
   - this applies on create and on edit;
   - the form offers only those levels;
   - otherwise the server rejects with "You cannot classify a record above your own clearance."
5. **Two scopes, both always on.** `ClassificationScope` (from `HasClassification`) runs as well as the jurisdiction scope:
   - **no context** → no rows;
   - **system context** → all rows;
   - **user context** → `classification IN (levels up to clearance) OR created_by = user`.
6. **Policies:** they check the permission, then jurisdiction (`JurisdictionGate`), then `canSee`. A record out of reach returns 404 through the route binding and scopes.
7. **Audit:**
   - every level change is logged as `classification_changed` with the old and new levels;
   - each read of a confidential or secret record writes a `record_access_logs` row.
8. **Aggregates respect classification:** dashboard counts are computed through both scopes.
9. **Currently classified:** only `community_security_notes`. Religious institutions and vulnerable figures are unclassified, but still jurisdiction-scoped.

## Open issues for Phase 3

1. **`ReferenceUsage` needs a real binding.** It is still `NoReferenceUsage`, although religious institutions now use religions and denominations. Until it is bound, values in use can be changed as if unused (schema changes and deactivation warnings). Bind it when persons arrive.
2. **Deferred columns:**
   - `communities.traditional_ruler_id` was deferred to Phase 3, when persons exist;
   - consider the same for any "leader person" links.
3. **Cross-jurisdiction grants** (`CLAUDE.md` 4.8):
   - the jurisdiction scope must admit records covered by an active grant, through the `AccessGrantResolver` hook in `app/Support/Jurisdiction`;
   - classification must still apply, which is the recommended reading; please confirm.
4. **Owner-supplied reference data is still missing:**
   - the senatorial district, LGA and ward CSVs (the dev database uses demo LGAs);
   - LGA boundaries, so the location-in-LGA triggers are dormant.
5. **The database superuser can disable triggers** (reference delete protection and boundary checks). The application role in production should not be a superuser; this is Phase 8 hardening.
6. **Fuzzy search threshold:** community search uses `word_similarity` without `<%`, so it does not use the trigram index. That is fine at LGA and state scale; revisit it for national person search.
7. **No map marker clustering.** LGA maps show at most 1000 markers.
8. **Decisions to confirm:**
   - **Deputy State Director and deletes:** the DSD is excluded from all `*.delete` permissions.
   - **Vulnerable figures:** LGA-wide rows are the authoritative LGA figure; community rows are breakdowns and are not summed.
   - **`security_agencies.state_id`** is not jurisdiction-scoped (it is reference data).
   - **Reference `code` values** are fixed once created (not "once used").
9. **Future dates** for `as_of_date` and `observed_at` are blocked by validation only, not by the database.
