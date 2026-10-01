# Phase 6C handover — Operational map, aggregated layers and blackspot suggestions

Date: 2026-10-01

## What was built

### 6C.1 Aggregation endpoints (`/map/layers/...`, GeoJSON)

Each endpoint needs `map.view` plus the module's own `viewAny`, and computes through the viewer's scopes. Parameters: `bbox=west,south,east,north` (clamped around Nigeria) and `zoom`.

| Layer | Computation | Typical payload (dev) |
|---|---|---|
| `density` | Hexagon counts of the viewer's counted incidents, filtered. Cached per tile. | 1.5 KB (national, zoom 6) to 126 KB (street, zoom 14) |
| `lga-choropleth` | Counts, or rates per 100,000 (`measure=rate`), per LGA in the viewer's area, on the simplified boundaries for the zoom (6A.0). | 6 KB (24 demo LGAs) |
| `state-choropleth` | The same per state; national users only. | 0.7 KB |
| `flashpoints`, `threats`, `formations`, `sites`, `communities`, `suggestions` | Points inside the box (`max_features` 4,000). Threats: active only by default, optional `urgencies[]`. Threat reports without a point are drawn at their community, or as their LGA's outline (or centroid). Sites: high and critical-infrastructure relevance by default (`relevance=all` for every site). | about 1 KB each on dev |

The incident points layer reuses the existing clustered endpoint (`/incidents/map/data`, 5D).

**Hexagons.** The grid is PostGIS's own (`ST_HexagonGrid` / `ST_Hexagon`): flat-topped, side `hex_meters` in Web Mercator.

| Zoom | ≤6 | ≤8 | ≤10 | ≤12 | ≥13 |
|---|---|---|---|---|---|
| Hexagon side | 50 km | 20 km | 5 km | 2 km | 500 m |
| Cache tile | 8° | 4° | 1° | 0.5° | 0.1° |

- **Cell assignment.** Each incident's cell is computed arithmetically (`HexGrid`) as the hexagon with the nearest centre, among three candidates. This is the same tiling as `ST_HexagonGrid` but much faster than intersecting points with a generated grid. A point on an edge falls in exactly one hexagon.
- **Tiles.** Each hexagon belongs to the tile containing its centre (half-open bounds), so it is counted once. Empty hexagons are omitted.
- **Shared filters.** Dates (default last 90 days), types, category, severities and statuses.
- **Statistics rule.** Statuses default to every counted status. Drafts and invalid reports cannot be requested: those values are dropped.

**Fallbacks without boundaries.** The response `meta.mode` is `polygons`, `centroids` (graduated circles) or `unavailable` (with the notice "LGA boundaries have not been imported"). The map page disables the layer when the user's area has neither boundaries nor centroids.

**Caching** (`LayerCache`, Valkey, 5 minutes):
- The key covers the layer, a **scope signature**, the filters, the snapped tile or area, the zoom bucket and a data version.
- The scope signature is the role, jurisdiction level, state, LGA, DDG zones and clearance. **Below `secret` clearance it also includes the user**, because those users see classified incidents they created themselves (CLAUDE.md 4a). Two deputies of one state may therefore see different counts, and their caches are never shared.
- Versions:
  - `InvalidateIncidentMapLayers` bumps the state's version and the national one on `IncidentSubmitted` and `IncidentStatusChanged`.
  - A boundary import bumps a global version.
  - National users follow the national version; others their state's.
- Requests over 16 tiles return a "Zoom in" notice; the point is to keep cache entries reusable. Choropleth areas are snapped to the same grid.
- Point layers are not cached: they are small, and indexed by the GIST indexes.

### 6C.2 Operational map (`/map`)

- **Layout:** full height; a layer panel with per-layer options (choropleth value, threat urgencies), a legend, the shared incident filters, and the boundary attribution.
- **Defaults:** the user's jurisdiction (centroid and outline), with density, flashpoints and active threats on.
- **Popups:** hexagons and LGAs or states show the count with "List them", which opens the incident list filtered:
  - hexagon: the exact `hex` filter;
  - LGA: `lga`;
  - state: `state`;
  - in each case the period and the counted statuses.
