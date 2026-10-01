# Handover — Phase 5D: Incident lists, search, map and dashboards

Date: 2026-10-01

## Summary

Incidents are now findable and visible:

- **The incident list:** filters, search, saved views and shareable URLs.
- **The incident map:** clustered markers coloured by severity and shaped by category.
- **The full detail page:** seven tabs, plus a print view marked with the classification and recorded in the access log.
- **Incidents tabs** on persons, criminal groups, formations, organization sites, communities and LGAs.
- **Incident figures on all three dashboards,** with live refresh.

At 50,000 incidents, every view loads well within one second. This completes Phase 5; see also `phase-5-summary.md`.

**Statistics rule, from this step on:** counts and figures leave out drafts and invalid reports, unless a view is explicitly about them (for example "My drafts").

Final checks:

- `make lint` passes.
- `make ci` passes: 1225 Pest tests (7993 assertions) in a clean copy, plus the Vitest suites.
- A separate `make test` run had one failure: a 5A test that expected `/incidents` to list drafts. It now asks for "My drafts" explicitly. The CI run above includes the fix.

## Phase 5 completion checklist

All on dev, with 50,004 incidents: 50,000 synthetic, plus the acceptance incidents.

- [x] **With 50,000 demo incidents, the list, map and dashboards load in under a second.**

  Server response times, best of 4, over HTTP with a signed-in session:

  | View | State Director (Lagos, 24,796 incidents in state) | DG (national) | LGA chief |
  |---|---|---|---|
  | `/incidents` (default view) | 0.137 s | 0.132 s | 0.132 s |
  | `/incidents?q=synthetic` (full text over all) | 0.153 s | 0.168 s | 0.140 s |
  | `/incidents/map/data` (last 30 days, cap 5,000) | 0.194 s | 0.294 s | 0.126 s |
  | `/dashboard` | 0.287 s | 0.265 s | 0.187 s |

  In the browser, the SD's list page reached DOMContentLoaded at 470 ms (Vite dev server).
- [x] **A State Director filters to "critical, last 7 days, escalated, unacknowledged", saves it, and shares the URL with a DSD.**
  - Filtered in the browser: 54 incidents. URL: `?escalated=unacknowledged&from=2026-09-24&severities[0]=critical`.
  - Saved as "Critical escalations this week".
  - The DSD, opening the same URL, gets 53. The database confirms exactly one secret incident in that set.
  - The SD's saved view does not appear for the DSD.
- [x] **The incident map clusters markers and opens popups with no descriptions or names.**
  - Clusters with counts (2,725 at Lagos), splitting as you zoom; markers are shaped by category.
  - The popup text is exactly "DEMO/LA/D24/2026/0022145 / Banditry · 15 Sept 2026, 10:54 · Submitted".
  - "672 incidents in this view have no map location", with a link to them in the list.
- [x] **A person's page shows the incidents they were involved in; under a grant, there is no incidents tab.**
  - As `chief.lagos`, Chidi Nwosu's Incidents tab lists `BUF/LA/D11/2026/000003` with involvement "Suspect".
  - Aminu Dogo Kabiru (Kano), seen by the chief under a grant, shows only Profile, although he is linked to the same incident in the chief's own LGA.
- [x] **Printing shows classification markings and appears in the access log.**
  - `/incidents/{ulid}/print` shows "RESTRICTED" at the top and "RESTRICTED · Printed by Tunde Lagos Chief on 1 Oct 2026, 09:05" at the bottom of every page.
  - `record_access_logs` has `print | print | chief.lagos`.
- [x] **Each role's dashboard shows correct live figures as a new incident is submitted.**
  - With the SD's dashboard open, the chief submitted a critical incident (`BUF/LA/D11/2026/000004`).
  - Without any navigation (one navigation entry), the SD's dashboard went from "Awaiting verification: 3551" to 3552, and from "Critical and high open: 3502" to 3503, with the live toast.
  - The DG's figures (fetched over HTTP) went from 1268 to 1269 escalated-unacknowledged, 308 to 309 critical in 7 days, and 9652 to 9653 incidents in 30 days.
  - The chief's "Latest incidents" now starts with `…/000004`.
  - The browser can hold one session at a time, so live refresh was watched on the SD's dashboard; it is the same hook on every role's dashboard.
- [x] **`make test`, `make lint` and `make ci` pass:** 1225 Pest tests and Vitest.

## What was built

### 5D.1 — Incident list

- **`IncidentFilters`** is the single filter class shared by the list, map, saved filters and URLs.
  - It validates input and drops unknown keys or invalid values, so old shared URLs still open.
  - Values are ULIDs and enum values only.
  - It always applies "others' drafts never appear" on top of the viewer's scopes.
  - Default statuses: submitted, returned, verified, under investigation, resolved.
