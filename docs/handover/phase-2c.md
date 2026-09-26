# Handover — Phase 2C: Community information and classification

Date: 2026-09-26

## Summary

Phase 2C adds:

- **Classification** (`CLAUDE.md` section 4a): an enum, a gate and a query scope that works together with the jurisdiction scope.
- **Three kinds of community information**, each scoped to the user's jurisdiction and fully audited:
  - religious institutions;
  - vulnerable population figures (aggregate counts only);
  - community security notes (classified, and reads of confidential or secret notes are logged).
- **New permissions, LGA and community tabs, and dashboard figures.**

Checks:

- `make test` passes: 670 Pest tests (3796 assertions) plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- Every item of the Phase 2 completion checklist passed in the browser.

Commits:

| Step | Commit |
|---|---|
| 2C.1–2C.7 | `f6e5769 feat(community-information): add classification, religious institutions, vulnerable figures and security notes` |
| Fix found during acceptance | `0602b0d fix(community-information): audit dates as plain dates` |
| — | `docs: add phase 2c handover and phase 2 summary` (this file, the Phase 2 summary, README, prompt) |

**Why one feature commit:** the steps depend on each other. The permissions, the drift tests, the classification scope, the models, the tabs and the dashboards only pass their tests together (the same reason as in 2B).

## Phase 2 completion checklist

| Item | Result |
|---|---|
| An LGA chief builds the full picture of their LGA | ✅ In Chrome as `chief.lagos` on Demo LGA LA-1-1. The profile (100%) and communities already existed from 2B.<br>- **Religious institution:** added "Olodi Central Mosque" (Islam / Sunni, congregation 650, pinned on the map). The denomination list followed the chosen religion. The summary cards counted it, and the map showed the purple marker.<br>- **Vulnerable figures:** added LGA-wide "Internally displaced" figures of 120 / 145 / 80 / 95 (total 440).<br>- **Security note:** added a restricted note, "Land dispute over waterside plots". The classification select offered **only Restricted**.<br>- **Notes list:** the chief sees only restricted notes. |
| The Deputy State Director sees confidential but not secret | ✅ As `dsd.lagos`:<br>- the restricted and confidential notes are listed;<br>- the secret note's URL → 404;<br>- opening the confidential note wrote exactly one `record_access_logs` row, for the deputy. |
| Dashboards show the new figures | ✅<br>- **Deputy's state dashboard:** active notes LA-1-1 = 2, and IDP figures of 265 adults, 175 children, 440 total.<br>- **DG's national dashboard:** Lagos has land dispute 1, cult activity 2, total 3. |
| Kano cannot see Lagos | ✅ As `sd.kano`:<br>- the dashboard shows no Lagos data;<br>- community searches for Olodi, Ajegunle, Apapa and Mosque return 0 results;<br>- every Lagos LGA, community, institution, figure and note URL returns 404. |
| `make test`, `make lint`, `make ci` | ✅ |

## What was built

### 2C.1 — Classification

- **`App\Enums\Classification`** has three levels:
  - Restricted < Confidential < Secret;
  - methods `label()`, `rank()`, `isAtMost()` and `upTo()`.
- **`ClassificationGate`** (`app/Support/Classification/`):
  - `maxLevelFor(user)`:
    - DG, DDG and State Director → secret;
    - Deputy State Director → confidential;
    - LGA chief → restricted;
    - super admin, no role, or an inactive / locked / deleted account → `null` (sees nothing).
  - `canSee(user, record)`: the record's level is at or below the user's maximum, **or the user created the record**. A null maximum always means false, even for the creator.
  - `canAssign(user, level)` and `assignableLevels(user)`.
- **`ClassificationScope`** is added by the `HasClassification` trait. It runs as well as `HasJurisdiction`, not instead of it:
  - **no context** → no rows;
  - **system context** → all rows;
  - **user context** → `classification IN upTo(max) OR created_by = user`.
- **Change logging:** `HasClassification` logs every level change as the named event `classification_changed`, with the old and new levels.
- **Contract:** `Contracts\Classified` (`classificationLevel()`) is the interface policies check.
- **Shared prop:** `auth.user.maxClassification` is shared with the frontend. It is used only to build the classification select; it is not a security control.

### 2C.2 — Tables

Migration `2026_09_30_000001_create_community_information_tables`:

- **Shared by all three tables:**
  - `ulid`, denormalised `state_id` and `lga_id`, `created_by` and `updated_by`, timestamps and soft deletes;
  - composite FKs `(lga_id, state_id)` → `lgas` and `(community_id, lga_id)` → `communities` (a new `UNIQUE (id, lga_id)` on `communities`).
