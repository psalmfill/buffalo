# Phase 10A handover: merging duplicate persons

Phase 10A lets a State Director (or the DG or a DDG) merge two records of
the same person within one state. LGA chiefs and Deputy State Directors
request merges; candidates are found automatically; every merge keeps a
manifest and can be undone for 30 days.

## What was built

### 10A.1 Inventory of person references (commit 58f93cc)

- `docs/architecture/person-references.md`: every reference to
  `persons.id` and the rule a merge applies to it:
  - 19 foreign keys (including the new merge tables);
  - 7 polymorphic columns that can hold a person (photos, blind indexes,
    grants, access requests, match events, access logs, activity log);
  - 8 polymorphic columns that never do;
  - the JSON and text references (notification links, activity
    properties, report parameters).
- `App\Support\Persons\Merge\PersonReferenceRegistry` is the same list in
  code. `PersonReferenceRegistryTest` reads the live schema and fails when
  a foreign key to `persons`, or any `*_type`/`*_id` pair, is not
  registered; a probe table proves the check works.

### 10A.2 Schema (commit 58f93cc)

- `persons`: `merged_into_person_id`, `merged_at`, `merged_by`. A CHECK
  allows them only on soft-deleted rows, never pointing at the row itself.
  A trigger on `person_merges` refuses a merged or deleted survivor.
- `persons_of_interest.merged_into_poi_id`: the link from a combined source
  POI to the survivor's (see Deviations).
- `person_merges`: `field_choices` ("survivor" or "source" per conflicting
  field, plus `place`, `photo` and `poi_status` choices; never values),
  `manifest`, the encrypted `survivor_prior_values`, and the executor and
  undo columns.
  - Hash-chained (`AuditChain::TABLES`), with `survivor_prior_values` and
    the undo columns left out of the hash.
  - The trigger `buffalo_person_merges_guard` refuses DELETE, TRUNCATE and
    any UPDATE except: the undo columns, set once; `survivor_prior_values`
    erased to NULL, or replaced only during an APP_KEY rotation (see
    Deviations).
  - The application role holds `SELECT, INSERT, UPDATE (undo columns)`
    only (`DatabaseRoles::UNDO_COLUMNS`).
- `person_merge_requests`: one pending request per pair (partial unique
  index on the unordered pair); reason of at least 20 characters (CHECK);
  composite LGA/state FK.
- `person_duplicate_dismissals`: lower ID first (CHECK); unique per pair.
- `incident_persons` has its own lock trigger, `buffalo_incident_persons_locked`:
  - the same as the Phase 5 lock;
  - plus one exception: while the transaction-local setting
    `buffalo.person_merge` is set (only `PersonMergeLocks` sets it), an
    UPDATE of a closed or invalid incident's row may change `person_id`,
    `notes`, `deleted_at` and `updated_at`, nothing else;
  - inserts, deletes and moves to another incident stay refused.
- `buffalo:keys:rotate-app-key --reencrypt` now also re-encrypts
  `person_merges.survivor_prior_values`.

### 10A.3 Duplicate candidates (commits 58f93cc, this step)

- `PersonDuplicateFinder` works on `Person::query()->ownJurisdiction($user)`,
  a new scope: the user's own jurisdiction plus classification, without
  grants. For the nightly count it works on one state in the system context.

  | Signal | Strength |
  |---|---|
  | Same NIN (blind index) | certain |
  | Same phone (blind index) | strong |
  | Same DOB (blind index) and name/alias similarity ≥ 0.75 | strong |
  | Name/alias similarity ≥ 0.85 in the same LGA | possible |

  Merged, deleted and dismissed records are excluded, and so are pairs in
  different states. Thresholds and the list limit (200) are in
  `config/buffalo.php` (`merges.*`).
- **Duplicate persons** (`/persons/duplicates`, new navigation item):
  - national users choose a state; State Directors and Deputies see their
    state; LGA chiefs see their LGA;
  - actions: **Request merge** (LGA chief, DSD), **Review and merge** (SD,
    DG, DDG), **Not a duplicate** (with a reason; SD and above; named
    events on both persons).
- `buffalo:persons:count-duplicates` runs nightly at 03:30 and caches
  counts per state (`DuplicateCounts`). The State Director's dashboard
  shows "Possible duplicate persons" and "Merge requests waiting".

### 10A.4–10A.6 Merge, undo (commit d222f52)

