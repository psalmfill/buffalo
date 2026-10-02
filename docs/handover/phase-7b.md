# Phase 7B handover — Global search

Date: 2026-10-02

## What was built

### 7B.1 Search service (`app/Services/Search`)

- **`GlobalSearch`** runs one **provider** per module, in section order:
  1. incidents;
  2. threat reports;
  3. flashpoints;
  4. persons;
  5. persons of interest;
  6. criminal groups;
  7. communities;
  8. organizations (directory, sites, contacts);
  9. agency formations;
  10. LGAs and senatorial districts.

  Providers are registered under the container tag `search.providers` (`AppServiceProvider`).
- **Results:**
  - `quick()`: up to 5 per type, plus the overall best results;
  - `full()`: the viewer's own scoped count per type and one page (25) of the chosen type.

  Each hit has a type, ULID, title, subtitle, link, classification, a score normalised to 0–1, and an `exact` flag.
- **`SearchQuery`** detects the kind of entry:

  | Entry | Type |
  |---|---|
  | `BUF/…` or `THR/…` | `reference`: exact lookup only, incidents or threats |
  | 11 digits | `nin` (also a phone if it starts with 0, as in the persons search) |
  | phone-shaped | `phone`: persons and organization contacts |
  | anything else, 2+ characters | `text` |

  A query of one character or less shows nothing.
- **Ranking:** exact matches (reference, NIN, phone) first, then score, then recency. Each type also has its own section.
- **`SearchBudget`** runs each provider call in its own transaction:
  - `statement_timeout` (300 ms) and the trigram thresholds are set with `set_config(…, true)`;
  - a cancelled query (SQLSTATE 57014) gives "more results may exist", not an error;
  - the previous timeout is restored, so an enclosing transaction (tests) keeps its own.
- **Access:**
  - Providers start from each module's own scoped Eloquent query (jurisdiction, classification, person grants, disseminations), never the system context.
  - The `globalSearch` gate admits intelligence roles only, so super admin gets 403.
  - Each provider also checks its module's permission.

| Provider | Matches on (index) | Permission | Score |
|---|---|---|---|
| Incidents | `lower(reference_no)` exact (`incidents_reference_no_ci`); `search_vector @@ websearch_to_tsquery` (GIN) | `incidents.view` | `ts_rank_cd(…, 32)`; exact 1 |
| Threat reports | `reference_no` exact (unique); new `search_vector` (title A, description B; GIN) | `threats.view` | `ts_rank_cd(…, 32)` |
| Flashpoints | `search_name` `<%` (trigram GIN) or new `search_vector` (name, description; GIN) | `flashpoints.view` | best of the two |
| Persons | `PersonSearch`: name and aliases `%` / `<%` (trigram GIN); NIN/phone by blind index | `persons.view` | name score; exact 1 |
| Persons of interest | as persons, among visible persons with a visible POI not deceased or cleared; shows risk | `poi.view` | as persons |
| Criminal groups | `search_name` (name and other names) `%` / `<%` | `criminal_groups.view` | similarity |
| Communities | `search_names` `<%` | `communities.view` | word similarity |
| Organizations | directory `search_name` `<%`; new site `search_name` `<%` (scoped, classified); phone: contacts by blind index (scoped, each checked with `view`) | `organizations.view` | word similarity; contact exact 1 |
| Agency formations | `search_name` `<%` | `formations.view` | word similarity |
| LGAs and senatorial districts | new `search_name` `<%`, or code exactly (new `upper(code)` indexes), within `JurisdictionOptions` | `lgas.view` | word similarity; code 1 |

### 7B.2 UI

**Header quick search** (`components/search/global-search.tsx`):
- a command palette opened by the header button, `/` (outside text fields) or Ctrl/Cmd+K;
- 250 ms debounce, with an aborted request on each keystroke;
- "Best match" first, then up to 5 per type with icons, subtitles, risk and classification badges ("Exact match" marked);
- arrow keys move, Enter opens the highlighted result (by default the best), Esc closes; "See all results" opens the full page;
- shown to intelligence roles only (`maxClassification`), and checked on the server too.

**Results page** `/search?q=…&type=…`:
- tabs with counts, 25 per page;
- "more results may exist" when a count timed out;
- "Showing the best 1,000 results. Refine your search" beyond the cap.

**"Check other states' person records"** POSTs the query to `/persons/search`, which is a POST so names stay out of URLs. It is shown when the user may request cross-jurisdiction access.

**Recent searches:**
- kept in localStorage per user (`buffalo.search.recent.{ulid}`, 8 at most), never sent to the server;
- cleared on sign-out in the user menu's logout handler. There was no `clearHistory()` to hook into.

### 7B.3 Audit

