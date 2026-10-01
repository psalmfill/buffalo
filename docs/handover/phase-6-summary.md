# Phase 6 summary — Flashpoints, blackspots, threats and map layers

Date: 2026-10-01

Phase 6 is complete. Detailed handovers:

| Step | Handover | Scope |
|---|---|---|
| 6A | `phase-6a.md` | Boundary import (and demo boundaries); flashpoints and blackspots, assessments, links to incidents and security notes; near-flashpoint alerts and review reminders |
| 6B | `phase-6b.md` | Threat reports (early warnings): reference numbers, credibility and urgency, expiry and reactivation, links, matching on incident submission, notifications and digest, real-time, dashboards |
| 6C | `phase-6c.md` | Operational map with server-aggregated layers (density, choropleths), caching per scope, blackspot suggestions, aggregated dashboard maps |

Final checks: `make lint`, `make test` and `make ci` pass: 1311 Pest tests (9078 assertions) plus Vitest.

## Tables added in Phase 6

All intelligence tables follow the project rules:

- `HasJurisdiction` on their own `state_id`/`lga_id`, with composite FKs;
- classification where sensitive;
- ULIDs in URLs;
- `timestamptz`;
- named activity events.

| Table | Step | Notes |
|---|---|---|
| `boundary_sources` | 6A | Each boundary import: level, source, attribution (shown on maps), synthetic flag, operator. |
| `boundary_simplified` | 6A | Display geometries per LGA or state at three tolerances (national, state, LGA zoom). Rebuilt on import. |
| `flashpoints` | 6A | Point + radius (100 m–50 km), kind, threat type, concern, status, classification, review date. The point lies inside its LGA (trigger). |
| `flashpoint_assessments` | 6A | Append-only; the only way the concern, status and review date change. |
| `flashpoint_incidents`, `flashpoint_security_notes` | 6A | Links on the flashpoint's place. Visible only when both sides are; soft-deleted on unlink. |
| `flashpoint_incident_alerts` | 6A | "Incident inside a flashpoint area" notices sent, once per pair. |
| `threat_report_counters` | 6B | `THR/<STATE>/<YEAR>/<5 digits>`, gap-free per state and year. |
| `threat_reports` | 6B | Credibility, urgency, source (never an informant's identity), optional area, expected window, expiry (max 90 days), status with a required note. |
| `threat_report_flashpoints`, `threat_report_criminal_groups`, `threat_report_incidents` | 6B | Links on the report's place, both sides visible. Roles: suspected / named in report. Relations: materialised as / related. |
| `threat_report_incident_matches` | 6B | Reports possibly realised by an incident, recorded at its submission. |
| `blackspot_suggestions`, `blackspot_suggestion_incidents` | 6C | Incident clusters not covered by a flashpoint: open, accepted or dismissed. |

## Map layers and caching (6C)

| Layer | Source | Cached |
|---|---|---|
| Incident density | Hexagon counts of the viewer's counted incidents (PostGIS grid; arithmetic cell assignment; per tile) | Yes |
| Incidents by LGA / by state | Counts or rates per 100,000 on simplified boundaries; centroids or a notice when boundaries are missing | Yes |
| Incidents (points) | 5D clustered endpoint, up to 5,000 | No |
| Flashpoints, threat reports, suggested blackspots, formations, organization sites, communities | Scoped points in the box (threats without a point are drawn as community points or LGA outlines) | No |

- **Cache:** Valkey, 5 minutes. Keyed by layer, scope signature, filters, snapped tile or area, zoom bucket and a data version.
- **Scope signature:** role, level, place, DDG zones and clearance, plus the user below `secret` clearance (creator rule).
- **Versions bumped by:** incident submission and status changes (state and national), and boundary imports (all).
- Each tile is computed at most once per signature and version.

## Permissions added in Phase 6

Key: **DG** — Director General; **DDG** — Deputy Director General; **SD** — State Director; **DSD** — Deputy State Director; **LGA** — LGA Security Chief. Super admin holds none.

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `flashpoints.view`, `flashpoints.manage`, `flashpoints.assess` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `flashpoints.delete` | ✓ | ✓ | ✓ | | |
| `threats.view`, `threats.create`, `threats.manage` | ✓ | ✓ | ✓ | ✓ | ✓ ¹ |
| `threats.reactivate`, `threats.delete` | ✓ | ✓ | ✓ | | |
| `map.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `blackspots.suggestions` | ✓ | ✓ | ✓ | ✓ | |
| `blackspots.refresh` | ✓ | ✓ | ✓ | | |

¹ LGA chiefs manage only the threat reports they created.

Boundary imports are Artisan commands for operators, not UI permissions.

## Notifications and scheduled jobs added

| Job / event | When | Recipients (who can see the record) |
|---|---|---|
| Incident inside a flashpoint area | On submission | SD, DSD, LGA chief of the flashpoint (who can see both) |
| Blackspot needs reassessment | Threshold of incidents in 30 days (at most once per 30 days) | SD, DSD |
| Flashpoint review due | Daily 07:10 | LGA chief, SD, DSD |
| Threat created (immediate / priority) | On creation | SD, DSD, LGA chief (+ DG and DDGs if immediate) |
| Threat digest (routine) | Daily 07:00 | SD, DSD |
| Threat raised to immediate or confirmed | On edit | SD, DSD, LGA chief, DG, DDGs |
| Threat materialised | On status | Creator, SD, DSD (+ national if immediate) |
| Threat expiry and 24-hour warning | Every 15 minutes | Creator, SD, DSD |
| Blackspot suggestions | Nightly 02:00 | SD (counts only) |

**Real-time:**
- Incidents keep the 5C channels.
- Threats use `states.{ulid}.threats` and `national.threats`, carrying the ULID and event name only.
- Dashboards and pages refetch through scoped routes.

## Performance (dev, 50,047 incidents, demo boundaries)

**Uncached layer responses** (HTTP, as the DG):

| Request | Time |
|---|---|
| National density | 0.82 s |
| Density at zoom 8–14 | 0.28–0.43 s |
| LGA choropleth | 0.22 s |
| State choropleth | 0.18 s |
| Point layers | about 0.12–0.14 s |

- **Cached:** 0.10–0.37 s, mostly framework and session overhead.
- **Payloads:** 0.7–126 KB, under the 200 KB target.
- **Queries:**
  - density, one 1° tile: 290 ms for a state viewer;
  - LGA choropleth counts: 6 ms;
  - flashpoint area matching: GIST index via a constant 50 km prefilter (6A);
  - threat matching: active threats only (few rows).
- **Blackspot job:** about 3–5 s for Lagos (12,000 incidents in 180 days, with refinement).

## Decisions for the CLAUDE.md log

- **Boundaries:** owner-supplied GeoJSON only (`buffalo:import-boundaries`). Synthetic demo boundaries are clearly labelled, local only. Display geometries are simplified at three tolerances.
- **Flashpoint area matching** uses a constant `MAX_RADIUS` (50 km) prefilter so that `ST_DWithin` uses the GIST index, then each row's radius.
- **Threat reports:**
  - parallel real-time channels (`…threats`);
  - matches with incidents recorded at submission;
  - time window from the earlier of the expected start and the report, until the later of the expected end and the expiry.
- **Map aggregation:**
  - hexagon cells computed arithmetically on PostGIS's grid, and the same arithmetic drives the incident list's `hex` filter;
  - JIT off for aggregation queries;
  - the cache scope signature includes the user below `secret` clearance.
- **Blackspot suggestions:**
  - DBSCAN plus a Poisson significance test (Bonferroni);
  - refinement of wide or insignificant clusters;
  - counts recomputed per viewer;
  - hidden below `min_points` visible incidents.
- **CLAUDE.md conflict, still open:** section 2 says Chart.js "via `react-chartjs-2`", but the code uses its own small wrapper (5D). One or the other should change.

## Open issues for Phase 7

- **Threats across borders:**
  - Threat reports and flashpoints are visible only in their own jurisdiction plus national.
  - Neighbouring LGAs and states never see a threat whose area reaches them.
  - Cross-border matches are recorded but invisible to both sides.
  - A deliberate **dissemination** mechanism is needed (see `phase-6b.md`).
- **Analytics and exports (Phase 7)** should:
  - reuse `MapFilters` / `IncidentFilters` and the statistics rule;
  - reuse the per-scope cache pattern (`LayerCache`) for heavier aggregates;
  - keep counts per viewer: never cache results across scope signatures.
- **Real boundaries:**
  - National LGA choropleth payloads need measuring once the real 774 boundaries are imported.
  - Records outside their new LGA boundary must be corrected after the import (report in `storage/app/private/reports`). Treat that report as classified, because it holds coordinates.
- **Incident points layer:** not limited to the map view (5,000 cap). If Phase 7 needs more, move it to tiled clustering on the server.
- **Placeholder cards:** none remain undelivered. The flashpoint card now shows a count; Phase 7 dashboards may expand it.
- **Search:** global search (Phase 7) should include flashpoints, threat reports and suggestions through their scopes.
- **Dev data:**
  - 50,047 incidents, including 50,000 demo and 40 hotspot ones (`--purge` removes them);
  - demo boundaries;
  - acceptance flashpoints, threat reports THR/LA/2026/00001–00003, one accepted suggestion.