- Flashpoints, threats, formations, sites, communities and suggestions show their popup with a link.
- **URL state:** layers, filters, measure, urgencies, week, centre and zoom are kept in the URL with `history.replaceState`.
- **Time slider:** "Step through the period by week" with a Play button (one week every 1.5 s). It redraws the density layer per week; each week is a cacheable request.
- **Incident list:** gained `bbox` and `hex` filters, shown as a "Map area" check that can be cleared.

### 6C.3 Blackspot suggestions

**Tables:**
- `blackspot_suggestions`: scoped by the centre's place (composite FK); centroid, radius, cluster size, dominant type, category, first and last occurrence, status, decision fields, `flashpoint_id`, `generated_at`.
- `blackspot_suggestion_incidents`: the member ids.

**Clustering** (`BlackspotClustering`, a sanctioned scope bypass in `App\Support\Jurisdiction`; ids and positions only):
- `ST_ClusterDBSCAN` over each state's counted incidents of the last 180 days, optionally per category (`per_category`).
- `eps` is in ground metres: Web Mercator coordinates with `eps` scaled by the state's latitude.

**Generation** (`GenerateBlackspotSuggestions`, nightly at 02:00, and "Refresh now" for SD and above):
- skips clusters covered by an active or monitoring flashpoint, and areas dismissed within 500 m in the last 90 days;
- updates an open suggestion in place within 500 m (same ULID);
- deletes open suggestions that are no longer backed by a cluster (derived data);
- keeps accepted and dismissed suggestions.

**Notifications:** each State Director is told the number of new suggestions (counts only).

**Visibility:**
- The list and the map layer recompute each suggestion's count through the viewer's own incident scopes.
- A suggestion is shown only when the viewer can see at least `min_points` of its incidents, and only visible members are listed. The stored cluster size is never sent.

**Accept:** `/flashpoints/create?suggestion=…` pre-fills kind (blackspot), LGA, point, radius and threat type. The threat type is the first active one in the dominant incident type's category. On save, in the same transaction, the visible member incidents are linked and the suggestion is marked accepted.

**Dismiss:** needs a reason.

### 6C.4 Dashboards

- `DashboardMap` now draws aggregated layers only, for the last 30 days:

  | Dashboard | Area layer | Points |
  |---|---|---|
  | LGA chief | density | flashpoints, active threats |
  | State | LGA choropleth (density without boundaries) | flashpoints, immediate and priority threats |
  | National | state choropleth (density without boundaries) | immediate threats |

  Each map links to the operational map with the same view.
- The 500-point `map7` raw incident payloads are removed from the state and national figures.
- The "Active flashpoints" placeholder card now shows the count of active and monitoring flashpoints in reach.

### 6C.5 Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `map.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `blackspots.suggestions` (view, accept, dismiss) | ✓ | ✓ | ✓ | ✓ | |
| `blackspots.refresh` | ✓ | ✓ | ✓ | | |

Each layer also needs its module's view permission; super admin has none. The matrix, `auth.ts`, the PHP drift test and the navigation test are updated. Navigation gained "Operational map" (Overview) and "Suggested blackspots" (Threats & flashpoints).

## Phase 6 completion checklist (browser, dev)

- [x] **Density at 50,000 demo incidents, with demo boundaries.** Panning and zooming redraws within a second. Measured layer response times (HTTP, as the DG):

  | Request | Uncached | Cached |
  |---|---|---|
  | National density (zoom 6, 4 tiles) | 0.82 s | 0.20 s |
  | Zoom 8 | 0.38 s | 0.10 s |
  | Zoom 10 | 0.41 s | 0.17 s |
  | Zoom 12 | 0.43 s | 0.13 s |
  | Zoom 14 | 0.28 s | 0.37 s |
  | LGA choropleth | 0.22 s | 0.14 s |
  | State choropleth | 0.18 s | 0.12 s |

  The time slider steps week by week (Play advanced the URL to `week=3`), and the hotspots change between weeks.
- [x] **State Director click-through.** The SD enabled "Incidents by LGA" and clicked Demo LGA LA-3-3 ("798 incidents"). "List them" opened the incident list filtered to that LGA, the period and the counted statuses: "798 incidents in this view".
- [x] **Suggested blackspot.** `buffalo:seed-demo-incidents --hotspot=6.34,3.10 --hotspot-count=20` was run twice (40 incidents), then `buffalo:suggest-blackspots --state=LA`. It suggested one blackspot: 56 incidents (hotspot plus background), mostly Kidnapping, radius 1.6 km.
  - The SD accepted it; the form was pre-filled (Blackspot, Kidnapping, point, radius).
  - Saving created "Badagry road kidnapping cluster" with **56 incidents linked**, and the suggestion is accepted.
