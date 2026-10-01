# BUFFALO — Phase 6C: Operational Map, Aggregated Layers and Blackspot Suggestions

Read `CLAUDE.md` before starting (see the decisions log entry on aggregated map layers). Read `docs/handover/phase-6a.md` and `docs/handover/phase-6b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

BUFFALO now has location data across many modules: incidents, flashpoints, threat reports, formations, organization sites, communities, and (since 6A) LGA boundaries. Each module has its own map. This step brings them together in one **operational map** with a layer control, adds **server-side aggregated layers** (incident density and LGA choropleths), suggests new blackspots from incident clustering, and replaces the dashboards' raw point maps with aggregated ones. It completes Phase 6.

**Principles:**
- Every layer is computed through the viewer's own scopes and the statistics rule (CLAUDE.md).
- Aggregation happens in PostGIS; the browser receives small payloads.
- No new map packages. Density is drawn as a hexagon grid of GeoJSON polygons (no heatmap plugin needed).

---

## Step 6C.1 — Aggregation endpoints

Under `/map/layers/...`, each authorised by the relevant module's `view` permission, returning GeoJSON:

| Layer | Computation | Payload |
|---|---|---|
| **Incident density** | `ST_HexagonGrid` over the requested bounding box, hexagon size chosen from the zoom level (about 5 sizes from ~50 km at national zoom to ~500 m at street zoom); count of visible, non-draft, non-invalid incidents per hexagon in the filtered period; empty hexagons omitted | polygon + count per hexagon |
| **LGA choropleth** | count (or rate per 100,000 population, when `population_estimate` exists, selectable) of visible incidents per LGA, using simplified boundaries for the zoom level (6A.0) | polygon + LGA name + value |
| **State choropleth** | as above, per state (national users) | polygon + state name + value |
| **Incidents** | existing clustered points (5D), unchanged | |
| **Flashpoints** | points with radius and concern level | |
| **Threat reports** | active only by default; points with radius and urgency; LGA-level threats without a location drawn as the LGA outline | |
| **Formations**, **organization sites** (high and critical relevance by default), **communities** | existing point layers, clustered | |

Requirements:
- Shared filters for incident-based layers: date range (default last 90 days), incident type, category, severity, status.
- Boundaries not imported: choropleth layers are unavailable with a notice ("LGA boundaries have not been imported"); with centroids only, show graduated circles at centroids instead.
- **Caching:** cache aggregated results in Valkey for 5 minutes, keyed by layer, filters, bounding box tile, zoom bucket, and a **scope signature** (role, clearance, jurisdiction IDs, zones). Never share cached results between different scope signatures. Invalidate incident layers on incident submission and status change events for the affected state.
- Bounding boxes are snapped to a fixed tile grid per zoom bucket so the cache is effective.
- Responses capped in size; report typical payload sizes in the handover (target: under 200 KB per layer request).

## Step 6C.2 — Operational map page

`/map` (navigation: "Operational map" under Overview; visible to any intelligence role).

- Full-screen `BuffaloMap` with a layer panel: toggle each layer the user is permitted to see; per-layer legend; the incident filter panel shared by incident layers; the boundary attribution from `boundary_sources`.
- Default view: the user's jurisdiction (LGA or state centroid and boundary; national otherwise), with the density, flashpoints and active threats layers on.
- Clicking a hexagon or LGA shows its count and a link to the incident list pre-filtered to that area and period (hexagon: bounding-box filter; LGA: LGA filter). Add a bounding-box filter to the incident list for this.
- Clicking a flashpoint, threat, formation or site shows its existing popup with a link.
- Layer and filter state is kept in the URL so map views can be shared (each viewer still sees only their own scopes).
- Time slider: step through the selected period by week, redrawing the density layer, to show how hotspots move.

## Step 6C.3 — Blackspot suggestions

A nightly job (and an on-demand "Refresh" for SD and above) runs **in system context** per state:

- `ST_ClusterDBSCAN` over non-draft, non-invalid incidents in the last 180 days, `eps` 1,000 m and `minpoints` 5 (both in `config/buffalo.php`), optionally per incident category.
- For each cluster not already covered by an active or monitoring flashpoint's area, store a suggestion in `blackspot_suggestions`: `ulid`, `state_id`, `lga_id` (of the cluster's centroid; composite FK), `centroid`, `radius_meters` (distance to the farthest member, minimum 500), `incident_count`, `dominant_incident_type_id`, `first_occurred_at`, `last_occurred_at`, `status` (`open`, `accepted`, `dismissed`), `decided_by`, `decided_at`, `dismiss_reason`, `generated_at`.
- Suggestions are scoped like other records. The incident count shown to a viewer is recomputed through their own scopes, so it never reveals incidents they cannot see. Members are stored as incident IDs in a child table, and only visible ones are listed.
- Dismissed suggestions are not regenerated for the same area (centroid within 500 m) for 90 days.

UI: a "Suggested blackspots" list for SD, DSD and national users, also shown as a layer on the operational map. **Accept** opens the flashpoint form pre-filled (kind `blackspot`, location, radius, threat type from the dominant incident type's category) and links the member incidents; **Dismiss** requires a reason. The State Director is notified when new suggestions appear (daily, counts only).

## Step 6C.4 — Dashboard maps

Replace the raw point maps on dashboards (the 500-point maps from 5D) with aggregated layers:
- LGA chief: density layer for their LGA, last 30 days, plus flashpoints and active threats.
- State roles: LGA choropleth for the state (or density if no boundaries), last 30 days, plus flashpoints and active `immediate`/`priority` threats.
- National: state choropleth (or density), last 30 days, plus active `immediate` threats.

Each dashboard map links to the operational map with the same view.

## Step 6C.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `map.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `blackspots.suggestions` (view, accept, dismiss) | ✓ | ✓ | ✓ | ✓ | |
| `blackspots.refresh` | ✓ | ✓ | ✓ | | |

Each layer also requires its own module's view permission. Super admin: none. Update the matrix, drift tests and `auth.ts`.

## Step 6C.6 — Tests

- Every aggregation endpoint: counts exactly match the viewer's scoped incident queries for a fixture set (per role, including clearance); drafts and invalid excluded; filters applied.
- Cache: different scope signatures never share entries; invalidation on incident events; tile snapping.
- Choropleth fallbacks without boundaries and with centroids only.
- Hexagon size per zoom bucket; empty hexagons omitted.
- Map click-through: the bounding-box filter on the incident list returns the same incidents as the hexagon count.
- Blackspot job: clusters found with the configured parameters; areas already covered by flashpoints skipped; dismissals respected for 90 days; viewer-scoped counts; accept pre-fills and links.
- `EXPLAIN` for the density and choropleth queries at 50,000 incidents, and response times, reported in the handover (target: under 1 second uncached).

## Phase 6 completion checklist

- [ ] With demo boundaries and 50,000 demo incidents, the operational map's density layer redraws within a second when panning and zooming, and the time slider shows hotspots moving week by week.
- [ ] A State Director clicks an LGA on the choropleth and lands on the incident list filtered to it.
- [ ] The nightly job suggests a blackspot from a cluster of demo incidents; accepting it creates a blackspot with the incidents linked.
- [ ] A Deputy State Director's density counts exclude `secret` incidents they cannot see.
- [ ] Dashboards show aggregated maps; no dashboard request returns raw incident points beyond the clustered incident layer.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-6c.md` per CLAUDE.md section 9, and `docs/handover/phase-6-summary.md` summarising all of Phase 6: tables, layers and their caching, permissions, performance, and open issues for Phase 7. Print both.
