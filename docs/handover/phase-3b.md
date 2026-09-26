# Handover — Phase 3B: Political information, persons of interest, criminal groups

Date: 2026-09-26

## Summary

Phase 3B adds, all within jurisdiction only:

- **Political offices**, with an explicit rule that lets LGA chiefs see offices of their senatorial district.
- **Senatorial district pages** and an LGA **Leadership** tab.
- **Persons of interest (POIs)**, with a status and risk timeline, reviews and daily overdue reminders.
- **Criminal groups**, with areas of operation and members.
- **Permissions, dashboard figures and tests.**

Checks:

- `make test`: 859 Pest tests (4861 assertions) plus 13 Vitest tests.
- `make lint` and `make ci` pass.
- The three acceptance items passed in the browser; `psql` confirms the access logs and the timeline events.

Commits:

| Step | Commit |
|---|---|
| 3B.1–3B.7 | `c928a27 feat(political): add political offices, persons of interest and criminal groups` |
| — | `docs: add phase 3b handover` (this file, README, prompt) |

**Why one feature commit:** the permissions and drift tests, the models, the scope contract, the dashboards and the pages depend on each other, as in earlier phases.

## Acceptance checklist

| Item | Result |
|---|---|
| In a demo LGA, record a chairman and councillors, a senator for the district, and see them on the district and LGA leadership pages | ✅ As `chief.lagos` (Demo LGA LA-1-1), recorded through the form:<br>- Adewale Balogun, **LGA chairman**;<br>- Folake Adeyemi, **councillor** for Demo Ward LA-1-1-01;<br>- Tunde Okafor, **councillor** for Demo Ward LA-1-1-02;<br>- Oluremi Adesanya, **senator** for Demo District LA-1 (no LGA).<br>**Form:** the fields followed the office type (LGA and ward for councillors, district for the senator). The chief's district list offered only their own district. Each holder got the "Political leader" role automatically.<br>**`/senatorial-districts`:** Demo District LA-1 · Lagos · 4 LGAs · senator Oluremi Adesanya.<br>**District page:** the LGAs with their chief and chairman; current holders (senator, chairman); prominent persons.<br>**LGA Leadership tab:** the chairman and both councillors, by ward. |
| Designate a person as POI, change status twice with comments, and see the timeline | ✅ Chidi Nwosu was designated: high risk, at large, offence category Cultism, with grounds. The person got the POI role; review due 2027-09-26.<br>**First change:** status → Under surveillance, with a comment.<br>**Second change:** status → Detained and risk → Critical, with a comment.<br>**Timeline** (newest first) shows all four entries with their comments, author and time. `activity_log` holds `poi_designated`, `poi_status_changed` ×2 and `poi_risk_changed`, with old and new values and the comments. `record_access_logs` has `poi` context entries for the chief. |
| Create a cult group operating in two LGAs with three members; an LGA chief in the second LGA (not the home LGA) cannot see it | ✅ **Created** as `sd.lagos`: "Jetty Boys Confraternity" (cult, home LGA **LA-1-2**), areas of operation LA-1-2 and **LA-1-1**, members Segun Alabi (leader, confirmed), Emeka Obi (armourer) and Dayo Ajayi. Each member showed a "Not a person of interest — designate?" suggestion.<br>**As `chief.lagos` (LA-1-1):** the group page, its members tab and its edit page are **404**, and the group list does not include it; the chief's dashboard is 200. |
| `make test` and `make lint` pass | ✅ (and `make ci`; its first run failed on a Docker Hub network timeout before any check ran, and the retry passed) |

## What was built

### 3B.1 — Political offices

- **Table `political_offices`** with the prompt's columns. Database guarantees:
  - composite FKs: district → state, LGA → state, ward → LGA;
  - CHECKs: a senator has a district; chairmen, vice chairmen and councillors have an LGA; a councillor has a ward; a ward needs an LGA; `ended_on` is not before `started_on`.
- **The explicit LGA rule:** a new contract, `App\Support\Jurisdiction\Contracts\LgaVisibilityRule`, applied identically by `JurisdictionScope` (SQL) and `JurisdictionGate` (one record). `PoliticalOffice` implements it: an LGA chief sees offices of their LGA, and offices with **no LGA** whose senatorial district contains their LGA. State-level users see every office in their states.
- **The holder must be visible:** a global scope on offices requires the person to be visible, so a classified person's offices never reveal them.
- **Recording an office** (`SavePoliticalOffice`) activates the person's `political_leader` role (`RoleAssigner`).
- **Overlap warnings** (`OfficeOverlaps`): single seats are governor and deputy per state, senator per district, chairman and vice per LGA, councillor per ward. Other current holders produce a **warning toast**, never an error.
- **Request:** `PoliticalOfficeRequest` checks the type rules, that the district and LGA belong to the state, that the LGA lies in the district when both are given, and that the ward belongs to the LGA. The office as saved must be visible to the user.