- `MergePersons` applies every rule in the inventory in one transaction.
  `PersonMergeLocks` takes, in ID order:
  - transaction-level advisory locks `pg_advisory_xact_lock(10001, id)`;
  - row locks on both persons;
  - with `lock_timeout` 10 s (`BUFFALO_MERGE_LOCK_TIMEOUT`).

  The merge then re-reads and re-authorises both persons under the locks.
  `MergeManifest` records each change while applying it. The rules:
  - **Scalar fields:** chosen per conflict, otherwise whichever record has
    a value. A NIN conflict blocks; different DOBs and a deceased record
    need confirmation. Encrypted fields are written through the model, so
    the ciphertext and blind indexes are fresh; a test shows the
    survivor's ciphertext differs from the source's and blind-index
    searches by the source's NIN and phone find the survivor.
  - **Names:** names not kept become aliases (deduplicated by
    `search_alias`).
  - **Place:** the survivor's, or the source's if chosen (same state). A
    community follows its LGA.
  - **Classification:** the higher. **Deceased:** true if either.
  - **Roles and aliases:** union. **Associations:** repointed; the link
    between the two dropped; duplicate pairs combined.
  - **POI:** moved, or combined (higher risk, chosen status, union of
    categories, texts labelled `[From merged record <ULID>]`, latest
    sighting, earliest review); named events on both POIs.
  - **Offices; community, religious and organization links:** repointed.
  - **Memberships:** the stronger status and the earliest `since`.
  - **Incident persons:** same incident and involvement combined; locked
    incidents through the exception.
  - **Photos:** all moved, the chosen one made current (`order_column`).
  - **Grants:** revoked ("record merged"), with `AccessGrantChanged` and
    `AccessGrantEnded`. **Open access requests:** cancelled, requesters
    notified. **Other pending merge requests** on the source: cancelled.
  - **The source:** soft-deleted with `merged_*`; named events
    `person_merged_into` and `person_merged_from`.
  - **Notifications:** the LGA chiefs of both LGAs and the State Director,
    with the survivor's link only (a test checks no names).
- `UndoPersonMerge` runs within 30 days, all or nothing:
  - the manifest is reversed exactly; link columns must still point where
    the merge left them, otherwise the whole undo aborts;
  - dropped rows are restored and combined rows get their prior values;
  - aliases added by the merge are removed;
  - the source is restored;
  - the survivor's fields return from `survivor_prior_values`, except
    fields edited since the merge that the executor chooses to keep;
  - grants and requests are **not** restored;
  - `survivor_prior_values` is erased.
- `buffalo:merges:erase-prior-values` runs daily and erases expired or
  undone prior values; the hash chain still verifies.

### 10A.7 UI (this step)

