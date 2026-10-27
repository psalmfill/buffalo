# BUFFALO — Phase 11F: States Section

Read `CLAUDE.md` before starting. Read `docs/handover/phase-11-summary.md` and, for the pattern to mirror, `docs/handover/phase-2b.md` (LGA profiles and pages).

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

The side menu has a **Local Government** section with LGA list and detail pages, but states have no pages of their own: they appear only inside dashboards (including the 11A state dashboard), filters and other records. The client wants **States** in the side menu, working the same way as LGAs.

The **state page** is the state's reference profile (what the state is: geography, leadership, security presence, LGAs). The **state dashboard** from 11A stays the operational view (what is happening now). Each links to the other.

---

## Step 11F.1 — State profiles

`state_profiles` table (one per state, created on first edit), mirroring `lga_profiles`:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `state_id` | unique FK |
| `history` | rich text (11B), nullable |
| `economic_activities` | rich text, nullable |
| `terrain` | jsonb array, same fixed values as LGA profiles |
| `major_roads`, `waterways` | rich text, nullable |
| `borders` | rich text, nullable: neighbouring states and **international borders** (for example with Benin, Niger, Chad or Cameroon), border crossings |
| `general_notes` | rich text, nullable |
| `last_reviewed_at`, `last_reviewed_by` | |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

- Plain text copies for rich fields as in 11B; `LogsBuffaloActivity`.
- **Visibility:** `HasJurisdiction` on `state_id`, with an explicit rule (like `LgaVisibilityRule` for political offices) letting an **LGA chief read their own state's profile**. It is general background, useful context for an LGA officer. Editing stays with state-level and national roles. Test both directions.
- **Completeness:** computed like the LGA profile's (history, economic activities, terrain, roads, borders, and at least one LGA profile reviewed).
- **Review reminder:** reuse the LGA profile reminder job pattern, notifying the State Director and Deputy when the state profile has not been reviewed in `BUFFALO_PROFILE_REVIEW_DAYS`.
- Editable state facts for users with `state_profiles.update`: `capital` (already on `states`). Names, codes and zones stay admin and import only. Area and population are **computed** from the state's LGAs (sum of `area_km2` and `population_estimate`, with the number of LGAs missing a figure shown).

## Step 11F.2 — Pages

Navigation: rename the **Local Government** section to **States & LGAs**, with items **States** (above LGAs), LGAs, Senatorial districts and Communities.

**`/states`** (permission `states.view`):
- National users: all states within reach (a zoned DDG sees their zones' states), grouped by zone or alphabetical, with filters for zone and the 11A state lens applied as the default filter.
- State Director and Deputy: redirected straight to their own state's page.
- LGA chiefs: redirected to their own state's page (read-only, limited tabs; see below).
- Columns: state, zone, capital, LGAs, State Director (from current postings; "Vacant" if none), profile completeness, last reviewed, incidents in the last 30 days (counted per the statistics rule and the viewer's scopes), active `immediate` threats.

**`/states/{ulid}`**, with tabs:

| Tab | Content | LGA chief sees it? |
|---|---|---|
| **Overview** | Zone, capital, computed area and population, number of LGAs, districts and communities, current State Director and Deputy, profile completeness and last reviewed, **Mark as reviewed**, a map of the state boundary with its LGAs (and a link to the operational map focused on the state), and **Open state dashboard** (11A) for users who may open it | Yes, without the dashboard link and figures outside their LGA |
| **Profile** | View and edit the state profile | Yes, read-only |
| **LGAs** | The state's LGAs: district, headquarters, LGA chief, profile completeness, incidents (30 days) | Only their own LGA's row |
| **Senatorial districts** | The state's districts with their LGAs and current senators | Yes (names and senators only) |
| **Leadership** | Current state-level office holders: governor, deputy governor, senators, House of Representatives and State Assembly members, commissioners, special advisers, party officials, grouped by office (from political offices; past holders collapsed) | Yes (public office holders only) |
| **Security presence** | Agency formations in the state grouped by agency, with commands first; active flashpoints and blackspots by concern level; active threat reports by urgency; disseminations received (7A) | No |
| **Organizations** | High and critical-infrastructure sites in the state, by LGA | No |
| **Incidents** | The last 90 days summarised by type and by LGA, with links to the incident list filtered to the state | No |
| **History** | Activity log for the state profile | No |

- Every tab's content goes through the viewer's own scopes and clearance. Tabs a user cannot use are hidden, not empty.
- Wherever a state name appears as text across the app (incident pages, LGA pages, filters' chips, dashboards' state grid), link it to the state page for users who can open it.

## Step 11F.3 — Exports, printing and search

- **List export:** register a `states` exporter in the 11E registry (non-sensitive), so `ListExporterCoverageTest` passes.
- **State profile PDF:** a `state_profile` report type mirroring the 7D LGA profile: profile, computed facts, LGAs summary, districts, current **public office holders** only, security presence summary (counts, not details), flashpoints by concern level, recent incident summary. No persons of interest, no criminal group members. Same markings, logging and permissions as the LGA profile PDF.
- **Global search:** extend the "LGAs and districts" provider to include states (name and code, within reach).

## Step 11F.4 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `states.view` | ✓ | ✓ | ✓ | ✓ | ✓ (own state, limited tabs) |
| `state_profiles.update` | ✓ | ✓ | ✓ | ✓ | |

Super admin: none. Update the matrix, drift tests, `auth.ts` and the route inventory, and add the new pages to the browser check (`make browser-check`).

## Step 11F.5 — Tests

- State profile visibility: each role, including the LGA chief's read-only rule for their own state and denial for other states; editing rights.
- `/states` per role: national list (zoned DDG limited), redirects for state and LGA roles, lens default filter.
- Each tab: content matches the viewer's scopes; hidden tabs for LGA chiefs; LGAs tab shows only their own LGA for them.
- Computed area and population, with missing-figure counts.
- Leadership tab shows only current office holders (past collapsed), state-level offices only.
- Completeness and review reminders.
- Exporter registered; state profile PDF content and markings; search finds states.
- Browser check covers the new pages for a national user, a State Director and an LGA chief.

## Acceptance checklist

- [ ] The side menu shows **States & LGAs** with States first.
- [ ] The DG opens States, sees all 37 grouped by zone with Lagos's figures, opens Lagos, and moves between the state page and the Lagos state dashboard.
- [ ] The Lagos State Director lands directly on Lagos's page, completes its profile including international and state borders, and marks it reviewed.
- [ ] A Lagos LGA chief sees Lagos's overview, profile (read-only), districts and leadership, only their own LGA in the LGAs tab, and no security or incident tabs.
- [ ] The state profile PDF downloads with markings and no persons of interest.
- [ ] `make test`, `make lint`, `make ci` and `make browser-check` pass.

## Handover

Write `docs/handover/phase-11f.md` per CLAUDE.md section 9 and print it.