- **`religious_institutions`:**
  - the community is required;
  - religion, plus an optional denomination. A composite FK `(denomination_id, religion_id)` → `denominations(id, religion_id)` ensures the denomination belongs to the religion;
  - CHECK constraints on the type and a non-negative congregation;
  - a point location with a spatial index;
  - a boundary trigger that reuses `buffalo_community_location_in_lga()`.
- **`vulnerable_population_stats`:**
  - `community_id` is nullable; null means an LGA-wide figure;
  - the category and four counts (non-negative CHECK), plus `as_of_date` and `source`;
  - no names, notes or free-text description;
  - a unique index `NULLS NOT DISTINCT` on `(lga_id, community_id, category, as_of_date) WHERE deleted_at IS NULL`, allowing one figure per place, category and date.
- **`community_security_notes`:**
  - the category, title, description, status and `classification` (default restricted), plus `observed_at` and `resolved_at`;
  - CHECK constraints on the category, status and classification;
  - "resolved requires `resolved_at`".

### 2C.3 — Models, enums, Actions

- **Enums:** `ReligiousInstitutionType`, `VulnerableCategory`, `SecurityNoteCategory` and `SecurityNoteStatus`.
- **Models:** all use `HasJurisdiction` and `LogsBuffaloActivity`.
  - `ReligiousInstitution`: `withCoordinates()` and `pointFrom()`.
  - `VulnerablePopulationStat`: `COUNTS`, `total()`, and a `latestPerSeries` scope (`DISTINCT ON`).
  - `CommunitySecurityNote`: `Classified`, `HasClassification` and `LogsReads`. `requiresReadLogging()` is true for confidential and secret notes.
  - Date columns are cast `date:Y-m-d`.
- **Actions** (`app/Actions/CommunityInformation/`):
  - `SaveReligiousInstitution`: location changes are logged as `location_changed`.
  - `SaveVulnerableFigures`.
  - `SaveSecurityNote`: `create`, `update` and `changeStatus`. A status change logs `status_changed` with the old and new status; resolving sets `resolved_at`.
  - `DeleteWithReason`: a soft delete that logs `{type}_deleted` with the reason.
- **Form Requests:**
  - `LgaRecordRequest` is the shared base: the LGA, the community, and "the community must be in this LGA".
  - `ReligiousInstitutionRequest`: the denomination must belong to the religion; values must be active (`ActiveOption`).
  - `VulnerableFiguresRequest`: the date must not be in the future; one figure per date.
  - `SecurityNoteRequest`:
    - `canAssign`, which rejects with "You cannot classify a record above your own clearance.";
    - status and `resolved_at` only on create.
  - `SecurityNoteStatusRequest`.

### 2C.4 — Permissions and policies

- **Permissions:**
  - `religious_institutions.*`, `vulnerable_stats.*` and `security_notes.*`, each with `.view`, `.manage` and `.delete`;
  - `view` and `manage` go to every intelligence role;
  - `delete` goes to the DG, DDG and State Director (`DELETE_ROLES`, also now used for `communities.delete`);
  - the super admin gets none of them.
- **Policies:**
  - `ReligiousInstitutionPolicy`, `VulnerablePopulationStatPolicy` and `CommunitySecurityNotePolicy`. The notes policy checks the permission, then jurisdiction, then `ClassificationGate::canSee`.
  - `LgaPolicy` gained `addReligiousInstitution`, `addVulnerableFigures` and `addSecurityNote`.
- **Frontend and drift tests:** `types/auth.ts` `PERMISSIONS`, `RolesAndPermissionsTest` and the navigation test's role table are kept in step with the seeder.

### 2C.5 — UI

- **Tabs:**
  - LGA page: Religious institutions, Vulnerable population and Security notes (`LgaController::TABS`, loaded only when selected).
  - Community page: Details, Religious institutions, Vulnerable population, Security notes, History.
- **Religious institutions tab:**
  - counts by religion and by type, computed and never stored;
  - filters for religion, type and community;
  - a list and a map with purple `religious` markers;
  - the Communities tab map has a checkbox to show the institutions layer.
- **Vulnerable population tab:**
  - the latest figure per category, with totals;
  - on LGA pages, each community's latest figures, labelled as breakdowns;
  - a category's history (`?history=`);
  - a "counts only" warning on the panel and the form.
- **Security notes tab:**
  - filters for status and category;
  - classification and status badges;
  - a note page with a status change form and history.
- **Forms:**
  - the classification select offers only assignable levels;
  - deletes require a reason (`ConfirmActionDialog`).

### 2C.6 — Dashboards