- [x] **Deputy and secret incidents.** For Lagos the SD's density sum is 7,814 and the DSD's 7,611. The difference, 203, is exactly the number of secret Lagos incidents in the period that the DSD did not create.
- [x] **Dashboards.** They show aggregated maps; no dashboard response carries raw incident points (tested).
- [x] **Checks:** `make test`, `make lint` and `make ci` pass: 1311 Pest tests (9078 assertions) plus Vitest.

## EXPLAIN (dev, 50,047 incidents, SD Lagos, JIT off)

**Density, one 1° tile at zoom 10:** 290 ms, 89 hexagons from 7,814 incidents.

```
GroupAggregate (actual time=288.200..288.956 rows=89)
  ->  Sort (rows=7814)
        ->  Nested Loop (actual time=73.849..285.586 rows=7814)
              ->  Bitmap Heap Scan on incidents (rows=7814)
                    Recheck Cond: state_id = 33 AND status = ANY(counted) AND occurred_at >= '2026-07-03'
                    Filter: location IS NOT NULL AND deleted_at IS NULL AND location && <tile envelope> AND (classification ... OR created_by = 8)
                    ->  Bitmap Index Scan on incidents_state_status_occurred_index (rows=9740)
              ->  Limit -> Sort -> Values Scan (3 candidate cells per incident, 0.023 ms each)
Execution Time: 290.564 ms
```

**LGA choropleth counts:** 6 ms.

```
HashAggregate (rows=12)
  ->  Bitmap Heap Scan on incidents (rows=9714)
        ->  Bitmap Index Scan on incidents_state_status_occurred_index
Execution Time: 6.184 ms
```

National users hit the date index instead (5D), at about 0.2 s per national tile. Both queries are well under the 1-second target.

## Files

### Created

- **Support:**
  - `app/Support/Map/{ZoomBucket, Tile, ScopeSignature, LayerCache, MapFilters, HexGrid, IncidentLayers, PointLayers}.php`
  - `app/Support/Jurisdiction/BlackspotClustering.php`
- **Actions:** `app/Actions/Blackspots/{GenerateBlackspotSuggestions, AcceptBlackspotSuggestion, DismissBlackspotSuggestion}.php`
- **Model and policy:**
  - `app/Models/BlackspotSuggestion.php`
  - `app/Policies/BlackspotSuggestionPolicy.php`
- **HTTP:**
  - `app/Http/Controllers/Map/{MapController, MapLayerController, BlackspotSuggestionController}.php`
  - `app/Http/Presenters/BlackspotSuggestionRows.php`
  - `routes/map.php`
- **Listener, notification, command:**
  - `app/Listeners/InvalidateIncidentMapLayers.php`
  - `app/Notifications/BlackspotSuggestionsReady.php`
  - `app/Console/Commands/SuggestBlackspots.php`
- **Database:** `database/migrations/2026_10_12_000001_create_blackspot_suggestion_tables.php`
- **Frontend:**
  - `resources/js/pages/map/index.tsx`
  - `resources/js/pages/blackspots/index.tsx`
  - `resources/js/components/map/operational-layers.ts`
- **Tests:** `tests/Feature/Map/{MapLayersTest, BlackspotSuggestionsTest}.php`
- **Docs:** `docs/prompts/phase-6c.md`

### Changed

- **Back end:**
  - `IncidentFilters` (`bbox`, `hex`)
  - `IncidentFigures` (`map7` removed)
  - `PlaceholderCards` and the three dashboard builders
  - `FlashpointController` and `FlashpointRequest` (accepting a suggestion)
  - `BoundaryImporter` (cache bump)
  - `SeedDemoIncidents` (`--hotspot`)
  - `Permission`, `RolesAndPermissionsSeeder`
  - `config/buffalo.php` (`map`, `blackspots`)
  - `routes/console.php`, `routes/web.php`
