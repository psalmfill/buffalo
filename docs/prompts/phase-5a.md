# BUFFALO — Phase 5A: Foundations and the Incident Record

Read `CLAUDE.md` before starting (updated for Phase 5: see section 5 "Date-times" and the decisions log). Read `docs/handover/phase-4-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phases 1–4 are complete. Phase 5 builds incident reporting and tracking, the core operational module. It is split into four steps:

- **5A (this step):** time zone fix, searchable pickers, and the incident record with all its related data (casualties, persons, groups, agencies, property).
- **5B:** evidence, documents and images.
- **5C:** workflow, follow-ups, notifications, real-time updates, duplicate detection.
- **5D:** lists, search, map, detail page completion, dashboards and cross-module tabs.

---

## Step 5A.0 — Time zone fix

Phase 4 found that `timestamptz` columns written from Africa/Lagos wall-clock values through a UTC database session are one hour late.

1. Set `'timezone' => env('DB_TIMEZONE', config('app.timezone'))` on the `pgsql` connection (and the test connection). Verify with `SHOW timezone` in a test.
2. Migration correcting existing `timestamptz` data: find **every** `timestamptz` column in the schema (query `information_schema.columns`; list them in the handover). For each column whose values were written by the application through the UTC session, subtract one hour (`col - interval '1 hour'`). Nigeria has no daylight saving, so the shift is constant. Exclude columns filled by database defaults such as `now()`, which were already correct; list which were excluded and why.
3. Leave Phase 4's plain `timestamp` columns as they are (they hold correct Lagos wall-clock values). New columns from now on use `timestampTz` (CLAUDE.md).
4. Tests: a Carbon value in Africa/Lagos round-trips unchanged through a `timestamptz` column and a `timestamp` column; the three columns named in the Phase 4 summary display correctly.

**Commit:** `fix(db): set connection time zone and correct timestamptz data`

## Step 5A.1 — Searchable pickers

Replace capped plain lists with a reusable async picker, needed by the incident form.

1. `EntityPicker` React component: debounced search input, results list, keyboard navigation, single or multi select, selected chips, clear. Values are ULIDs.
2. A picker endpoint per entity under `/pickers/...`, each: authorised by the entity's `view` (or `link` for persons) policy; filtered through jurisdiction and classification scopes; index-backed trigram search where a `search_name` exists; returns at most 20 results with ULID, label and a short secondary line (e.g. LGA and state); accepts optional filters (e.g. `security_agency_id`, `lga`).
3. Endpoints: persons (uses `link`, so grant holders can pick persons they hold grants for, per Phase 3), communities, agency formations, organization sites, criminal groups, organizations, users (officers).
4. Replace the four capped lists from the Phase 4 open issues (formation parent, coverage deployment formation, security provider, site formation) with the picker.
5. Tests: each endpoint respects jurisdiction, classification and permission; out-of-reach records never appear; results capped at 20.

**Commit:** `feat(ui): add searchable entity pickers`

## Step 5A.2 — Incidents table

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `reference_no` | nullable until submission (5C); unique when present |
| `incident_type_id` | FK (register with `ReferenceUsage`) |
| `details` | jsonb, validated by `DetailsValidator` against the type's schema |
| `details_schema_version` | integer; the type's `schema_version` at the time `details` was last saved |
| `title` | required, max 200 |
| `description` | text, required |
| `severity` | enum `low`, `medium`, `high`, `critical`; defaults to the type's `default_severity`, editable |
| `occurred_at` | `timestampTz`, required, not in the future |
| `occurred_at_precision` | enum `exact`, `approximate`, `date_only` |
| `state_id`, `lga_id` | required; composite FK pattern |
| `community_id` | nullable; composite FK |
| `location` | nullable point; in-LGA check when boundaries exist |
| `location_description` | nullable text (landmark, road, junction) |
| `action_taken` | nullable text: the immediate action at time of reporting (later actions go in follow-ups, 5C) |
| `status` | enum `draft`, `submitted`, `returned`, `verified`, `under_investigation`, `resolved`, `closed`, `invalid`; default `draft` (transitions built in 5C) |
| `classification` | default `restricted` |
| `reporting_officer_id` | FK users; the creator by default |
| `search_vector` | generated `tsvector` (English) over title and description; GIN index |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

Workflow columns (`submitted_at`, `verified_by`, escalation fields, etc.) are added in 5C. Do not add them now.

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` (for `confidential` and `secret`).
- If the incident type's schema changes later, old `details` stay valid against the version they were saved with: validate on save only, display with `DynamicFieldsView` (which shows deprecated fields).

## Step 5A.3 — Related tables

Every child table carries its own `state_id`/`lga_id`, copied from the incident and kept in sync if the incident's LGA changes (access rule 9), with the composite FK pattern. All use `LogsBuffaloActivity`, and changes appear in the incident's history.

**`incident_casualties`**

| Column | Notes |
|---|---|
| `outcome` | enum `killed`, `injured`, `abducted`, `missing`, `rescued`, `arrested` |
| `group` | enum `civilian`, `security_personnel`, `suspect` |
| `security_agency_id` | required when group is `security_personnel`, otherwise null |
| `adult_male`, `adult_female`, `child_male`, `child_female`, `unknown` | integers ≥ 0; at least one > 0 |