- **`search_logs`:** `user_id`, `query_hash` (SHA-256 of the lowercased, whitespace-collapsed query; CHECK hex-64), `query_type` (CHECK), `result_count`, `created_at`. It is append-only through the existing `buffalo_reject_audit_change()` trigger, including truncate.
  - `result_count`: the number of hits listed for quick searches; the sum of scoped counts for the full page.
- **Rate limit:** `throttle:global-search` allows 60 per user per minute.
  - JSON gets a 429 with "Too many searches. Wait a minute and try again."
  - Pages get a toast with the same message.

### Database (`2026_10_14_000001_add_global_search.php`)

- `threat_reports.search_vector` and `flashpoints.search_vector`: generated, with GIN indexes.
- `organization_sites.search_name`, `lgas.search_name` and `senatorial_districts.search_name`: generated `buffalo_search_text(name)`, with trigram GIN indexes.
- `lgas_code_upper` and `senatorial_districts_code_upper` indexes.
- The `search_logs` table and its triggers.
- The new columns are hidden on their models and excluded from the audit log.

## Acceptance checklist (browser, dev)

- [x] **Reference opens the threat.** As the Lagos SD: pressed `/`, typed `thr/la/2026/00004`. The palette showed "Best match — Planned kidnapping on the Badagry expressway — Exact match", and Enter opened `/threats/01m3wsh6y7vakfahr025wk93sq`.
- [x] **Common name, separate sections, own jurisdiction.** The dev data has no name shared across modules, so I added acceptance records named "Adewale": a Kano person, a community in each state, and an organization with a Lagos site and a Kano site.
  - The Lagos SD's "Adewale" search showed separate sections: Persons (Adewale Balogun, Lagos), Communities (Adewale Estate, Lagos), and Organizations (the national directory entry plus the **Lagos** site only).
  - The full page shows tabs Persons 1, Communities 1, Organizations 2, and the persons-search link.
  - The Kano SD sees only the Kano person, community and site.
- [x] **Deputy and secret incidents.** For a secret Lagos incident the DSD did not create, the DSD's search by title lists nothing, while the SD's lists it.
  - For "synthetic incident" (every demo incident), the DSD's count is 24,195 against the SD's 24,832, and no secret record from anyone else is on the page.
  - The DSD can see secret incidents they created themselves (the creator rule, CLAUDE.md 4a), as everywhere else.
- [x] **Under one second with 50,000 incidents.** Timings are below.
- [x] **Checks:** `make test`, `make lint` and `make ci` pass: 1346 Pest tests (9671 assertions) plus Vitest.

## Performance (dev: 50,047 incidents)

**HTTP response times** (curl, including framework and session; warm):

| Request | DG | SD Lagos | DSD Lagos |
|---|---|---|---|
| Quick, "synthetic incident" (all 50,040 incidents match) | 0.35 s | 0.18 s | 0.19 s |
| Full page, "synthetic incident" (counts plus page 1) | 0.25 s | 0.24 s | 0.25 s |
| Quick, "kidnapping" | 0.15–0.19 s | 0.15 s | 0.14 s |
| Full page, "kidnapping" | 0.22 s | 0.18 s | 0.15 s |
| Quick, "Olodi" | 0.17–0.19 s | 0.15–0.18 s | 0.18 s |
| Quick, `THR/LA/2026/00004` (one provider) | 0.27 s | | |

**Per-provider times** (DG, "kidnapping market", inside the budget; `hits` / `count`):

| Provider | hits | count |
|---|---|---|
| incidents | 20 ms | 3 ms |
| threats | 3 ms | 0.4 ms |
| flashpoints | 39 ms (first call) | 0.8 ms |
| persons | 13 ms | 0.7 ms |
| pois | 4 ms | 1.1 ms |
| groups | 1.6 ms | 0.4 ms |
| communities | 1.7 ms | 0.4 ms |
| organizations | 5 ms | 0.7 ms |
| formations | 2.7 ms | 0.4 ms |
| places | 2.7 ms | 0.7 ms |

The incident count over 50,040 matches takes 25–56 ms; the incident hits for "synthetic incident" take 63–144 ms.

**`EXPLAIN (ANALYZE)` at the dev volume:**
- **Incidents** use `Bitmap Index Scan on incidents_search_vector_gin` (`search_vector @@ 'kidnap' & 'market'`), 0.05–0.27 ms.
- **The other tables are tiny on dev** (11 persons, 5 threat reports, a handful of flashpoints, sites and groups), so the planner seq-scans them. With `enable_seqscan = off` for the transaction, every provider query uses its index:

