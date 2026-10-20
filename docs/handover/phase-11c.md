# Phase 11C — Arrests

Prompt: `docs/prompts/phase-11c.md`.

## What was built

### 11C.1 Offence types

- `offence_types` in the Phase 2A lookup pattern: shared columns, lowercase code fixed once created, DELETE/TRUNCATE refused by trigger, deactivated rather than deleted. It adds a nullable `threat_type_id` (the threat category an offence counts under in analytics), editable on the reference-data page as an optional "Threat category" select.
- The 21 offences from the prompt are seeded by `ReferenceDataSeeder` (`createMissing`, so administrators' edits survive). Where a threat type fits, they are mapped: armed robbery, kidnapping, cultism, banditry, terrorism and sea piracy to the same-named threat types; illegal oil bunkering and pipeline vandalism to oil theft; the rest have no mapping.
- `ReferenceUsage`: an offence type is in use when on any arrested person's offences; a threat type when an offence type maps to it; a security agency when on any arrest.

### 11C.2 Tables

Migration `2026_11_18_000001_create_arrest_tables`:

- **`arrests`:** reference `ARR/<STATE>/<YEAR>/<5 digits>`, assigned at creation and gap-free per state and year (`arrest_counters`, the same atomic upsert as threat reports; `ArrestReferenceAllocator`).
  - Columns: time and precision (reusing `OccurrencePrecision`), agency and optional formation, place with composite FKs (`(lga_id, state_id)` → lgas, `(community_id, lga_id)` → communities), optional point, optional incident, status, notes (rich text with `notes_text`), classification, authors, timestamps and soft deletes.
  - Triggers: the formation must belong to the agency, and the point must lie inside the LGA.
  - `search_vector` is generated from `notes_text` only (GIN index).
- **`arrest_persons`:** a person on record or a described unidentified person (CHECK), a per-person status, notes. The place is bound to the arrest's by a cascading composite FK (access rule 9). One live row per person per arrest.
- **`arrest_person_offences`:** offence and details. The place is bound to the arrested person's by a cascading composite FK. One live row per offence per person.
- **`arrest_status_histories`:** per arrest (person null) or per arrested person, with from, to, comment (required, CHECK), who and when. Append-only through the same trigger as incident follow-ups (only the place may follow the arrest).
- `record_match_events.context` gains `arrest_entry`.
- **Models:**
  - `Arrest` uses `HasJurisdiction`, `HasClassification`, `HasRichText`, `LogsBuffaloActivity` and `LogsReads` (views above `restricted` are logged).
  - `ArrestPerson`, `ArrestPersonOffence` and `ArrestStatusHistory` are each scoped on their own place and visible only while their parent is.
- **Person merges:** `arrest_persons.person_id` is registered in `PersonReferenceRegistry` (rule `arrest_persons`) and documented in `docs/architecture/person-references.md`.
  - **Merge:** rows are repointed, including arrests in other jurisdictions (grant links). When both records are on the same arrest, the survivor's row is kept, the source's offences it lacks move to it, identical person-offence rows are dropped, notes are concatenated, and the source's row is dropped. Everything is in the manifest.
  - **Undo:** reverses the manifest. `arrest_person_id` is treated as a link column, so a row moved since the merge blocks the undo.
  - The registry test passes, and the 10A fixture now includes arrests: "undo restores every row exactly" covers them.
- **Consolidation:** arrests linked to a duplicate move to the original incident (`repoint` in `App\Support\Incidents\Consolidation`, with an `incident_consolidated` event on the arrest). Arrests the user cannot see are listed as not consolidated and left in place.

### 11C.3 Behaviour

- **Arrest made** on the incident page (users who can see the incident and hold `arrests.create`; never on an invalid incident) opens `/arrests/create?incident=…`. The form is pre-filled with:
  - the incident link, LGA, community, location and location description;
  - the incident's responding agencies listed first, with the first one (and its formation) chosen;
  - its suspects (persons on record, or unidentified descriptions) as one-click suggestions;
  - its date, shown as a hint only (the time of arrest stays empty).
- **Standalone arrests** start from **New arrest** on the list, with an optional incident picker (the existing picker, which excludes invalid incidents).
- **Arrested persons:**
  - **Person on record:** a picker with purpose `arrest`, using the new `PersonPolicy::linkToArrest`: `arrests.create` plus visibility of the person, i.e. own jurisdiction or an active grant (the incident rule).
  - **Unidentified person:** a description, with "Check records".
  - **New person.**
  - Unidentified and new persons run the cross-jurisdiction match with context `arrest_entry`: hits, "Request access", and "Create anyway" with a justification (audited `created_despite_hit`). The server logic moved from `IncidentPersonController` into a shared `PersonEntryController`; incidents and arrests each set their context and link ability. The frontend pieces moved into `components/persons/person-entry.tsx`.
- **Suggestions, never applied:**
  - The incident's reported `arrested` casualty count is compared with the persons on its arrests, as the viewer may see them. A difference shows on the incident page and the arrest page, with a link to the incident's normal edit/amendment flow.
  - A person of interest still at large or under surveillance, whom the user may manage, gets "update their status to detained" (a link to the POI record).
  - A person who is not a POI gets "Designate as person of interest".
  - The form asks `POST /arrests/persons/suggestions` for picked persons, so the suggestion shows while recording.
- **Statuses:** set at creation (for the arrest and, optionally, per person; persons default to the arrest's). Afterwards they change only through `ChangeArrestStatus`, with a comment of at least 5 characters, writing status history rows and a `status_changed` event.
  - **Arrest-wide:** every person takes the new status.
  - **Per person:** the arrest then shows the most common status; on a tie the current one stays (`ArrestStatuses::mostCommon`).
  - The edit form refuses status changes.
- **Notifications** (`ArrestNotification`; payload: reference, LGA and link only): a new arrest of a POI notifies the SD and DSD of each POI's owning state, and an arrest linked to an escalated incident notifies the DG and the DDGs covering the arrest's state. Recipients must be able to see the arrest (`view` policy), and the actor is never notified.

### 11C.4 Pages

- **Navigation:** **Arrests** under Incidents.
- **`/arrests`:** filters for date range, agency, formation, offence, status, zone, state, LGA, linked to an incident, and person of interest. It has the state lens (`lens` middleware, zone filter). Columns: reference, date, agency, persons, offences, status, LGA, incident.
- **`/arrests/{ulid}`:** details; arrested persons with offences, statuses and suggestions (a name only when the viewer can see that person); the linked incident (or "an incident not visible to you"); the casualty comparison; status changes; a status timeline tab; and history.
- **Arrests tabs:** on incident pages (with the comparison notice), person pages (hidden when there are none, and never under a grant), LGA pages and formation pages.
- **Global search:** `ArrestProvider` matches an exact `ARR/…` reference (the query parser now recognises `ARR/`) or full text on `notes_text`. Hits show agency, LGA, date and status, never names or offences, and the state lens ranks first.
- **Dashboards:**
  - LGA chief: arrests in the last 30 days, with the latest five.
  - State roles: arrests by offence (arrested persons) and by agency over 30 days.
  - National: the state grid card gains "Arrests (30 days)", shown but not part of the attention score.
- **Analytics (Incidents tab):** arrests over time, arrested persons by offence with its threat category, arrests by agency, and the share of incidents of each type with at least one linked arrest. The last chart counts only arrests the viewer can see; incidents follow the statistics rule.

### 11C.5 Permissions

`arrests.view`, `arrests.create` and `arrests.manage` go to DG, DDG, SD, DSD and LGA chiefs; `arrests.delete` to DG, DDG and SD; super admins get none. I updated the matrix, `RolesAndPermissionsTest`, `resources/js/types/auth.ts`, the navigation config and test, the jurisdiction matrix test (`arrests.show`, `arrests.edit`, with an arrest in `MatrixRecords`), and the route inventory (362 routes, no warnings).

## Statistics rule (as asked)

**Arrests linked to incidents later marked invalid still count as arrests** on dashboards, in analytics and in search: the arrest happened, even if the incident report was invalid. Incident counts (including the "share of incidents with an arrest" denominator) still exclude drafts and invalid incidents.

## Files

- **New:**
  - Migration: `database/migrations/2026_11_18_000001_create_arrest_tables.php`
  - Models: `app/Models/{Arrest,ArrestPerson,ArrestPersonOffence,ArrestStatusHistory,OffenceType}.php`
  - Factories: `database/factories/{ArrestFactory,OffenceTypeFactory}.php`
  - Enum: `app/Enums/ArrestStatus.php`
  - Policy: `app/Policies/ArrestPolicy.php`
  - Requests: `app/Http/Requests/Arrests/{ArrestRequest,ArrestStatusRequest}.php`
  - Actions: `app/Actions/Arrests/{SaveArrest,ChangeArrestStatus}.php`
  - Controllers: `app/Http/Controllers/Arrests/{ArrestController,ArrestStatusController,ArrestPersonController}.php`, `app/Http/Controllers/Persons/PersonEntryController.php`
  - Presenter: `app/Http/Presenters/ArrestPresenter.php`
  - Support: `app/Support/Arrests/{ArrestReferenceAllocator,ArrestNotifier,ArrestStatuses,ArrestSuggestions,ArrestLinks,ReferenceUsageChecks}.php`, `app/Support/Dashboard/ArrestFigures.php`
  - Search and notifications: `app/Services/Search/Providers/ArrestProvider.php`, `app/Notifications/ArrestNotification.php`
  - Routes: `routes/arrests.php`
  - Frontend: `resources/js/pages/arrests/{index,form,show}.tsx`, `resources/js/components/arrests/{arrests-table,arrest-persons-editor,arrest-comparison}.tsx`, `resources/js/components/persons/person-entry.tsx`, `resources/js/components/dashboard/arrest-figures.tsx`, `resources/js/types/arrests.ts`
  - Tests: `tests/Feature/Arrests/{ArrestsTest,ArrestReferenceConcurrencyTest}.php`
- **Changed:** incident, person, LGA and formation controllers and pages (tabs, "Arrest made", comparison); `IncidentPersonController` (now extends `PersonEntryController`) and `persons-editor.tsx`; merge (`MergePersons`, `UndoPersonMerge`, `MergeManifest`), `PersonReferenceRegistry` and docs; `Consolidation` and its preview; reference-data presenter, request, `ReferenceTable` and seeder; `PickerController` (purpose `arrest`); `PersonPolicy`; `SearchQuery`; the dashboard builders, `StateGrid` and `IncidentAnalytics`; permissions (enum, seeder, `auth.ts`, navigation and its test, `RolesAndPermissionsTest`); `MatrixRecords` and `JurisdictionMatrixTest`; `MergeFixtures` and `MergeRulesTest`; route inventory; README.

## Deviations and decisions

1. **Person-link ability:** a new `PersonPolicy::linkToArrest` (`arrests.create` + `view`) rather than renaming `linkToIncident`. It is the same rule, but it needs the arrests permission, so a role could hold one without the other.
2. **Incident person-entry endpoints refactored:** shared server logic in `PersonEntryController`, shared frontend parts in `person-entry.tsx`. Incident behaviour and messages are unchanged (the audit message is still "Created from an incident …").
3. **Status of the arrest:** stored, kept as the most common of its persons' statuses (ties keep the current one). An arrest-wide change sets every person.
4. **Access requests from the arrest form** cite the linked incident when there is one. Requests cannot cite an arrest itself (`record_access_requests` has an `incident_id` but no arrest link). Adding that would extend Phase 3C, so I left it out.
5. **The formation list** on the form and filter covers formations the user can see, limited to 1,000 (500 on the list filter).
6. **The amendment link** on the comparison points to the incident's edit page, which is the amendment flow after submission (`IncidentPolicy::update`); there is no separate amend ability.
7. **Search icon:** lucide has no handcuffs icon, so arrests use `Gavel` (search results and navigation).
8. **No new packages.**

## Known issues / notes for the next step

- **Phase 11E exporters:** the arrests list has no exporter yet. When 11E registers exporters for every list view, arrests need one (names and offences under the sensitive-list rules where they apply).
- I did not test the arrest form by hand in a browser (signing in needs a TOTP account). HTTP tests cover pre-fill, validation, saving, suggestions, scopes and notifications; the frontend passes `tsc` and ESLint.
- Run `make wayfinder` after pulling; run the migration, `ReferenceDataSeeder` (offence types) and `RolesAndPermissionsSeeder` (arrests permissions) when deploying.

## Gates

- `make test`: 1,803 Pest tests passed (15,109 assertions) and 21 Vitest tests (4 files).
- `make lint`: passed (Pint, Larastan level 7, ESLint, Prettier, `tsc --noEmit`).
- `make ci`: "All CI checks passed." The first two attempts failed on network access while building images (Docker Hub DNS lookups and a timed-out Debian mirror), not on code; the third attempt passed.
- The first full run surfaced three existing tests that needed updating for arrests: `DatabaseRolesTest` (`arrest_status_histories` added to `DatabaseRoles::FOLLOW_PLACE`), `PersonLinksTest` (`OffenceType` usage checks) and `RolesAndPermissionsTest` (LGA chief now holds `arrests.view/create/manage`).
- Dev database: run migrations again (or `buffalo:db:provision-roles`) so the application role gets the append-only grants on `arrest_status_histories`.