- **Review and merge** (`/persons/merge?a=&b=[&request=]`) shows:
  - every field side by side, conflicts highlighted with radio choices
    (the NIN row blocked);
  - DOB and deceased confirmations;
  - **Swap**, the owning LGA choice and the visibility warning ("<LGA>
    will no longer see this person … on N of their incidents");
  - the current photo choice, the POI status choice and the resulting
    classification;
  - counts of what moves, and what ends (grants and requests).

  Merging asks for a TOTP code.
- **Request merge** dialog: from the candidates list or a person's page;
  the person picker has a new `own` mode (own jurisdiction only).
- **Merge requests** (`/persons/merge-requests`): by status, with
  **Review and merge** and **Decline** (with a note).
- **The person page:**
  - **Merged records** on the profile tab, with each source's **History**
    (`/persons/merges/{merge}/history`) and **Undo**, within the window and
    with step-up; the undo dialog lists edits since the merge and says
    that revoked access is not restored;
  - **Request merge** (LGA chiefs, DSDs) and **Merge with…** (SD, DG,
    DDG).
- **A merged record's URL:** `persons.show` uses `->missing()` with
  `MergedPersonRedirect`. It follows the chain to the final survivor and
  redirects with a notice only when the viewer can see that survivor;
  otherwise 404.
- **Help:** new guide `docs/manuals/guides/merging-duplicates.md`, linked
  from the three pages.

### 10A.8 Permissions

- `persons.duplicates.view` (all five intelligence roles),
  `persons.merge_request` (DSD, LGA chief), `persons.merge` (DG, DDG, SD).
  The super admin holds none.
- Updated together: the matrix, `auth.ts`, the navigation test and the
  permission drift test.
- Route inventory regenerated (342 routes, all authorised). The three
  routes with merge parameters are in the jurisdiction matrix's EXCLUDED
  list, pointing to `MergeHttpTest`, which covers them per role.

### Also fixed

- **`buffalo_incident_child_locked`** (Phase 5, pre-existing) checked only
  the row's new incident, so a casualty, agency, property or other child
  row could be moved off a closed or invalid incident onto an open one.
  It now refuses when either incident is locked (migration
  `2026_11_10_000002`, test in `IncidentWorkflowTest`).

## Verification

- `make test`: 1,673 Pest tests (13,370 assertions) and 14 Vitest pass.
  Phase 10A adds 71 Pest tests in `tests/Feature/Persons/Merge/`:
  - the reference registry;
  - the finder (each signal, own jurisdiction, grants, classification,
    states, dismissals, volume, nightly counts);
  - every merge rule and a full merge-then-undo on the rich fixture;
  - safety (authorisation per role, grants and requests, history,
    `survivor_prior_values`, append-only, undo limits, concurrency,
    notifications);
  - HTTP (pages per role, step-up, redirects including chains, requests,
    dismissal, the picker's own mode).
- `make lint`: Pint, Larastan level 7, ESLint, Prettier and tsc are clean.
- `make ci`: passes (hardening checks, dated exceptions, no Composer or npm
  advisories, fresh build, full suite).

### Index use (`EXPLAIN`)

On 20,000 persons in one LGA (the worst case for name pairs), with 4,000
aliases and 6,000 blind index rows, the finder query as the application
runs it:

```
Seq Scan on persons  (rows=20009)            -- the state filter selects every row: correct
Index Scan using blind_indexes_indexable_type_indexable_id_name_unique on blind_indexes x (rows=3)
Index Scan using blind_indexes_indexable_type_indexable_id_name_unique on blind_indexes y (rows=3)
  … (the same for phone and DOB)
Index Only Scan using person_duplicate_dismissals_person_a_id_person_b_id_unique
Planning Time: 18 ms
Execution Time: 1945 ms
```

The first design probed the trigram GIN index once per name
(`p2.search_name % v.t`). On the same data it hit a 300 s statement timeout:
common trigrams (the shared first names) make every probe read most of the
index. Name pairs now use **prefix filtering**, described under Deviations.
`DuplicateFinderTest` guards both the speed (20,000 persons, under 15 s
including the inserts) and the index-backed blind-index joins.

### Acceptance checklist

Run with a script (scratchpad, not shipped) on a freshly created UAT
environment: production images built from this tree, `APP_ENV=uat`,
synthetic Lagos and Kano data. It signs in with password and TOTP and uses
the endpoints the screens use. **9/9 passed:**

| Item | Result |
|---|---|
| Candidates list a planted NIN duplicate as certain and a misspelt-name pair with the same DOB as strong | Pass: "Same NIN" pair certain; "Same date of birth and similar name" pair strong |
| An LGA chief requests a merge; the State Director reviews, chooses fields, sees the visibility warning, confirms with TOTP | Pass. The chief's request (own LGA) was listed for the State Director and executed through the review page with a TOTP code; the request was marked executed. The visibility warning was checked on a cross-LGA pair: "Demo LGA LA-1-2 LGA will no longer see this person" (see Deviations: a chief's own records are all in one LGA) |
| Searching by the source's old name and NIN finds the survivor; the source's URL redirects | Pass |
| A Kano officer's active grant on the source is revoked and their open page closes | Pass: Kano searched the NIN, requested, the Lagos State Director approved, and the person page opened under the grant. After the merge: grant revoked ("record merged"), 404, "Temporary access revoked" notification. The page closing relies on the `AccessGrantEnded` broadcast, which is asserted in tests, not watched in a browser |
| Undoing the merge restores both records exactly | Pass: both person pages identical before the merge and after the undo (fields, place, classification); no aliases left from the merge |
| A pair with two different NINs cannot be merged | Pass: blocked on the review page; refused by the server; both records unchanged |


## Deviations and decisions

- **Name pairs use prefix filtering instead of a GIN probe per name.**
  - pg_trgm similarity is the Jaccard index of two trigram sets. Two names
    with similarity ≥ t must therefore share at least one of each name's
    n − ⌈t·n⌉ + 1 rarest trigrams (the standard prefix-filter bound), with
    rarity counted within the scope.
  - The join is a hash join on (trigram, LGA) over those prefixes; `%`
    then checks the threshold. This is exact: it misses no pair.
  - The CLAUDE.md rule ("nationwide fuzzy searches use the trigram index")
    is about searches against the table. A self-join across a state
    through the GIN index was quadratic in practice (above).
  - The query runs with `enable_nestloop = off` (transaction-local). Fresh
    data gave the planner row estimates of 1 for a 20,000-row CTE, and it
    chose nested loops that rescanned the CTE for every row (over 10
    minutes).
- **DOB pairs:** the 0.75 name check uses `similarity()` only on the pairs
  the DOB blind index has already found.
- **`persons_of_interest.merged_into_poi_id`** was added. The prompt asks
  for the source POI to be "soft-deleted with a link to the survivor's"
  but lists no column.
- **`person_merge_requests.lga_id` is nullable**: a Deputy State Director
  has no LGA.
- **`survivor_prior_values` holds the survivor's values before AND right
  after the merge** (one encrypted blob). Undo needs the "after" values to
  detect edits made since the merge without decrypting anything else.
- **APP_KEY rotation** may replace `survivor_prior_values` while it is set:
  the re-encryption sets `buffalo.key_rotation = 'on'` for its
  transaction. Otherwise the column can only be erased.
- **`buffalo.person_merge` and `buffalo.key_rotation` are guards against
  application code paths, not privilege boundaries.** The application
  role could set them in any transaction, as it can already update
  incident persons on open incidents. The triggers stop accidental
  changes; the audit trail (manifest, named events) records merges.
- **Undo is stricter than the prompt.** It is also refused when another
  record was merged into the survivor later and that merge is not undone
  ("Undo that merge first"); otherwise restoring the survivor's fields
  would overwrite the later merge's choices.
- **Edits since the merge:**
  - on the survivor's own fields, the executor keeps or discards each one
    (prompt);
  - on combined link rows (notes, membership status), a column edited
    since the merge keeps its current value, and the prior value is
    restored only if the column is unchanged;
  - a link column that has moved aborts the undo.
- **Visibility warning:** it applies only when the two records belong to
  different LGAs. An LGA chief's own records are all in one LGA, so a
  chief's request never shows it; the acceptance run shows it on a
  cross-LGA pair.
- **Review page counts** include link rows on records outside the
  executor's view (for example another state's incident that linked the
  person under a grant). They are numbers only, and those rows move too:
  leaving them on a deleted record would break them.