Unique (`incident_id`, `outcome`, `group`, `security_agency_id`) with `NULLS NOT DISTINCT`. This matches the vulnerable-population count pattern from 2C.

**`incident_persons`**

| Column | Notes |
|---|---|
| `person_id` | nullable FK; linking requires `link` on the person |
| `unidentified_description` | text; required when `person_id` is null |
| `involvement` | enum `victim`, `suspect`, `witness`, `informant`, `complainant`, `other` |
| `notes` | nullable text |

- Display rule: the person's name is shown only to viewers who can see the person (per Phase 3 rules as implemented, including grants). Other viewers of the incident see "Person on record (not visible to you)" and the involvement, nothing more.
- Adding an unidentified person, or creating a new person from the incident form, runs `PersonMatchService` with context `incident_entry` (match event context added now). Hits appear exactly as in the person form, with "Request access" and "Create anyway" (justification, audited).
- When a person is linked as `suspect`, the UI suggests designating them a person of interest (link to the POI action; no automatic change).

**`incident_criminal_groups`**: `criminal_group_id` (must be visible to the user adding it), `role` enum `suspected`, `claimed`, `confirmed`, `notes`.

**`incident_agencies`**: `security_agency_id` (register with `ReferenceUsage`), `agency_formation_id` (nullable; same agency; visible), `role` enum `first_responder`, `lead`, `support`, `notified`, `responded_at` (`timestampTz`, nullable, not before `occurred_at`), `notes`.

**`incident_properties`**: `property_type_id` (register with `ReferenceUsage`), `description`, `quantity` (nullable integer), `estimated_value_naira` (nullable decimal ≥ 0), `organization_site_id` (nullable; visible).

**Access requests link:** add nullable `incident_id` to `record_access_requests` so an officer can cite their own incident when requesting access. The incident must be visible to the requester; show it on the decision page as "Related incident: [reference] — [type], [LGA]" (the approver sees only that line, not the incident).

## Step 5A.4 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `incidents.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `incidents.create`, `incidents.update` | ✓ | ✓ | ✓ | ✓ | ✓ |

Workflow permissions come in 5C. For now, `incidents.update` allows editing a `draft` only, by its creator or by SD/DSD of the state. Super admin: none. Update `RolesAndPermissionsSeeder::matrix()`, drift tests and `resources/js/types/auth.ts`.

## Step 5A.5 — UI

Navigation: new section **"Incidents"** with "Report incident" and "Incidents" (a simple list of the user's drafts for now; the full list is 5D).

**Incident form** (`/incidents/create`, `/incidents/{ulid}/edit`), one page in sections:

1. **What happened:** type (grouped by category), then the type's `DynamicFields`; title, description, severity (pre-filled from type).
2. **When and where:** occurred at with precision; LGA (via `JurisdictionOptions`), community picker filtered to the LGA, map point picker, location description.
3. **People:** casualties grid (outcome × group rows with the five counts; agency picker for security personnel); persons involved (person picker, or "Unidentified person" with description, or "New person" opening the person creation flow with the match check); criminal groups.
4. **Response:** agencies (agency, formation picker filtered by agency, role, responded at); action taken.
5. **Property affected:** property rows with optional organization site picker.
6. **Classification.**

Save as draft at any time; client and server validation. Changing the type after details are entered warns that type-specific fields will be cleared.

**Incident detail** (`/incidents/{ulid}`): a basic read-only view of all sections and the history tab. 5D completes the page.

Run `make wayfinder` after route changes.

## Step 5A.6 — Tests

- Time zone fix as listed in 5A.0.
- Pickers as listed in 5A.1.
- Scope and classification matrix for incidents and every child table; child rows are not visible through the incident relationship alone (each has its own scope).
- Child tables' `state_id`/`lga_id` stay in sync when the incident's LGA changes.
- `details` validation per type, including a type whose schema changed after an incident was saved.
- `occurred_at` not in the future; `responded_at` not before `occurred_at`.
- Casualty rules: agency required for security personnel only; at least one count > 0; uniqueness.
- Person links require `link`; a grant holder can link; the name is hidden from incident viewers who cannot see the person; `incident_entry` match events are recorded.
- Groups, formations and sites must be visible to be linked; formation must match the agency.
- Reference usage registered for incident types, agencies and property types.
- Draft editing rules per role.
- Access request with an incident the requester can see; rejected with one they cannot.
- Full-text search vector populated.

## Acceptance checklist

- [ ] Existing POI "last sighted" times now display correctly.
- [ ] Create a draft kidnapping in a demo LGA with type-specific fields, 2 abducted civilians, 1 injured police officer, a linked suspect from an active grant, a bandit group, the police division as first responder, and a vehicle as property.
- [ ] A Deputy State Director of the state sees the incident, but the suspect's name is hidden if they cannot see that person.
- [ ] `make test` and `make lint` pass.

## Out of scope

Evidence (5B); submission, reference numbers, workflow, follow-ups, notifications (5C); lists, search, map, dashboards (5D); flashpoint links (Phase 6).

## Handover

Write `docs/handover/phase-5a.md` per CLAUDE.md section 9 and print it.
