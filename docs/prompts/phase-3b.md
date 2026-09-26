# BUFFALO — Phase 3B: Political Information, Persons of Interest and Criminal Groups

Read `CLAUDE.md` before starting. Read `docs/handover/phase-3a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 3A built the persons record: encryption, photos, roles, aliases, associations, and in-jurisdiction duplicate warnings. This step adds the political and administrative information from the brief (section B), persons of interest and criminal groups (section C). Everything remains **in-jurisdiction only**; cross-jurisdiction behaviour is 3C.

---

All new location-bearing tables in this step follow the composite foreign key pattern from Phase 2 (`CLAUDE.md` decisions log).

## Step 3B.1 — Political offices

`political_offices` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `person_id` | FK; the person must hold the `political_leader` role (add it automatically when an office is recorded) |
| `office` | enum: `governor`, `deputy_governor`, `senator`, `house_of_reps_member`, `state_assembly_member`, `lga_chairman`, `lga_vice_chairman`, `councillor`, `commissioner`, `special_adviser`, `party_official`, `other` |
| `office_title` | nullable string (e.g. "Commissioner for Works", or the title when `other`) |
| `party` | nullable string |
| `state_id` | required |
| `senatorial_district_id` | required for `senator`; must belong to the state |
| `lga_id` | required for `lga_chairman`, `lga_vice_chairman`, `councillor`; must belong to the state |
| `ward_id` | nullable; required for `councillor`; must belong to the LGA |
| `constituency_name` | nullable string (House of Reps / State Assembly constituencies) |
| `started_on`, `ended_on` | `ended_on` nullable (current office) |
| `created_by`, `updated_by`, timestamps, soft deletes | |

- `HasJurisdiction` on the office's `state_id`/`lga_id`. For offices without an LGA (e.g. senator), state-level users see them; LGA chiefs see them only if the office's senatorial district contains their LGA. Implement this as an explicit rule in the model's scope configuration and test it.
- A person may hold several offices over time. Overlapping holders of single-seat offices (e.g. two current governors of one state) produce a **warning**, not an error; records may be imperfect.
- `LogsBuffaloActivity`.

## Step 3B.2 — Senatorial district pages

Navigation: add "Senatorial districts" under "Local Government" (`lgas.view`).

**`/senatorial-districts`** — districts within jurisdiction with state, number of LGAs, current senator.

**`/senatorial-districts/{ulid}`**:
- LGAs in the district, each with its current LGA chief and chairman.
- Current office holders connected to the district: senator, House of Reps members and State Assembly members whose constituency is recorded against LGAs in the district, LGA chairmen of its LGAs.
- Prominent persons in the district: persons with `political_leader`, `traditional_ruler`, `religious_leader` or `community_leader` roles whose LGA is in the district.
- Past office holders (collapsed section).

Add a **"Leadership"** tab to the LGA page: current chairman, vice chairman, councillors by ward, traditional rulers of its communities, religious leaders, community leaders.

## Step 3B.3 — Persons of interest

`persons_of_interest` table (one per person; creating it adds the `person_of_interest` role, closing it deactivates the role):

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `person_id` | unique FK |
| `state_id`, `lga_id` | copied from the person, kept in sync |
| `risk_level` | enum: `low`, `medium`, `high`, `critical` |
| `status` | enum: `at_large`, `under_surveillance`, `detained`, `on_trial`, `convicted`, `deceased`, `cleared` |
| `offence_categories` | jsonb array of `threat_types` codes (from 2A); register this usage with `ReferenceUsage` (3A.4b) |
| `known_offences` | text |
| `modus_operandi` | text, nullable |
| `last_known_location` | nullable point |
| `last_known_location_description` | nullable text |
| `last_sighted_at` | nullable datetime |
| `review_due_at` | date, required, default 12 months from creation (`BUFFALO_POI_REVIEW_MONTHS`) |
| `last_reviewed_at`, `last_reviewed_by` | |
| `created_by`, `updated_by`, timestamps, soft deletes | |

Rules:
- `HasJurisdiction`; classification comes from the parent **person** record (a POI is visible only when its person is). Do not add a separate classification column; enforce through the person relationship and test it.
- `LogsReads`: viewing POI details is access-logged with context `poi`.
- Every status change and risk level change is a named activity event with old and new values and a mandatory comment.
- Status `cleared`: requires a comment; sets `review_due_at` to 30 days ahead so the record is reviewed for archiving; deactivates the POI role but keeps the history.
- Status `deceased` also sets `persons.is_deceased`.
- **Review reminders:** a daily scheduled job notifies the owning LGA chief and State Director (via `NotifyRole`) of POIs past `review_due_at`. "Mark reviewed" requires a short comment and sets the next review date.

## Step 3B.4 — Criminal groups

`criminal_groups` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id`, `lga_id` | owning jurisdiction (where the group is primarily based) |
| `name` | required |
| `search_name` | normalised, trigram index |
| `other_names` | jsonb array |
| `type` | enum: `cult`, `bandit_group`, `kidnap_syndicate`, `armed_robbery_gang`, `piracy_gang`, `oil_theft_network`, `terrorist_group`, `militia`, `other` |
| `description` | text |
| `estimated_size` | nullable integer |
| `status` | enum: `active`, `dormant`, `dismantled`, `unknown` |
| `classification` | enum, default `restricted` |
| `created_by`, `updated_by`, timestamps, soft deletes | |