- **Merged records and source history:**
  - LGA chiefs see the merges of survivors in their LGA
    (`PersonMerge` implements `LgaVisibilityRule`);
  - the source's History needs `update` on the survivor (own jurisdiction,
    never a grant);
  - officer names come from `OfficerNames` (users are jurisdiction-scoped).
- **Dashboard counts** go to State Directors only (`persons.merge`): the
  state count includes records above a deputy's clearance.
- **Notifications:** the executor does not notify themselves.
  Revoked-grant holders get the existing `AccessGrantChanged` ("revoked by
  the owning state") and the live `AccessGrantEnded`.
- **No step-up for "Not a duplicate".** The prompt requires step-up for
  executing and undoing only.
- **The current photo is ordered by `order_column`, then ID** (it was the
  newest ID). New uploads still become current; a merge renumbers the
  chosen photo.

## New packages

None.

## Known issues

- **Open grant pages:** the acceptance run checks that the Kano officer's
  grant is revoked, that they get 404 and that they are notified. It did
  not watch a browser page close: that relies on the Phase 4A.0
  `GrantEndedWatcher` and the `AccessGrantEnded` broadcast, which
  `MergeSafetyTest` asserts.
- **Common names:** in a large LGA, many people with identical common
  names are all "possible" pairs. The list shows the strongest 200; use
  **Not a duplicate** to clear pairs that are known to be different
  people.
- **Duplicate incidents** (the CLAUDE.md entry on consolidating
  incidents) are not part of 10A.

## Anything the next step must know

- **A new table referencing persons** fails `PersonReferenceRegistryTest`
  until it is registered with a rule, handled in `MergePersons` and
  `UndoPersonMerge`, and documented in
  `docs/architecture/person-references.md`.
- **Merge and undo** write link rows only through `MergeManifest`, whose
  `TABLES` list is the allow-list.
- **`Person::scopeOwnJurisdiction`** is available for any picker or figure
  that must exclude records seen under a grant.