| Provider | Index |
|---|---|
| threats | `threat_reports_search_vector_gin` |
| flashpoints | `flashpoints_search_name_trgm` (`search_name %> '…'`) and `flashpoints_search_vector_gin` |
| persons | `persons_search_name_trgm` for both `%` and `%>`, as a BitmapOr (aliases the same) |
| groups | `criminal_groups_search_name_trgm` |
| communities | `communities_search_names_trgm` |
| organizations | `organizations_search_name_trgm`, `organization_sites_search_name_trgm` |
| formations | `agency_formations_search_name_trgm` |
| LGAs | `lgas_search_name_trgm` plus `lgas_code_upper` (BitmapOr) |
| districts | `senatorial_districts_search_name_trgm` |
| references | `incidents_reference_no_ci`, `threat_reports_reference_no_unique` |

## Files

### Created

- **Search service:**
  - `app/Services/Search/{GlobalSearch, SearchQuery, SearchHit, SearchProvider, SearchBudget}.php`
  - `app/Services/Search/Providers/{BaseProvider, IncidentProvider, ThreatReportProvider, FlashpointProvider, PersonProvider, PersonOfInterestProvider, CriminalGroupProvider, CommunityProvider, OrganizationProvider, FormationProvider, PlaceProvider}.php`
- **HTTP:** `app/Http/Controllers/Search/SearchController.php`, `routes/search.php`
- **Database:** `database/migrations/2026_10_14_000001_add_global_search.php`
- **Frontend:**
  - `resources/js/components/search/{global-search, search-hit-row, search-icons}.tsx`
  - `resources/js/pages/search/index.tsx`
  - `resources/js/lib/recent-searches.ts`, `resources/js/types/search.ts`
- **Tests:** `tests/Feature/Search/GlobalSearchTest.php` (14 tests)
- **Docs:** `docs/prompts/phase-7b.md`

### Changed

- **Back end:**
  - `AppServiceProvider` (providers tag, `globalSearch` gate, `global-search` rate limiter)
  - `config/buffalo.php` (`search`)
  - `routes/web.php`
  - `PoiStatus::active()`
  - `ThreatReport`, `Flashpoint`, `OrganizationSite`, `Lga`, `SenatorialDistrict` (hidden, unaudited search columns)
- **Frontend:**
  - `app-sidebar-header.tsx` (search box)
  - `user-menu-content.tsx` (clears recent searches on sign-out)
- **Docs:** `README.md`

## Deviations and decisions

1. **Who may search:** intelligence roles only, through the `globalSearch` gate (`ClassificationGate::maxLevelFor() !== null`).
   - The prompt adds no permission. Super admin holds some reference permissions (for example `lgas.view`) but "gets nothing", as asked.
   - Searching itself needs no new permission. Each provider needs its module's permission.
2. **Incident ranking window.** Ranking a broad term over tens of thousands of matches overran the 300 ms budget: "synthetic incident" returned no hits, only "more results may exist". So:
   - incident hits are ranked among the **1,000 most recent matches the viewer can see** (a scoped subquery);
   - the results page lists at most `max_results` (1,000) per type and asks the user to refine beyond that;
   - counts stay exact.
3. **Persons of interest are shown on the person page** (`/persons/{ulid}?tab=poi`); there is no separate POI page. "Active" means every status except deceased and cleared (`PoiStatus::active()`). The POI record and the person must both be visible.
4. **Organizations:**
   - The directory is national (as built in 4C), so directory hits are not jurisdiction-limited.
   - Sites and contacts are scoped. A contact phone match names only the organization and site, never the contact, and links to the organization.
   - Phone numbers are never put in URLs.
5. **"Check other states' person records"** is a POST, because `/persons/search` is POST (it keeps names out of URLs). Global search itself never calls `PersonMatchService` and never writes `record_match_events` (tested).
6. **`search_logs.result_count`** is the number of hits listed for quick searches and the sum of scoped counts for the results page.
7. **Pagination within multi-source tabs** (organizations, places) merges and re-ranks the sources page by page. This is fine for directory-sized results.
8. **Existing module searches** (communities, criminal groups) still filter with `word_similarity() >=` on their own pages. Global search uses only the index operators (`%`, `<%`), per the decisions log. Moving the module pages over is left for a later clean-up and noted here.

## New packages

None. The command palette is built from the existing dialog component; there is no `cmdk`.

## Known issues and notes

- **Demo references:** demo incidents use `DEMO/…` references rather than `BUF/…`, so they are not reference lookups (their titles still match by full text). Real references are `BUF/…`.
- **Dev data volume:** apart from incidents, dev tables are tiny, so the planner prefers seq scans there. The index plans above were checked with seq scans disabled.
- **Automated typing in the palette:** the very first keystrokes after "/" can arrive before the input has focus. This only affected automated typing in acceptance; a person typing is not affected.
- **Dev data added for acceptance:**
  - person "Adewale Usman" (Kano);
  - communities "Adewale Estate" (Lagos) and "Adewale Quarters" (Kano);
  - organization "Adewale Haulage Ltd" with sites in Lagos and Kano.
- **For later phases:** analytics (7C) and exports (7D) can reuse `SearchBudget` for user-facing queries that need a time limit.