### 3B.2 — Senatorial district pages and LGA leadership

- **`/senatorial-districts`:** districts within jurisdiction (`JurisdictionOptions::districtQuery`), with state, number of active LGAs and current senators.
- **`/senatorial-districts/{district}`**, bound within jurisdiction like `{lga}`:
  - the LGAs, with current chief and chairman;
  - current holders (senator; reps and assembly members whose office is recorded against an LGA of the district; LGA chairmen);
  - prominent persons (active political, traditional, religious or community leader roles in the district's LGAs);
  - past holders, in a collapsed section.
- **LGA Leadership tab:** chairman, vice chairman, councillors by ward, traditional rulers of its communities, religious leaders of its institutions, community leaders.
- **One source:** `App\Support\Political\Leadership` builds both, entirely through the scopes. The current-chief lookup moved there from `LgaController`.

### 3B.3 — Persons of interest

- **Table `persons_of_interest`** with the prompt's columns. Database guarantees:
  - a **composite FK `(person_id, lga_id, state_id)` → persons, ON UPDATE CASCADE**, so the POI's place always equals the person's;
  - one POI per person among non-deleted rows;
  - CHECKs on risk, status and the offence array;
  - a GIN index on `offence_categories`.
- **Visibility:** `HasJurisdiction` plus a global scope requiring the person to be visible (the person's jurisdiction **and classification**). There is no classification column. `PersonOfInterestPolicy` also checks the person policy.
- **Access logging:** viewing the POI tab logs `record_access_logs` on the POI with context `poi`.
- **Actions:**
  - `DesignatePersonOfInterest`: grounds required; review due in `BUFFALO_POI_REVIEW_MONTHS` months; role activated.
  - `SavePoiDetails`: offences, modus operandi, location and sighting. A location change is a named event.
  - `ChangePoiStatus`: always with a comment; separate `poi_status_changed` and `poi_risk_changed` events with old and new values.
    - **cleared:** needs `poi.clear`; review in 30 days; role deactivated. Leaving "cleared" reactivates the role.
    - **deceased:** the person is marked deceased.
  - `MarkPoiReviewed`: comment required; next review defaults to 12 months ahead.
  - `DeletePersonOfInterest`: with a reason; deactivates the role.
- **Offence categories** are threat type codes, registered with `ReferenceUsageRegistry::jsonArray(..., 'code')`.
- **Reminders:** `buffalo:poi-review-reminders` runs daily at 07:00. It notifies the owning LGA chief (per LGA) and the State Director (per state) through `NotifyRole`, with **counts only**. Each overdue due date is reminded once (`review_reminded_for`).

### 3B.4 — Criminal groups

- **`criminal_groups`:** `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` (each view is logged with context `tab:<name>`). `search_name` is a generated column with a trigram index. The home LGA is fixed once created.
- **`criminal_group_areas`:** any LGA in any state. Composite FK; unique per group.
- **`criminal_group_members`:** unique per group and person. Only persons the user can see may be added. Members whose person the viewer can't see are neither shown nor counted, and can't be changed by that viewer. Adding a member never makes them a POI; the UI suggests it.
- **Audit:** area and membership changes are named events on the group (and on the person). Descriptions carry no names, only ULIDs (see the deviations).

### 3B.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA chief |
|---|:-:|:-:|:-:|:-:|:-:|
| `political.view`, `political.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `political.delete` | ✓ | ✓ | ✓ | | |
| `poi.view`, `poi.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `poi.clear` | ✓ | ✓ | ✓ | | |
| `poi.delete` | ✓ | ✓ | ✓ | | |
| `criminal_groups.view`, `criminal_groups.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `criminal_groups.delete` | ✓ | ✓ | ✓ | | |

The super admin gets none. Deletion always requires a reason.

### 3B.6 — UI

- **Navigation:**
  - Local Government → **Senatorial districts**;
  - Intelligence → **Persons of interest**, **Criminal groups**.
- **Person page tabs:**
  - **Political offices** (current and past; record, edit, delete);
  - **Person of interest** (details, timeline, the status/risk form with a required comment, Mark reviewed, "Designate as person of interest" when not one);
  - **Group memberships**.
- **`/persons-of-interest`:** filters for risk, status, offence category, LGA and review overdue. Sorted by risk, then last sighted.
- **`/criminal-groups`:** list and filters. The group page has Overview, Areas of operation (LGA markers at centroids; a list when centroids are missing), Members and History tabs.
- **Dashboards** (all through the scopes):
  - **LGA:** open POIs by risk, overdue reviews;
  - **State:** open POIs by LGA and risk, overdue reviews, active groups;
  - **National:** open POIs by state and risk, active groups by type.

### 3B.7 — Tests

- **`tests/Feature/Political/PoliticalOfficesTest.php`:**
  - validation per type (13 cases), plus the database CHECKs;
  - the role added on recording; what an LGA chief may record;
  - the district visibility rule, in the scope and the gate;
  - holder classification hides the office;
  - overlap warnings; deletion rules; the person page tab.
- **`LeadershipPagesTest.php`:** district and LGA pages show the right current holders, exclude ended offices, and respect jurisdiction.
- **`tests/Feature/PersonsOfInterest/PersonsOfInterestTest.php`:**
  - the jurisdiction matrix; visibility through the person's classification; access logging with context `poi`;
  - designation; place sync in the database; status and risk changes with comments and old/new values;
  - clearing (with `poi.clear`, 30 days, the role); deceased; reviews;
  - reminder targeting and once-only sending; list order and filters; deletion; permissions per role.
- **`tests/Feature/CriminalGroups/CriminalGroupsTest.php`:**
  - the scope matrix and classification;
  - **areas never grant visibility**, including a zone-B DDG;
  - members must be visible; hidden members are not shown or changeable;
  - memberships on the person page; access logging; deletion; dashboard figures.

## Deviations and conflicts

- **State-wide offices are not visible to LGA chiefs.** The prompt's rule reads: "for offices without an LGA, LGA chiefs see them only if the office's senatorial district contains their LGA". A governor or commissioner (no district, no LGA) is therefore **not visible to LGA chiefs**, and LGA chiefs cannot record such offices. I implemented the rule literally (fail closed). **Please confirm**, or say if LGA chiefs should see state-wide offices of their state.
- **Offices, POIs, areas and members are also scoped through their "parent"** (the person, or the group), not only by their own columns:
  - an office or POI is visible only when its person is;
  - an area or membership only when its group is.

  For areas, this resolves a conflict with `CLAUDE.md` rule 2: the area rows carry `state_id`/`lga_id`, but those describe the group; they are not the record's jurisdiction. Scoping areas by their own LGA would hide a Lagos group's Ogun areas from its Lagos owners, and would *grant* visibility, which the prompt forbids. **Please confirm.**
- **Extra delete permissions:** `political.delete`, `poi.delete` and `criminal_groups.delete` (State Director and above, as in Phase 2). The prompt names the rule for POIs and groups; for offices, deletion is for mistaken records. Ending a term is done by editing the end date.
- **POI designation requires a comment** (the grounds), and a POI cannot be designated directly as "cleared".
- **Reminders are sent once per overdue due date** (a new `review_reminded_for` column), not every day for the same record. The LGA chief gets one notice per LGA and the State Director one per state, with counts only: notification payloads never contain POI names.
- **The POI's state and LGA are kept in sync by the database** (composite FK, ON UPDATE CASCADE) rather than in application code. A person's LGA is fixed anyway (3A).
- **Audit descriptions no longer name the *other* record's person.** This also changes 3A: association events and group membership events on a person or group say "Association added (Employer)" or "Member added", with the other record's ULID in the properties. A history reader may see one record and not the other.
- **Areas map:** the demo LGAs have no centroids, so the Areas tab shows the list with a notice. Markers appear once centroids are imported.
- **Dashboard POI counts are "open" POIs**, excluding cleared and deceased; overdue reviews count all POIs.

## New packages

None.

## Known issues and notes

- **Leadership data comes through the scopes.** On a district page an LGA chief sees the other LGAs' names (reference data), but not their chiefs or chairmen. This is intended.
- **House of Reps and State Assembly members** appear on a district page only when recorded against an LGA of the district (the form says so). There is no constituency table.
- **Other open items:** offices can't be re-assigned to another person (delete and re-record); POI records are not merged; no marker clustering.
- **Dev database changes:**
  - two synthetic wards in Demo LGA LA-1-1 (`LA-1-1-W01/W02`; dev only, not a seeder);
  - eight fictional persons (Adewale Balogun, Folake Adeyemi, Tunde Okafor, Oluremi Adesanya, Chidi Nwosu, Segun Alabi, Emeka Obi, Dayo Ajayi);
  - their offices, Chidi Nwosu's POI record, and the group "Jetty Boys Confraternity";
  - the new permissions were seeded and Horizon restarted.

  `make fresh` resets the data.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next step (3C)

- **Grants must pass through the new scopes too:** POIs, offices, areas and members are visible through their person or group, so a grant on a person should make their POI visible (it does automatically, through `whereHas('person')`). Decide whether a grant on a person also reveals their groups.
- **Update and delete must use the user's own jurisdiction:** `PoliticalOfficePolicy`, `PersonOfInterestPolicy` and `CriminalGroupPolicy` use `JurisdictionGate::canAccess`, which will include grants. Grants are read-only.
- **`LgaVisibilityRule`** exists for LGA-level rules; `AccessGrantResolver::addGrantedRecords` still ORs grants into the same nested group.
- **National match-check:** it can reuse `PersonSearch`, and POIs via `PersonOfInterest` inside `runAsSystem()`.