- **Filters:**
  - type (multi), category, severity (multi), status (multi);
  - escalated: yes / no / unacknowledged;
  - from / to (Lagos dates);
  - state, LGA, community;
  - has casualties, casualty outcome;
  - overdue verification (5C deadlines, on the application clock);
  - reporting officer, criminal group;
  - my reports, no map location;
  - sort (time, reference, severity, status, type) and direction.
- **Search:** `BUF/…` matches `lower(reference_no)` exactly. Anything else uses `search_vector @@ websearch_to_tsquery('english', …)`, ranked by `ts_rank` unless a sort is chosen.
- **`IncidentRows`:** the shared list row, with a casualty summary ("3 killed, 2 abducted", one grouped query per page), the reporting officer (`OfficerNames`) and the escalation badge. No numeric ids.
- **`incident_saved_filters`** (`ulid`, `user_id`, `name`, `filters` jsonb; unique per user and name): a user saves the current view by name and removes their own. Anyone else's is not found.
- **Built-in views:**
  - "Awaiting my verification" (holders of `incidents.verify`);
  - "Escalated, unacknowledged" (national users);
  - "My reports" and "My drafts" (everyone).
- **`IncidentListController`:** `/incidents` replaces the 5A drafts list; `/incidents/map`; `/incidents/map/data`; saved-filter store and destroy. Pickers in a shared URL get their names back through the viewer's own scopes.

### 5D.2 — Map

- **`leaflet.markercluster` (MIT)** is wired into `BuffaloMap` through a new `cluster` prop. Markers can also take a shape: circle, square, diamond, triangle or ring.
- **Clustering is on for:**
  - the incident maps;
  - the dashboard maps;
  - the formation map;
  - the site maps;
  - the LGA communities map.
- **Map data** returns only the popup fields:
  - reference, type and category;
  - severity and status;
  - time and coordinates.

  Newest first, capped by `buffalo.incidents.map_cap` (5,000; `BUFFALO_INCIDENT_MAP_CAP`), with the total, whether it is capped, and the count without a location.

### 5D.3 — Detail page and print

- **Tabs:**
  - Overview: core fields, type details, location map, and workflow with action taken;
  - People: casualties with a totals row, persons, groups;
  - Response: agencies, formations, property;
  - Evidence;
  - Timeline;
  - Possible duplicates: reviewers only; others fall back to Overview;
  - History.
- **`/incidents/{ulid}/print`:** a bare page (no app shell). The classification bar is fixed at the top and bottom, so it repeats on every printed page, and the footer reads "Printed by … on …".
  - It includes the overview, people, response, evidence titles only, and the timeline.
  - Everything comes through the same scopes, so content above the viewer's clearance (for example a secret follow-up) is absent.
  - Logged as `print`.

### 5D.4 — Incidents tabs on other records

- **`IncidentLinks`** builds each scoped query (person, group, formation, site, community, LGA) and leaves out drafts and invalid reports.
- **Hidden when empty:** an `exists()` check before the tab is offered.
- **Under a person grant:** the tab is never offered, because the grant view has its own tab list.
- **Person tab:** adds the person's involvement per incident.
- **LGA tab:** adds the last 90 days by type.

### 5D.5 — Dashboards

`IncidentFigures` (all through scopes; no drafts or invalid reports):

- **LGA chief:**
  - last 30 days by type (bar chart);
  - my open reports by status;
  - returned reports highlighted;
  - my drafts;
  - latest 5 in the LGA.
- **SD/DSD:**
  - awaiting verification, with overdue rows highlighted and the time since submission;
  - critical and high open (count and list);
  - escalated, with acknowledgement shown;
  - last 30 days by LGA and by type;
  - casualties by outcome (killed, injured, abducted, rescued);
  - a map of the last 7 days (up to 500 points, clustered).
- **DG/DDG** (DDGs see their zones):
  - escalated awaiting acknowledgement (top of the page, with the true count);
  - critical in the last 7 days (count and list);
  - last 30 days by state and by category;
  - casualties by outcome and by group;
  - security personnel casualties by agency;
  - states with overdue verifications;
  - a map of the last 7 days' critical and high incidents.
- **Live refresh:** each dashboard listens on its 5C channel (`states.{ulid}.incidents` or `national.incidents`) and reloads only the `incidents` prop, debounced by 1.5 s.
- **Placeholder cards:** the "Incidents" and "Pending escalations" placeholders are gone; "Active flashpoints" (Phase 6) remains.

### 5D.6 — Performance

**Migration `2026_10_09_000001_add_incident_lists`:**

- `incidents (state_id, status, occurred_at)`;
- `(lga_id, occurred_at)`;
- `(incident_type_id, occurred_at)`;
- `(occurred_at, id)`;
- `(reporting_officer_id, status)`;
- a partial `incident_casualties (incident_id, outcome) WHERE deleted_at IS NULL`;
- `location` already had a GIST index (5A).

