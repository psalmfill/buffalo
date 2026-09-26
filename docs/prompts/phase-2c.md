# BUFFALO — Phase 2C: Community Information and Classification

Read `CLAUDE.md` before starting (especially section 4a, Classification rules). Read `docs/handover/phase-2b.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 2B delivered LGA profiles, communities, community import and the map picker. This step adds the remaining community information from the brief (religious groups, vulnerable persons, security-related community information) and builds the `ClassificationGate`, which every classified record in later phases will use.

---

## Step 2C.1 — Classification

1. `Classification` enum: `restricted`, `confidential`, `secret`, with `label()` and ordering.
2. `ClassificationGate` in `app/Support/Classification/`:
   - `maxLevelFor(User $user): ?Classification` per CLAUDE.md 4a (DG, DDG, State Director → `secret`; Deputy State Director → `confidential`; LGA chief → `restricted`; super admin → `null`, meaning no intelligence access).
   - `canSee(User $user, Model $record): bool` — the record's level is within the user's maximum, **or** the user is the record's creator.
   - `canAssign(User $user, Classification $level): bool` — users cannot create or raise records above their own maximum.
3. `HasClassification` model trait adding a global scope: records above the user's maximum are excluded unless `created_by` is the user. It must compose with `JurisdictionScope` (both must pass). Same context rules as jurisdiction: `none` sees nothing, `system` sees all.
4. Lowering or raising a record's classification is logged as a named activity event with old and new levels.
5. UI: a reusable `ClassificationBadge` component and a `ClassificationSelect` that offers only levels the user may assign.

## Step 2C.2 — Religious institutions

`religious_institutions` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id`, `community_id` | community required; must belong to the LGA |
| `religion_id`, `denomination_id` | denomination nullable; must belong to the religion |
| `name` | required |
| `type` | enum: `church`, `mosque`, `shrine`, `islamiyya_school`, `mission_school`, `other` |
| `estimated_congregation` | integer, nullable |
| `location` | nullable point |
| `notes` | text, nullable |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- Do **not** add `leader_person_id` yet (Phase 3 adds it).
- `HasJurisdiction`, `LogsBuffaloActivity`. Not classified (always `restricted`).
- "Number and types of religious groups" is computed from this table, never stored.

## Step 2C.3 — Vulnerable population statistics

**Aggregate counts only. This table must never hold names or any identifying information.**

`vulnerable_population_stats` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id` | required |
| `community_id` | nullable (null means an LGA-wide figure) |
| `category` | enum: `mental_health_condition`, `physical_disability`, `elderly_without_support`, `unaccompanied_minors`, `internally_displaced`, `other` |
| `adult_male`, `adult_female`, `child_male`, `child_female` | integers, ≥ 0, default 0 |
| `as_of_date` | date, required, not in the future |
| `source` | short string (max 150), e.g. "LGA social welfare office" |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- Unique on (`lga_id`, `community_id`, `category`, `as_of_date`), treating null `community_id` correctly (use a partial index or `NULLS NOT DISTINCT`).
- **No free-text notes field**, by design: free text invites names. Show help text on the form stating that no names or identifying details may be recorded.
- History is kept: new figures are added as new rows with a new date; the UI shows the latest figure per category and a history view.
- `HasJurisdiction`, `LogsBuffaloActivity`, classification fixed at `restricted`.

## Step 2C.4 — Community security notes

`community_security_notes` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id`, `community_id` | community required |
| `category` | enum: `land_dispute`, `chieftaincy_dispute`, `communal_tension`, `farmer_herder`, `youth_restiveness`, `cult_activity`, `political_tension`, `religious_tension`, `boundary_dispute`, `other` |
| `title` | required, max 200 |
| `description` | text, required |
| `status` | enum: `active`, `monitoring`, `resolved` |
| `classification` | enum, default `restricted` |
| `observed_at` | date |
| `resolved_at` | nullable date, required when status is `resolved` |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, and `LogsReads` (from 1D) when classification is `confidential` or `secret`.
- Status changes are logged as named events.
- These notes are an early form of intelligence; Phase 6 flashpoints will be able to reference them. Leave no placeholder columns for that.

## Step 2C.5 — Permissions

| Permission | DG | DDG | State Dir. | Deputy | LGA Chief |
|---|---|---|---|---|---|
| `religious_institutions.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |
| `vulnerable_stats.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |
| `security_notes.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |

Super admin: none. Deleting any of these requires State Director or above, with a reason. All subject to jurisdiction and, for security notes, classification.

## Step 2C.6 — UI

Add tabs to the LGA page (`/lgas/{ulid}`) and sections to the community page:

- **Religious institutions:** list, filters (religion, type, community), add/edit with map picker; summary cards showing counts by religion and by type for the LGA.
- **Vulnerable population:** latest figures per category as a table with totals, "Add figures" form, history view per category. On the community page, the same for that community.
- **Security notes:** list with classification badges, status and category filters; create/edit with `ClassificationSelect`; status change actions.
- Map: religious institutions as a toggleable layer alongside communities (different marker style).

Dashboards:
- LGA chief: count of active security notes; religious institutions count.
- State roles: active security notes by LGA; vulnerable population totals by category for the state (latest figures).
- DG/DDG: active security notes by state and category.

All dashboard figures go through the jurisdiction and classification scopes. Run `make wayfinder` after route changes.

## Step 2C.7 — Tests

- `ClassificationGate` for every role × level, including the creator exception and super admin.
- `HasClassification` composes with jurisdiction: a Deputy State Director sees `confidential` in their state, not `secret` (unless creator), and nothing from another state at any level.
- A user cannot assign a level above their maximum, via the HTTP layer as well.
- Classification change logged with old and new levels.
- Reads of `confidential`/`secret` notes create `record_access_logs` rows; `restricted` reads do not.
- Scope matrix for all three new models.
- Vulnerable stats: no notes column exists; uniqueness including LGA-wide rows; future dates rejected; latest-per-category query correct.
- Community must belong to the LGA; denomination must belong to the religion.
- Deletion rules and reasons.
- Dashboard counts respect jurisdiction and classification.

## Phase 2 completion checklist

- [ ] An LGA chief builds a full picture of their demo LGA: profile, communities, religious institutions, vulnerable figures, and a `restricted` security note.
- [ ] A Deputy State Director can see a `confidential` note in their state but not a `secret` one created by the State Director.
- [ ] State and national dashboards show correct aggregated figures.
- [ ] No Lagos data is visible to a Kano user anywhere, including dashboards and search.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-2c.md` per CLAUDE.md section 9, and `docs/handover/phase-2-summary.md` summarising all of Phase 2: tables, permissions, the classification rules as implemented, and open issues for Phase 3. Print both.
