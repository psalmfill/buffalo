# BUFFALO — Phase 11C: Arrests

Read `CLAUDE.md` before starting (the Phase 11 arrests rule; access rule 9 on models linked to persons; the merging rules). Read `docs/handover/phase-11b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

The client wants to record arrests: **which agency** made the arrest, **who** was arrested, for **what offence**, and **when**. An arrest may stand alone or be linked to an incident. On an incident page, an **Arrest made** button opens the form already filled with what the incident knows.

Incident casualties already have an `arrested` count. That count stays (it records what was reported); arrest records add the detail. The two are compared but never changed automatically.

---

## Step 11C.1 — Offence types (reference data)

`offence_types` lookup following the Phase 2A pattern exactly, admin-editable, with a `threat_type_id` (nullable) mapping each offence to a threat category for analytics.

Seed (editable by the client): murder; attempted murder; armed robbery; kidnapping; cultism; banditry; terrorism; unlawful possession of firearms; illegal arms dealing; drug trafficking; illegal oil bunkering; pipeline vandalism; sea piracy; sexual offence; assault; arson; fraud; theft; criminal conspiracy; public disturbance; other.

## Step 11C.2 — Tables

**`arrests`**

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `reference_no` | `ARR/<STATE>/<YEAR>/<5-digit seq>`, assigned on creation, gap-free per state and year (counter table, as for threats) |
| `arrested_at` | `timestampTz`, required, not in the future |
| `arrested_at_precision` | `exact`, `approximate`, `date_only` |
| `security_agency_id` | required (register with `ReferenceUsage`) |
| `agency_formation_id` | nullable; same agency; visible to the user |
| `state_id`, `lga_id` | required; composite FK |
| `community_id` | nullable; composite FK |
| `location` | nullable point; `location_description` nullable |
| `incident_id` | nullable; the incident must be visible to the user and not invalid |
| `status` | `in_custody`, `charged`, `arraigned`, `released_on_bail`, `released_without_charge`, `transferred`, `convicted`, `acquitted` |
| `notes` | rich text (11B) |
| `classification` | default `restricted` |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

**`arrest_persons`** (one arrest can involve several people)

| Column | Notes |
|---|---|
| `arrest_id` | |
| `state_id`, `lga_id` | copied from the arrest, kept in sync (access rule 9); composite FK with cascade |
| `person_id` | nullable; linking requires the same rule as incidents (`PersonPolicy::linkToIncident`, or a renamed shared ability: own jurisdiction or an active grant) |
| `unidentified_description` | required when `person_id` is null |
| `status` | per-person status, same values as the arrest's; the arrest's own status shows the most common, with per-person detail |
| `notes` | plain text, nullable |

**`arrest_person_offences`**: `arrest_person_id`, `offence_type_id` (register with `ReferenceUsage`), `details` (plain text, nullable). At least one offence per arrested person. Same place columns as above.

**`arrest_status_histories`**: append-only (trigger), per arrest and per person: from, to, comment, changed by, changed at.

- `Arrest` uses `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` above `restricted`. Child rows are scoped on their own place.
- Register `arrest_persons.person_id` in `PersonReferenceRegistry` with merge and undo rules (repoint; deduplicate identical person-offence rows within one arrest; recorded in the manifest). The registry test must pass.
- If an incident gains arrests and is later consolidated as a duplicate (10B), arrests move to the original incident: add the rule to `App\Support\Incidents\Consolidation`.

## Step 11C.3 — Behaviour

1. **Arrest made** button on the incident page (users who can view the incident and hold `arrests.create`; incident not invalid). It opens the arrest form pre-filled with: the incident link, place, community and location; the agency choices from the incident's responding agencies (first one selected); the incident's suspects as suggested arrested persons (one click to add each); the incident date as a hint, not a default.
2. **Standalone arrests:** "New arrest" from the Arrests list, with an optional incident picker.
3. **Arrested persons:** pick an existing person, add an unidentified person with a description, or create a new person. Unidentified and new persons run the cross-jurisdiction match check (`PersonMatchService`, new context `arrest_entry`), with the same hits, "Request access" and "Create anyway" (justification) flow as the incident form.
4. **Suggestions, never automatic changes:**
   - When an arrest links to an incident whose `arrested` casualty count differs from the number of persons in its arrests, show the difference on both pages, with a link to amend the incident (the normal amendment flow).
   - When an arrested person is a person of interest the user can manage, suggest updating their POI status to `detained` (link to the POI action).
   - When an arrested person is not a POI, offer "Designate as person of interest".
5. **Status changes** (arrest-wide or per person) require a comment and write status histories and named activity events.
6. **Notifications** (payload: reference, LGA, link; no names or offences): a new arrest of a person who is a POI notifies the SD and DSD of the POI's owning state, if they can see the arrest; an arrest linked to an escalated incident notifies the DG and covering DDGs.

## Step 11C.4 — Pages

- Navigation: **"Arrests"** under Incidents.
- **`/arrests`**: list with filters (date range, agency, formation, offence, status, state, LGA, linked to incident yes or no, person of interest yes or no) and the state lens (11A). Columns: reference, date, agency, persons count, offences, status, LGA, linked incident.
- **`/arrests/{ulid}`**: details, arrested persons with their offences and statuses (names shown only as the viewer can see each person, as on incidents), linked incident, casualty comparison, status timeline, history.
- **Tabs elsewhere:** "Arrests" on person pages (hidden under a grant, like incidents), incident pages, LGA pages and formation pages.
- **Global search:** add an arrests provider (reference exact; notes full text on the plain text copy).
- **Dashboards:** LGA chief: arrests in the last 30 days. State roles: arrests by offence and by agency (30 days). National: the state grid (11A) gains an "arrests (30 days)" figure.
- **Analytics:** a small "Arrests" section in the Incidents tab: arrests over time, by offence (and its threat category), by agency, and the share of incidents of each type with at least one linked arrest.

Run `make wayfinder` after route changes.

## Step 11C.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `arrests.view`, `arrests.create`, `arrests.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `arrests.delete` | ✓ | ✓ | ✓ | | |

Super admin: none. Update the matrix, drift tests, `auth.ts` and the route inventory.

## Step 11C.6 — Tests

- Reference numbers gap-free per state and year under concurrency.
- Scope and classification matrix for arrests and all child tables; child rows not visible through the arrest alone.
- Person links: own jurisdiction or active grant; names hidden from viewers who cannot see the person; `arrest_entry` match events recorded.
- Pre-fill from an incident; suggestions shown and never applied automatically; casualty comparison.
- Status changes require comments; histories append-only.
- Person merge and undo move arrest rows correctly (extend the 10A manifest test fixture); registry test passes.
- Consolidation moves arrests to the original incident.
- Notifications reach only users who can see the arrest; payloads contain no names or offences.
- Search provider, dashboards and analytics respect scopes and the statistics rule (arrests linked to invalid incidents still count as arrests; state that in the handover).

## Acceptance checklist

- [ ] On a kidnapping incident, **Arrest made** opens a pre-filled form; the State Director records two suspects arrested by the police division for kidnapping and unlawful possession of firearms.
- [ ] The incident page shows the arrest and flags that the incident's arrested count (0) differs from 2, with a link to amend it.
- [ ] One suspect is a person of interest; the form suggests changing their status to detained.
- [ ] A standalone arrest with an unidentified person is recorded and found by its `ARR/` reference in global search.
- [ ] Merging one arrested person into a duplicate keeps the arrest linked; undo restores it.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-11c.md` per CLAUDE.md section 9 and print it.