**`EXPLAIN (ANALYZE, BUFFERS)`** on dev at 50,004 incidents, as the Lagos State Director (24,796 incidents in the state):

| Query | Plan | Time |
|---|---|---|
| List, default view, page 1 (25 rows) | Index Scan Backward on `incidents_occurred_id_index`, filter on state, status, classification; 76 rows read | 2.5 ms |
| List, default view, total count (16,391) | Bitmap Index Scan on `incidents_state_id_index`, Bitmap Heap Scan | 49.5 ms |
| State dashboard: awaiting verification count | Bitmap Index Scan on the status index | 3.8 ms |
| State dashboard: last 30 days by LGA | Bitmap Index Scan on `incidents_state_status_occurred_index`, Hash Join on LGAs | 6.0 ms |
| State dashboard: casualties by outcome, 30 days | Hash Join (incidents via the state/occurred index, casualty rows) | 10.3 ms |

**`buffalo:seed-demo-incidents --count=N [--purge]`** (local only; refuses elsewhere):

- 50,000 incidents in about 20 seconds;
- spread over active LGAs, the last 365 days, all statuses, severities and classifications;
- 80% have a location near their state's centre; 40% have casualty rows;
- critical ones escalated.

Demo incidents are obviously fake: "[Demo] Synthetic incident N", references `DEMO/…`, which never touch the `BUF/` counters. `--purge` removes them; it needs a database superuser to switch triggers off, so it is local only.

### 5D.7 — Permissions

No new permissions. Saved filters come with `incidents.view`, and print uses `incidents.view`. Navigation gains **Incident map** (`incidents.view`).

## Deviations and decisions

1. **Charts use Chart.js, not ApexCharts** (your decision during this step). ApexCharts 5.x and later (7.6.1 current) is dual-licensed: free only under US$2M revenue, paid above. That conflicts with CLAUDE.md's permissive-licences rule.
   - Chart.js 4.5.1 (MIT) is used through our own small `BarChart` wrapper, which is tree-shaken to bar charts and includes an accessible table of the figures. No `react-chartjs-2`.
   - **CLAUDE.md section 2 still says "Charts: ApexCharts"; please update it.**
2. **Evidence view logging changed (5B behaviour):** evidence items are logged as viewed (`incident_page`) only when the Evidence tab shows them, not on every incident tab. The 5B test was updated.
3. **The overdue filter uses the application clock,** passed as a parameter, as the 5C reminder command does. It is consistent in tests (time travel) and production.
4. **The map cap is configurable** (`buffalo.incidents.map_cap`, default 5,000), so tests can exercise the cap without 5,001 rows.
5. **Record tabs leave out drafts and invalid reports** (statistics rule); they are record histories.
6. **Filter visibility:** the reporting-officer filter is offered only to holders of `users.view` (the users picker needs it), and the criminal-group filter only to holders of `criminal_groups.view`. The server applies whatever is in the URL either way, through the viewer's scopes.
7. **National dashboard lists** carry a true count alongside at most 20 (escalations) or 15 (critical) rows. The first version showed the row count in the header, which was misleading at volume.
8. **Casualty outcome charts** show killed, injured, abducted and rescued, as the prompt lists. Missing and arrested are in the list filter, not the charts.

## New packages

| Package | Version | Licence | Why |
|---|---|---|---|
| `chart.js` | 4.5.1 | MIT | Dashboard bar charts (replaces ApexCharts; see deviation 1). Brings `@kurkle/color` (MIT). |
| `leaflet.markercluster` | 1.5.3 | MIT | Marker clustering (approved in CLAUDE.md). |
| `@types/leaflet.markercluster` | 1.5.6 | MIT | TypeScript types (dev). |

All are bundled by Vite; nothing is fetched at runtime.

## Bugs fixed during this step

- **Demo ULIDs:** the seeder stored uppercase ULIDs while the application stores lowercase. Fixed in the seeder, and the 50,000 demo rows on dev were lowercased.
- **Misleading national headers:** "escalated, awaiting acknowledgement" showed the capped row count; it now shows the true count (deviation 7).

## Known issues and notes for Phase 6

- **The list's total count** is the slowest query: 49 ms at 16,000 matching rows. It grows with volume; consider an estimated count or a cap on very large states later.
- **Map payloads:** 1.2 MB at the 5,000 cap. The heatmaps and flashpoint layers of Phase 6 should aggregate on the server rather than send points.
- **Demo data on dev:** 50,000 demo incidents remain (some points fall in water through synthetic jitter; demo reporting officers are random across states). Remove them with `buffalo:seed-demo-incidents --purge`.
- **Dashboard maps** show up to 500 recent points; Phase 6 map layers will replace this.
- **Saved filters** hold ULIDs: a view naming a community, officer or group that a recipient cannot see simply matches nothing for them.
- **Live refresh** reloads the whole `incidents` prop (debounced); finer-grained updates can come with Phase 7 analytics.
