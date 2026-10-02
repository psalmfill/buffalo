# BUFFALO — Phase 7B: Global Search

Read `CLAUDE.md` before starting. Read `docs/handover/phase-7a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Each module has its own search. Officers need one search box that finds anything they are allowed to see: a reference number, a name, a place, an organization. This step builds global search on PostgreSQL (no external engine, per CLAUDE.md).

**Rules:**
- Every result comes through its module's own scopes (jurisdiction, classification, grants, disseminations). Global search never runs in system context.
- **Global search never produces cross-jurisdiction person hits.** Hit/no-hit stays in the persons search (`/persons/search`), which global search links to.
- Searching is not viewing: listing a result does not write `record_access_logs`; opening the record does (as today).

---

## Step 7B.1 — Search service

`app/Services/Search/GlobalSearch.php` with one **provider** per module, each returning at most N results (default 5 in the quick view, 25 per page in the full view) with: type, ULID, title, subtitle (e.g. "Kidnapping · Ikeja LGA · 3 days ago"), classification, and a relevance score normalised to 0–1.

| Provider | Matches on | Permission |
|---|---|---|
| Incidents | reference number (exact, `BUF/…`), `search_vector` (`websearch_to_tsquery`) | `incidents.view` |
| Threat reports | reference (`THR/…`), title/description (add a generated `search_vector` like incidents) | `threats.view` |
| Flashpoints | `search_name` trigram, description full text (add `search_vector`) | `flashpoints.view` |
| Persons | name and aliases (trigram, index-backed `%`); exact NIN or phone via blind index | `persons.view` |
| Persons of interest | as persons, restricted to active POIs; shows risk level | `poi.view` |
| Criminal groups | name and other names | `criminal_groups.view` |
| Communities | names (existing `search_names`) | `communities.view` |
| Organizations | directory names; site names | `organizations.view` |
| Agency formations | name | `formations.view` |
| LGAs, senatorial districts | name, code | `lgas.view` |

- **Query detection:** `BUF/…` or `THR/…` → exact reference lookup only; 11 digits → NIN; phone-shaped → phone (persons and organization contacts, exact); otherwise text across all providers.
- Providers run as separate queries (not one giant `UNION`), each with a time budget (e.g. 300 ms, `SET LOCAL statement_timeout`); a provider that times out is reported as "more results may exist" rather than failing the whole search.
- Use index-backed operators only (CLAUDE.md). Add any missing GIN indexes and report `EXPLAIN` results for each provider at the dev data volume.
- Ranking across providers: exact matches first (reference, NIN, phone), then by normalised score, then recency. Each type is also shown in its own section.

## Step 7B.2 — UI

- **Quick search** in the app header (shortcut `/` or Ctrl/Cmd+K): a command-palette dialog, debounced, showing up to 5 results per type with icons, classification badges and subtitles. Enter opens the top result; arrow keys navigate.
- **Full results page** `/search?q=…&type=…`: tabs per type with counts (counts are the viewer's own scoped counts), pagination per tab, and a link "Check other states' person records" to `/persons/search` with the query pre-filled.
- Recent searches: kept per user in the browser only (localStorage, cleared on sign-out together with the existing `clearHistory()` work if present); never stored on the server.
- An empty or one-character query shows nothing.

## Step 7B.3 — Audit

Log each search request (not the results) in a lightweight `search_logs` table: `user_id`, `query_hash` (SHA-256 of the normalised query; the plain query is **not** stored, because it may contain names or NINs), `query_type` (`reference`, `nin`, `phone`, `text`), `result_count`, `created_at`. Immutable via trigger. Purpose: detecting unusual search volumes per user, alongside the Phase 3 hit limits.

Rate limit: 60 searches per minute per user (Laravel rate limiter), returning a friendly message.

## Step 7B.4 — Tests

- Each provider: only in-scope results, classification ceiling, grants (persons) and disseminations (threats, flashpoints) respected; super admin gets nothing.
- No cross-jurisdiction person hits from global search, and no `record_match_events` written by it.
- Query detection for references, NIN and phone; exact matches ranked first.
- Provider timeout handled without failing the search.
- `search_logs` stores no plain query; immutable; rate limit enforced.
- Listing results writes no `record_access_logs`.
- Counts per tab equal the viewer's own scoped counts.

## Acceptance checklist

- [ ] Typing a threat reference in the header search opens that threat directly.
- [ ] Searching a common name returns persons, communities and organizations in separate sections, each limited to the user's jurisdiction.
- [ ] A Deputy State Director never sees a `secret` incident in results.
- [ ] Global search with 50,000 incidents returns in under 1 second; per-provider timings are in the handover.
- [ ] `make test` and `make lint` pass.

## Handover

Write `docs/handover/phase-7b.md` per CLAUDE.md section 9 and print it.