- **Frontend:**
  - `BuffaloMap` (GeoJSON layers, view callback)
  - `dashboard-map.tsx`, `incident-figures.tsx`, `placeholder-card.tsx`, the three dashboard pages
  - `flashpoints/form.tsx` (suggestion pre-fill; number input step)
  - `threats/form.tsx` (number input step)
  - `incident-filters.tsx`
  - `navigation.ts`, `auth.ts`, `types/dashboard.ts`, `types/incidents.ts`
- **Tests:** `RolesAndPermissionsTest`, `navigation.test.ts`, `IncidentViewsTest` (`map7` now absent), `DashboardTest` (flashpoint card count)
- **Docs:** `README.md`

## Deviations and decisions

1. **Hexagon list filter.** The prompt asks for a bounding-box filter on the incident list for hexagon click-through. A hexagon's bounding box also contains parts of its neighbours, so its count could never equal a box list. Hexagon links therefore use an exact `hex` filter (`size:i:j`), with the same cell arithmetic as the density layer; the test asserts equality for every hexagon. The `bbox` filter exists as well, for general use.
2. **Cell assignment** is arithmetic (nearest of three candidate centres) on PostGIS's hexagon grid, not `ST_Intersects` with `ST_HexagonGrid` output. It produces the same tiling, is 5–10× faster, and puts edge points in exactly one cell.
3. **JIT is switched off** (`SET LOCAL jit = off`) for the aggregation queries. Their cost estimates triggered JIT compilation of about 0.9 s, more than the queries themselves.
4. **The scope signature includes the user below `secret` clearance**, because of the creator rule; the prompt's signature (role, clearance, jurisdiction, zones) would otherwise share results between deputies who see different classified incidents.
5. **Cache invalidation** covers submission and status changes, as asked, plus boundary imports. Other incident edits (location or LGA corrections) appear within the 5-minute TTL.
6. **Blackspot clustering goes beyond plain DBSCAN.** With the configured `eps` (1,000 m) and `minpoints` (5), the 50,000 uniformly spread demo incidents formed one 46 km cluster over all of Lagos, and smaller `eps` produced hundreds of chance clusters. Two additions (both configurable) make the job useful:
   - **Significance:** a cluster is kept only if it is denser than chance. This is a Poisson test against the state's background density, Bonferroni-corrected for the number of incidents (`significance` 0.05).
   - **Refinement:** clusters wider than `max_radius_meters` (5 km), or not significant as a whole, are re-clustered with half the `eps`, up to 3 times. A dense core inside diffuse activity therefore still stands out.

   On real data, where incidents are not uniform, these filters mostly pass genuine hotspots unchanged.
7. **Open suggestions are regenerated each run**: updated in place within 500 m, otherwise replaced. Stale open ones are deleted as derived data; accepted and dismissed ones are records and are kept. Suggestion radii are rounded up to whole hundreds of metres.
8. **Accepting reuses the flashpoint form** (pre-filled), as the prompt suggests. Only the incidents the accepting officer can see are linked.
9. **The time slider is client-side.** It steps through weeks of the selected period, requesting the density layer per week.
10. **The incident points layer is not limited to the bounding box.** It is the 5D clustered endpoint, capped at 5,000 points.

## Problems found and fixed during acceptance

- **Wrong counts from map links.** Map links to the incident list omitted statuses, so the list fell back to its own default (open work, no closed incidents) and showed fewer incidents. Links now pass the counted statuses explicitly.
- **Silent form failure.** The radius number boxes (flashpoint and threat forms) had `step=100`, so the browser silently refused a suggested radius of 1,516 m. The boxes now accept any value (the slider keeps 100 m steps), and suggestion radii are rounded.

## New packages

None.

## Known issues and notes

- **Real boundaries** (774 LGAs) will make national LGA choropleth payloads larger than on dev. The national simplification tolerance (0.02°) should keep them near the 200 KB target, but this was not measured with real data.
- **Demo data** is uniformly random, so density shows little structure beyond the seeded hotspot.
- **Hexagon sizes** are in Web Mercator metres, about 1–3% larger on the ground across Nigeria.
- **Dev data:**
  - the hotspot incidents ("[Demo] Hotspot incident …", 40);
  - the "Badagry road kidnapping cluster" blackspot (56 links);
  - the accepted suggestion;
  - roles re-seeded.
