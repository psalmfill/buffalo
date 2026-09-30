# Handover — Phase 5A: Foundations and the incident record

Date: 2026-10-01

## Summary

Phase 5A lays the foundations for incident reporting:

- **5A.0 — Time zone fix:** the database session now uses Africa/Lagos, and existing `timestamptz` values were corrected.
- **5A.1 — Searchable pickers:** one `EntityPicker` component with scoped endpoints. The four capped Phase 4 lists now use it.
- **5A.2–5A.3 — The incident record:**
  - the `incidents` table;
  - five related tables (casualties, persons, criminal groups, agencies, property), each with its own jurisdiction;
  - access requests can cite an incident.
- **5A.4 — Permissions:** `incidents.view`, `incidents.create` and `incidents.update`.
- **5A.5 — UI:**
  - a new **Incidents** navigation section;
  - the six-section incident form (drafts only);
  - a basic read-only detail page with a history tab.
- **5A.6 — Tests:** every test listed in the prompt, plus a per-role policy test.

Final checks:

- `make test`: 1079 Pest tests (6583 assertions) and the Vitest suites pass.
- `make lint` passes.
- `make ci` passes (1079 Pest tests in a clean copy).
- The browser acceptance checklist passes (below).

Commits:

- `ce7709c` fix(db): set connection time zone and correct timestamptz data (5A.0);
- `53cc133` feat(ui): add searchable entity pickers (5A.1);
- the incident feature commit, and the docs commit (this file, the prompt, the README).

## Acceptance checklist

- [x] **POI "last sighted" times display correctly.** On dev, Chidi Nwosu's sighting was entered as 30 Sept 2026 14:30. It displays as 14:30 and is stored as 13:30 UTC. Before 5A.0 it would have shown 15:30.
- [x] **Draft kidnapping in Demo LGA LA-1-1**, created as `chief.lagos` through the form:
  - the type fields (location Highway, 2 victims, ransom ₦5,000,000), with severity pre-filled from the type;
  - casualties: 1 man and 1 woman abducted (civilians), and 1 injured NPF officer;
  - suspect: Aminu Dogo Kabiru (a Kano person, picked under an active grant);
  - criminal group: Apapa Road Bandits;
  - response: NPF, Apapa Division, as first responder;
  - property: one vehicle (a bus, ₦18,000,000).

  The draft's page shows every section. Dev preparation:
  - I created the bandit group, since the demo LGA had none;
  - the chief's earlier grant had been revoked, so I approved the chief's pending (escalated) request as `sd.kano`, for its requested 7 days.
- [x] **`dsd.lagos` sees the incident; the suspect shows as "Person on record (not visible to you)".** Every other section is visible. Dev note: `dsd.lagos` did not have the shared acceptance password, so I set it on dev.
- [x] **`make test` and `make lint` pass.**

## What was built

### 5A.0 — Time zone (commit `ce7709c`)

- **Connection time zone:** `config/database.php` sets the pgsql `timezone` to `env('DB_TIMEZONE', env('APP_TIMEZONE', 'Africa/Lagos'))`.
- **Data correction:** migration `2026_10_06_000001_correct_timestamptz_values` subtracts one hour from the only pre-existing `timestamptz` columns. All of them are written by the app:
  - `lga_profiles.last_reviewed_at`;
  - `persons_of_interest.last_reviewed_at` and `last_sighted_at`.
- **Not affected:** plain `timestamp` columns with a `CURRENT_TIMESTAMP` default (`failed_jobs`, `login_histories`, `record_access_logs`, `record_match_events`). Their models set `created_at` themselves.
- **Tests:** `tests/Feature/Database/TimeZoneTest.php`.

### 5A.1 — Pickers (commit `53cc133`)

- **Endpoints:** `POST /pickers/{persons|communities|formations|sites|groups|organizations|users}`.
  - Each applies its model's scopes (jurisdiction and classification) and returns at most 20 `{value, label, secondary, meta?}` rows.
  - Filters: `lga`, `agency`, `state`, `organization`, `sector`, `except`, and `purpose` for persons.
- **Persons purpose:** `link` is the default, `incident` is the other option (see the conflict below).
- **Component:** `resources/js/components/pickers/entity-picker.tsx` (`EntityPicker`), single or multiple, keyboard-navigable.
- **Capped lists replaced:**
  - the formation parent;
  - coverage deployment formations;
  - site security providers;
  - site formation links.