- **LGA dashboard** (`BuildLgaDashboard`): `information` shows active security notes and religious institution counts.
- **State dashboard** (`BuildStateDashboard`):
  - `securityNotesByLga`: active notes per LGA;
  - `vulnerableTotals`: the sum of each LGA's **latest LGA-wide** figures by category.
- **National dashboard** (`BuildNationalDashboard`): `securityNotesByState`, active notes per state by category.
- **Classification applies to the counts:** all counts go through both scopes, so a user never sees counts that include notes above their level.

### 2C.7 — Tests

- **`tests/Feature/Classification/ClassificationTest.php`:**
  - every role × level;
  - the creator exception, and its limit (none for users without an intelligence role);
  - the scope working together with jurisdiction;
  - change logging;
  - the shared prop;
  - an architecture check that `ClassificationScope` is never removed.
- **`tests/Feature/CommunityInformation/`:**
  - **`ScopeMatrixTest`:** every model × every role / place.
  - **`SecurityNotesTest`:**
    - assigning levels over HTTP, including raising a level on edit;
    - the deputy is denied secret notes;
    - access logs;
    - status workflow and the DB constraint;
    - deletion;
    - community and date rules;
    - dates audited as plain dates.
  - **`ReligiousInstitutionsTest`.**
  - **`VulnerableFiguresTest`:**
    - an exact column list, proving there is no name or notes column;
    - uniqueness, including LGA-wide rows;
    - future dates;
    - latest figures and history;
    - totals;
    - deletion.
  - **`DashboardFiguresTest`.**

## Deviations and conflicts

- **Delete permissions added** (`religious_institutions.delete`, `vulnerable_stats.delete`, `security_notes.delete`). Only the DG, DDG and State Director hold them, as with `communities.delete` in 2B. **The Deputy State Director is excluded again; please confirm.**
- **`DeleteCommunityRequest` renamed `DeleteRecordRequest`**, because all four delete endpoints use it.
- **Boundary trigger reused.** Religious institutions reuse the 2B trigger function `buffalo_community_location_in_lga()`. Its error message says "community location".
- **`maxClassification` is a shared Inertia prop** instead of a per-form `assignable` list. The server still validates with `canAssign`.
- **Security note status:**
  - it is set on create; afterwards it changes only through the separate status endpoint, so each change is a named, audited event;
  - the edit form does not change it.
- **Vulnerable figures can be edited** (as corrections, audited). New figures should be new rows with a new date, which keeps history.
- **Figure semantics:**
  - LGA-wide rows (`community_id` null) are the authoritative LGA figure;
  - community rows are breakdowns and are **not** summed into LGA or state totals;
  - state totals add up only the latest LGA-wide figures;
  - please confirm this reading.
- **Institutions layer:** the toggle is on the LGA Communities tab map (the prompt's "map layer"). The Religious institutions tab also has its own map.
- **Creator exception:** it does not apply to users with no intelligence clearance (super admin, blocked accounts). A creator who lost their role cannot see their own notes.
- **One feature commit**, for the reason given in the summary.

## New packages

None.

## Known issues and notes

- **Future dates are blocked only by validation.** "`as_of_date` / `observed_at` not in the future" has no database CHECK, because `current_date` is not immutable.
- **Tinker-created notes show "By System".** The dev confidential and secret notes were created through `SaveSecurityNote` in Tinker, so their history shows "By System".
- **Older audit dates:** audit entries written before `0602b0d` show dates as timestamps. Newer entries show `Y-m-d`.
- **No marker clustering and no LGA boundaries yet** (carried over from 2B). The boundary triggers stay dormant.
- **Dev database changes:**
  - accounts `sd.lagos@buffalo.local` and `dsd.lagos@buffalo.local` (password `Lagos#Officer2026`, 2FA set via Tinker);
  - in LA-1-1: Olodi Central Mosque, the IDP figures, the restricted note, plus one confidential and one secret cult-activity note created via Tinker;
  - `make fresh` resets all of this.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next phase (3)

- **Classified models** (persons of interest, political information) should:
  - implement `Classified` and use `HasClassification` together with `HasJurisdiction`;
  - check `ClassificationGate::canSee` in the policy after the jurisdiction check;
  - validate `canAssign` in the Form Request;
  - use `LogsReads::recordAccess()` in `show` for reads that must be logged (all person reads, per `CLAUDE.md` section 6).
- **Cross-jurisdiction grants** must allow records through *both* scopes. Decide whether a grant also overrides classification. The rules suggest it should not: grants are about jurisdiction, and clearance still applies.
- **Deletes with a reason:** use `DeleteWithReason` and `DeleteRecordRequest`.
- **Religions and denominations are now in use:** `ReferenceUsage` still needs a real binding (see the Phase 2 summary).