`criminal_group_areas`: `criminal_group_id`, `state_id`, `lga_id`, `notes`. LGAs where the group operates (may span states). The group itself is still owned and scoped by its home jurisdiction; an LGA listed as an area of operation does **not** grant visibility. (Cross-state visibility comes from 3C and Phase 7.)

`criminal_group_members`: `criminal_group_id`, `person_id`, `role_in_group` (nullable string, e.g. "leader", "armourer"), `membership_status` (enum: `suspected`, `confirmed`, `former`), `since` (nullable date), `notes`, `created_by`, timestamps. Unique (`group`, `person`). Adding a member does not automatically make them a POI, but the UI suggests it.

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` on groups.
- A user can add only persons they can see as members.

## Step 3B.5 — Permissions

| Permission | DG | DDG | State Dir. | Deputy | LGA Chief |
|---|---|---|---|---|---|
| `political.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |
| `poi.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |
| `poi.clear` | ✅ | ✅ | ✅ | | |
| `criminal_groups.view` / `.manage` | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ | ✅/✅ |

Super admin: none. `poi.clear` is needed to set status `cleared`. Deleting a POI record or group requires State Director or above, with a reason.

## Step 3B.6 — UI

Navigation under "Intelligence": "Persons of interest" (`poi.view`), "Criminal groups" (`criminal_groups.view`).

- **`/persons-of-interest`**: list within jurisdiction; filters for risk level, status, offence category, LGA, review overdue. Default sort: risk level then last sighted.
- **Person page** gains tabs: **Political offices** (current and past; add/edit), **Person of interest** (details, status and risk history timeline, "Designate as person of interest" button when not one, "Mark reviewed"), **Group memberships**.
- **`/criminal-groups`**: list with type, status, size, member count, classification; filters. **`/criminal-groups/{ulid}`**: overview, areas of operation (map of LGAs; LGA markers at centroids when available, list otherwise), members (with links to persons), history.
- Dashboards: LGA chief — POIs in their LGA by risk level, reviews overdue. State roles — POIs by LGA and risk, overdue reviews, active groups. National — POIs by state and risk, active groups by type.

Run `make wayfinder` after route changes.

## Step 3B.7 — Tests

- Political office validation per office type (district for senator, LGA for chairman/councillor, ward for councillor, all within the state).
- Office visibility rule for LGA chiefs via senatorial district.
- Single-seat overlap produces a warning, not an error.
- Senatorial district and LGA leadership pages show the right current holders and exclude ended offices.
- POI visibility follows the person's classification; POI views access-logged with context `poi`.
- Status/risk changes require a comment and are logged; `cleared` requires `poi.clear`, sets review in 30 days, deactivates the role; `deceased` updates the person.
- Review reminder job targets the right users.
- Criminal group scope and classification; areas of operation do not grant visibility; members must be visible to the user.
- Deletion rules with reason.

## Acceptance checklist

- [ ] In a demo LGA, record a chairman and councillors, a senator for the district, and see them on the district and LGA leadership pages.
- [ ] Designate a person as POI, change status twice with comments, and see the timeline.
- [ ] Create a cult group operating in two LGAs with three members; an LGA chief in the second LGA (not the home LGA) cannot see it.
- [ ] `make test` and `make lint` pass.

## Out of scope

Cross-jurisdiction matching, hit notices, access requests, delegation, grants (3C).

## Handover

Write `docs/handover/phase-3b.md` per CLAUDE.md section 9 and print it.