- **Tests:** `tests/Feature/Pickers/PickersTest.php`.

### 5A.2 — `incidents`

**Migration `2026_10_06_000002_create_incident_tables`**

- The prompt's columns: `reference_no` is partially unique when present; `location` is `geography(Point)` checked against the LGA by the existing trigger; `search_vector` is generated (English, title and description) with a GIN index.
- CHECKs on the enums and on `details` being a JSON object.
- The composite FKs `(lga_id, state_id)` and `(community_id, lga_id)`, plus `UNIQUE (id, lga_id, state_id)` as the target for the children.

**`Incident` model**

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` and soft deletes.
- `details` uses the new `App\Casts\JsonObject` cast. The plain `array` cast stores `[]` for an empty array, which breaks the object CHECK.
- Views are logged for confidential and secret incidents (`logsViews()`).

**`IncidentPolicy`**

- `viewAny` / `view`: the permission, own jurisdiction and classification.
- `create`, and `createIn(Lga)`: the LGA must be within reach.
- `update`: the incident is a `draft`, the user holds `incidents.update`, and is its creator or the SD/DSD of the state.

**`IncidentRequest`** (authorises before validating)

- `details` go through `DetailsValidator`. The existing values count only while the type is unchanged, so a draft saved under an older schema version stays editable.
- `occurred_at` cannot be in the future; `responded_at` cannot be before `occurred_at`.
- The classification must be one the user can assign.
- Each related row has its own checks (below).

**`SaveIncident`** (`create` / `update`)

- Related rows are synchronised as full lists: rows are updated by ULID, added, or soft-deleted.
- `details_schema_version` is set to the type's current version on every save.
- A change of location is logged as an event.

### 5A.3 — Related tables

- **Shared shape:** each table has a ULID, `incident_id`, its own `state_id`/`lga_id`, authors, `timestampsTz` and soft deletes.
- **Sync:** a composite FK `(incident_id, lga_id, state_id)` → `incidents (id, lga_id, state_id)` **ON UPDATE CASCADE** keeps the children in step with the incident's LGA. A child can never disagree with its incident; the database rejects it.
- **Models:** each extends `IncidentChild`, which adds `HasJurisdiction` on the row's own place, plus an `incident` global scope (`whereHas('incident')`). A row is visible only when **both** its own place and its incident are visible.
- **Tables:**
  - `incident_casualties`: the prompt's columns (the column is literally named `group`). CHECKs: an agency if and only if the group is security personnel; counts ≥ 0 with a sum > 0; unique `NULLS NOT DISTINCT` among live rows.
  - `incident_persons`: CHECK that there is a person or a description. Linking is authorised by `PersonPolicy::linkToIncident`. A linked person's name is shown only to viewers who can `view` that person.
  - `incident_criminal_groups`: unique per incident. The group must be visible to the editor.
  - `incident_agencies`: a trigger ensures the formation belongs to the agency. The formation must be visible. Changing the agency drops the formation.
  - `incident_properties`: quantity > 0 and value ≥ 0. The site must be visible.
- **Hidden linked records are kept:** an editor who cannot see a linked person, group, formation or site sees "not visible to you" with a **Replace** option. Leaving the key out keeps the link.
- **Reference usage:** `App\Support\Incidents\ReferenceUsageChecks` registers:
  - `incidents.incident_type_id`;
  - `incident_casualties.security_agency_id` and `incident_agencies.security_agency_id`;
  - `incident_properties.property_type_id`.
- **Access requests:**
  - `record_access_requests.incident_id` (nullable FK); the `incident` field must name an incident the requester can `view`.
  - The decision page shows "Related incident: [reference] — [type], [LGA] LGA" through `AccessRequestParties::incidentLine()`. This is a sanctioned names-only lookup; the approver usually cannot see the incident.
  - The request dialog cites the incident automatically when a hit comes up from a saved draft.
- **Person entry from the form** (`IncidentPersonController`):
  - `POST /incidents/person-check` checks an unidentified person's known details, or a new person's. The details are used for matching only and never stored with the incident. It returns:
    - local matches the user may link ("Use this person");
    - cross-jurisdiction hits (context `incident_entry`, recorded as match events), with **Request access**.
  - `POST /incidents/new-person` creates the person using `PersonRequest`'s rules. With hits, it needs `hit_justification` (**Create anyway**, audited as `created_despite_hit` with context `incident_entry`). It returns JSON, so the form stays open.
- **POI suggestion:** a suspect on record shows a "Consider designating them" link to the POI action. Nothing changes automatically.

### 5A.4 — Permissions

- `incidents.view`, `incidents.create` and `incidents.update` go to all five intelligence roles; super admin has none.
- Updated together: `RolesAndPermissionsSeeder::matrix()`, the drift test, `types/auth.ts` and the navigation test.
- Permissions were re-seeded on dev.

### 5A.5 — UI

- **Navigation:** a new **Incidents** section with "Report incident" and "Incidents" (your drafts).
- **`pages/incidents/form.tsx`**, the six sections of the prompt:
  - the types are grouped by category;
  - `DynamicFields` for the type's fields;
  - a warning before a type change clears the entered fields;
  - the LGA comes from `JurisdictionOptions`;
  - the community picker is filtered to the LGA;
  - the map point picker;
  - editors under `components/incidents/`.
- **`pages/incidents/show.tsx`:** a read-only view of every section (`DynamicFieldsView` for the details), a map, and a **History** tab. The history includes activity on related rows.
- **`pages/incidents/index.tsx`:** the user's drafts.

## Deviations and conflicts

1. **`link` vs grants (conflict with Phase 3C).** The prompt says the persons picker "uses `link` so grant holders can pick… per Phase 3". In 3C, `PersonPolicy::link` is own-jurisdiction only, and a test covers that. I kept `link` unchanged and added `PersonPolicy::linkToIncident`: `incidents.create` plus `view` (own jurisdiction or an active grant, and classification). The persons picker takes `purpose=incident` for it; its default stays `link`.
   - **Why this is safe:** an incident person row carries the incident's jurisdiction, not the person's, so a grant holder gains nothing new about the person.
   - **Decision for the owner:** confirm this reading of access rule 9 ("never `view`"). Rule 9 was written for records scoped through the person; here the link is authorised by a dedicated ability.
2. **Children follow the incident through the database** (ON UPDATE CASCADE on the composite FK), rather than through application code.
3. **A draft's LGA can be changed on edit**, but only to an LGA within the editor's reach (`createIn`).
4. **"New person" is a dialog on the incident form** posting to `/incidents/new-person`, not a redirect to the person page. This keeps the draft open. It uses the person form's rules and hit handling.
5. **The unidentified-person check** accepts an optional name, date of birth, NIN or phone for matching only. None of it is stored.
6. **The classification select offers all levels;** the server rejects any above the user's clearance, as in earlier forms.
7. **Client-side validation is light:** coordinates and required selects. The server is authoritative, and errors show per field and in a summary.

## New packages

None.

## Known issues and notes for 5B–5D

- **Workflow columns:** submission, reference numbers and the workflow are 5C; the detail page is completed in 5D.
- **The full-text vector covers the title and description only,** as the prompt asks. Location and details are not indexed.
- **Drafts list:** it shows only the user's own drafts. A State Director can edit a chief's draft, but finds it only through a link until the 5D list.
- **Hidden rows in history:** the history tab lists the activity of related rows, including rows about records the viewer cannot see. `ActivityPresenter` strips numeric keys (`*_id`), so a hidden person, group, formation or site is never identified. The row's own text (notes, descriptions) is shown, as on the detail page.
- **Old details on display:** `DynamicFieldsView` renders details with the type's *current* schema (which keeps deprecated fields). Values of a field removed from the schema entirely would not show, but in-use types can only deprecate fields (Phase 2A rule).
- **Dev data created for acceptance:**
  - the group "Apapa Road Bandits";
  - the chief's grant on Aminu Dogo Kabiru, expiring on 7 October;
  - Chidi Nwosu's sighting time;
  - the draft "Two travellers abducted on the Apapa expressway";
  - `dsd.lagos` now uses the shared dev password.
- **Carried over:** Phase 4's plain `timestamp` workflow columns remain as they were (see 5A.0).
