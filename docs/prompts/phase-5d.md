# BUFFALO — Phase 5D: Incident Lists, Search, Map and Dashboards

Read `CLAUDE.md` before starting. Read `docs/handover/phase-5c.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Incidents can be reported, evidenced, submitted, verified, escalated, followed up and closed. This step makes them findable and visible: the incident list with search and filters, the incident map, the completed detail page, dashboards, and incident tabs on other records. It completes Phase 5.

**Statistics rule for this step and all later ones:** counts and figures exclude `draft` and `invalid` incidents unless a view is explicitly about drafts or invalid reports.

---

## Step 5D.1 — Incident list

`/incidents` (permission `incidents.view`), server-side pagination (25), sorting and filtering, all through jurisdiction and classification scopes.

**Columns:** reference, type, title, LGA (and state for national users), occurred at, severity, status, escalation badge, casualties summary (e.g. "3 killed, 2 abducted"), reporting officer.

**Filters:** incident type (multi), category, severity, status (multi; default excludes `draft`, `closed`, `invalid`), escalated (yes/no, unacknowledged), occurred between, state and LGA (within reach), community (picker), has casualties, casualty outcome, overdue verification, reporting officer (picker), involves a criminal group (picker).

**Search:** one box that detects a reference number (`BUF/...`, exact) or otherwise runs full-text search on `search_vector` (use `websearch_to_tsquery`, ranked).

**Saved filters:** users can save the current filter set with a name (per user, `incident_saved_filters` table: `user_id`, `name`, `filters` jsonb). Provide defaults: "Awaiting my verification" (SD/DSD), "Escalated, unacknowledged" (national), "My reports" (everyone), "My drafts".

Filters are reflected in the URL so views can be shared between officers; each recipient still sees only what their own scopes allow.

## Step 5D.2 — Incident map

1. Install `leaflet.markercluster` (MIT; approved in CLAUDE.md). Add clustering support to `BuffaloMap` as an option, and apply it to the existing community, formation and site layers where marker counts can be large.
2. `/incidents/map`: the same filters as the list (shared filter component), with a date range defaulting to the last 30 days.
   - Markers coloured by severity, shaped or iconed by category; clusters show counts.
   - Popup: reference, type, occurred at, status, link. No descriptions or names.
   - Incidents without a location are counted in a notice ("12 incidents in this view have no map location") with a link to the list filtered to them.
3. Map data endpoint returns only what the popup needs and caps at 5,000 points (with a notice asking to narrow filters beyond that).

Heatmaps and flashpoint layers come in Phase 6.

## Step 5D.3 — Incident detail page (complete)

Tabs: **Overview** (all core fields, type details via `DynamicFieldsView`, location map, action taken, workflow summary), **People** (casualties table with totals; persons involved; criminal groups), **Response** (agencies and formations; property affected), **Evidence** (5B), **Timeline** (5C), **Possible duplicates** (verifiers only, 5C), **History** (audit log).

- Print-friendly view (`/incidents/{ulid}/print`): a clean single-page layout of the overview, people, response and timeline, with the classification level printed at the top and bottom of every page, and "Printed by [name] on [date]" in the footer. Printing is logged in `record_access_logs` with action `print`. Evidence is listed (titles only), not embedded. Permission: `incidents.view` plus clearance for everything included.

## Step 5D.4 — Incident tabs on other records

Each tab lists incidents visible to the viewer, with the same columns as the list, and hides the tab entirely when empty:

| Page | Incidents shown |
|---|---|
| Person | Incidents where the person is linked, with their involvement. Under a person **grant**, the tab is hidden (only the person is grantable). |
| Criminal group | Incidents linking the group. |
| Agency formation | Incidents where the formation responded. |
| Organization site | Incidents affecting the site's property. |
| Community | Incidents in the community. |
| LGA | Incidents in the LGA, with the last 90 days summarised by type. |

## Step 5D.5 — Dashboards

Replace the remaining incident placeholders. All figures go through jurisdiction and classification scopes and exclude drafts and invalid incidents. Keep charts simple (ApexCharts bar and line); deeper analytics come in Phase 7.

**LGA chief**
- Incidents in the last 30 days by type (bar).
- My open reports by status; returned reports needing my action (highlighted).
- My drafts.
- Latest 5 incidents in my LGA.

**State Director / Deputy**
- Awaiting verification, with overdue ones highlighted and the time since submission.
- Critical and high incidents open in the state.
- Incidents in the last 30 days by LGA and by type.
- Casualties in the last 30 days by outcome (killed, injured, abducted, rescued).
- Escalated incidents and whether national has acknowledged.
- A small map of the last 7 days' incidents in the state.

**DG / DDG**
- Escalated incidents awaiting acknowledgement (top of the page).
- Critical incidents in the last 7 days, nationally or in the DDG's zones.
- Incidents in the last 30 days by state (bar) and by category.
- Casualties in the last 30 days by outcome and by group (civilian, security personnel, suspect), and security personnel casualties by agency.
- States with overdue verifications.
- A national map of the last 7 days' critical and high incidents.

Dashboard counts update live when relevant incident events arrive over the 5C channels (refetch the affected widgets, debounced).

## Step 5D.6 — Performance

- Add indexes supporting the default list and dashboard queries (e.g. `(state_id, status, occurred_at)`, `(lga_id, occurred_at)`, `(incident_type_id, occurred_at)`, GIST on `location`). Report `EXPLAIN` results for the list default view and the state dashboard's main queries in the handover.
- Seed a performance dataset (a local-only Artisan command `buffalo:seed-demo-incidents --count=50000`, synthetic, refusing to run outside `local`) and confirm the list, map and dashboards load in under 1 second at that volume. Report timings.

## Step 5D.7 — Permissions

No new permissions except saved filters (always available to `incidents.view` holders, each user managing only their own). Print uses `incidents.view`.

## Step 5D.8 — Tests

- List: each filter, search by reference and full text, sorting, pagination; out-of-reach and above-clearance incidents never appear; drafts of other users never appear; default status filter.
- Saved filters: per user only; URL filters applied through the viewer's own scopes.
- Map endpoint: payload contains only popup fields; cap and notice; missing-location count.
- Detail tabs: each section respects child-row scopes and clearance.
- Print view: classification markings, footer, `print` access log entry, excludes content above clearance.
- Incident tabs on other pages: correct incidents; person tab hidden under a grant; tabs hidden when empty.
- Dashboard figures for each role against a fixed fixture set, including exclusion of drafts and invalid incidents and the clearance rule.
- `buffalo:seed-demo-incidents` refuses to run outside `local`.

## Phase 5 completion checklist

- [ ] With 50,000 demo incidents, the list, map and dashboards load in under a second.
- [ ] A State Director filters to "critical, last 7 days, escalated, unacknowledged", saves it, and shares the URL with a DSD, who sees only what their clearance allows.
- [ ] The incident map clusters markers and opens popups with no descriptions or names.
- [ ] A person's page shows the incidents they were involved in; the same person viewed under a grant shows no incidents tab.
- [ ] Printing an incident shows classification markings and appears in the access log.
- [ ] Each role's dashboard shows correct live figures as a new incident is submitted.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-5d.md` per CLAUDE.md section 9, and `docs/handover/phase-5-summary.md` summarising all of Phase 5: tables, workflow and permissions, notification rules, performance results, and open issues for Phase 6. Print both.
