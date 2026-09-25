# Handover — Phase 1A: Administrative hierarchy

Date: 2026-09-25

## Summary

The geographic backbone is in place: geopolitical zones, states, senatorial districts, LGAs and wards, with migrations, models, factories, seeders and a strict CSV import command. `make fresh` works without the owner's CSVs: it seeds 6 zones, 37 states and clearly synthetic Lagos/Kano districts and LGAs, with a visible warning. Larastan is at level 7. `make test` (120 tests, 592 assertions), `make lint` and `make ci` all pass.

Commits:

| Step | Commit |
|---|---|
| 1A.0 | `chore: raise larastan to level 7 and remove unused starter-kit files` |
| 1A.1 | `feat(hierarchy): add administrative hierarchy migrations` |
| 1A.2 | `feat(hierarchy): add hierarchy models and factories` |
| 1A.3 | `feat(hierarchy): add zones and states reference data` |
| 1A.4 | `docs(hierarchy): document owner-supplied hierarchy csv formats` |
| 1A.5 | `feat(hierarchy): add zones/states seeder, hierarchy import command and dev seeder` |
| 1A.6 | `test(hierarchy): cover constraints, models, seeders and hierarchy import` |
| — | `docs: add phase 1a handover` (this file) |

## What was built

### 1A.0 — Housekeeping

- `phpstan.neon` is at level 7. The only new error was `env('APP_NAME')` passed to `Str::slug()` in `config/horizon.php`; it is now cast to string.
- Removed `laravel/sail` (Composer) and `pnpm-workspace.yaml`. The README now says level 7.

### 1A.1 — Migrations (`database/migrations/2026_09_25_00000{1..5}_*`)

- All five tables have `id`, `ulid` (unique `char(26)`), `code`, `name`, `is_active` (default true) and timestamps. There are no soft deletes and no `created_by`/`updated_by`.
- Geography uses Laravel 13's native `geography()` column: `geography(Point,4326)` / `geography(MultiPolygon,4326)` on `states` and `lgas`, all nullable and empty for now. Each has a GIST index via `spatialIndex()`. No PostGIS package was added.
- Every foreign key is `restrictOnDelete()` and has its own btree index.
- Uniqueness:
  - zone, state and district `code` are unique globally.
  - `lgas` has `UNIQUE (state_id, code)` and `UNIQUE (state_id, name)`. The name constraint is `DEFERRABLE INITIALLY IMMEDIATE`; see below.
  - `wards` has `UNIQUE (lga_id, code)`.
- **Same-state guarantees, implemented as composite foreign keys** (the prompt left check vs. trigger to me):
  - `senatorial_districts` has `UNIQUE (id, state_id)`. `lgas_district_same_state_foreign`: `lgas (senatorial_district_id, state_id) REFERENCES senatorial_districts (id, state_id) ON UPDATE RESTRICT ON DELETE RESTRICT`.
  - `lgas` has `UNIQUE (id, state_id)`. `wards_lga_same_state_foreign`: `wards (lga_id, state_id) REFERENCES lgas (id, state_id) ON UPDATE RESTRICT ON DELETE RESTRICT`.
  - Why not a CHECK: a CHECK cannot look at another table.
  - Why not a trigger: a trigger on the child table misses changes to the parent, such as moving a district to another state while its LGAs still point at it. It also needs PL/pgSQL. The composite FK is declarative, always enforced, visible in `\d`, has no code path to bypass it, and covers both directions. The same guarantee can be added to any later table that stores `state_id` next to `lga_id`.

### 1A.2 — Models and factories

- `app/Models/{GeopoliticalZone,State,SenatorialDistrict,Lga,Ward}.php`, with generic relation annotations for Larastan level 7.
- Shared traits in `app/Models/Concerns/`, intended for every future model:
  - `HasUlidRouteKey`: Laravel's `HasUlids` applied to the `ulid` column (`uniqueIds()`), with `getRouteKeyName()` returning `ulid`. The bigint `id` stays the primary key. Route binding returns 404 for anything that is not a valid ULID, including numeric IDs.
  - `HasActiveScope`: `scopeActive()`.
- Relationships:
  - zone → `states()` and `lgas()` (`hasManyThrough` State).
  - state → `geopoliticalZone()`, `senatorialDistricts()`, `lgas()` (`hasManyThrough` SenatorialDistrict, as the prompt asks; the composite FK guarantees it matches `lgas.state_id`), and `wards()` (direct, via the denormalised `wards.state_id`).
  - district → `state()`, `lgas()`.
  - LGA → `state()`, `senatorialDistrict()`, `wards()`.
  - ward → `lga()`, `state()`.
- `$hidden` removes `id`, parent foreign-key IDs and geometry columns from serialisation, so numeric IDs cannot reach the frontend by accident.
- The models are **not** jurisdiction-scoped.
- Factories produce "Test Zone/State/District/LGA/Ward n" and synthetic codes (LGA codes are three random uppercase letters). They keep parents consistent:
  - `Lga::factory()` given a `senatorial_district_id` takes the state from the district.
  - Given a `state_id`, it creates a district in that state.
  - `Ward::factory()` always takes `state_id` from its LGA.
  - Every factory has an `inactive()` state.

### 1A.3 — `database/data/zones_states.csv`

The exact content from the prompt: 37 states, 6 zones (NC 7, NE 6, NW 7, SE 5, SS 6, SW 6).

### 1A.4 — `database/data/README.md`

Documents the three owner-supplied formats, the general rules (UTF-8, BOM allowed, header required with columns in any order, trimming, quoting), every validation rule per column, and the import options and behaviour. The owner files were **not** created.

### 1A.5 — Seeders and import command

- **`app/Support/Csv/CsvReader`** (+ `CsvFile`): a strict, reusable reader. It checks UTF-8, strips a BOM, requires the exact header set (reporting missing, unknown and duplicate columns), checks the field count on every row, skips blank lines, trims values, and handles quoted commas and line breaks. Errors carry the physical line number (`lgas.csv:12: …`), and all errors are collected rather than stopping at the first.
- **`ZonesAndStatesSeeder`**: reads `zones_states.csv`, validates it, and upserts zones and states by code in a transaction. Idempotent; ULIDs are kept on re-run. `capital` is left null because it isn't in the file.
- **`php artisan buffalo:import-hierarchy`** (`app/Console/Commands/ImportHierarchyCommand.php`, which is thin):
  - `App\Actions\Hierarchy\ReadHierarchyFiles` validates everything before any write:
    - headers, required fields and code formats (`<STATE>-<X>` districts, 3-letter LGAs, ward codes);
    - that every `state_code` exists, and that each LGA's district is in the file **for the same state**;
    - that each ward's LGA is in the file for that state;
    - no duplicate codes, and no duplicate names within a state (case-insensitive);
    - database conflicts: a district code can't move between states, and a file LGA can't reuse the name of an LGA that stays in the database;
    - the 37 / 109 / 774 totals. A mismatch is an error, or a warning with `--allow-count-mismatch`.
  - A reference to a row that is itself invalid doesn't cascade into further errors.
  - `App\Actions\Hierarchy\ImportHierarchy` builds a plan by comparing file rows with the database. It upserts by code (districts: code; LGAs: state + code; wards: LGA + code) inside a single transaction and runs `SET CONSTRAINTS lgas_state_id_name_unique DEFERRED` first. A dry run executes the same code path with writes turned off, so its counts are exactly what a real run would do.
  - Never deletes. Active records missing from the files are listed. With `--deactivate-missing` they are set to `is_active = false`.
  - Options: `--path`, `--dry-run`, `--allow-count-mismatch`, `--deactivate-missing`.
  - Output: warnings, all errors (then exit 1 with "Nothing was written."), missing-record lists, and a summary table of Created / Updated / Unchanged / Missing from files / Deactivated per level.
- **`DevelopmentHierarchySeeder`**:
  - Throws outside `APP_ENV=local`, and skips with a message when `lgas.csv` exists.
  - Creates 3 districts per state for Lagos and Kano (`LA-D1` "Demo District LA-1" … `KN-D3`) and 4 LGAs per district (`D11` "Demo LGA LA-1-1" … `D34`), 6 districts and 24 LGAs in total. It is idempotent.
  - Prints a boxed "SYNTHETIC HIERARCHY DATA IN USE" warning.
- **`DatabaseSeeder`** runs `ZonesAndStatesSeeder`, then:
  - the import command if `lgas.csv` exists, throwing if the import fails;
  - otherwise, in local only, `DevelopmentHierarchySeeder`;
  - otherwise, it throws a clear message naming the missing files and the README.

### 1A.6 — Tests (120 total; 71 new)

- `tests/Feature/Hierarchy/HierarchyConstraintsTest.php`:
  - both composite FKs reject mismatches on insert and on update, including moving a district with LGAs to another state;
  - code and name uniqueness scopes, and restricted deletes;
  - the geography column types and SRID, 4 GIST indexes, and an index on every FK.
- `HierarchyModelsTest.php`: ULID generation and route key for all five models, route binding rejecting numeric IDs and malformed ULIDs, hidden attributes, every relationship in both directions, both `hasManyThrough` relations, `active()` and synthetic factory names.
- `ZonesAndStatesSeederTest.php`: 6 zones and 37 states, every state checked against the CSV row including its zone, per-zone counts, no duplicates and stable ULIDs on re-run, and names restored on re-run.
- `DevelopmentHierarchySeederTest.php`:
  - refuses to run in `testing` and writes nothing;
  - in local, creates the right synthetic data idempotently;
  - `DatabaseSeeder` fails with a clear message outside local without CSVs, and uses the synthetic data in local.
- `ImportHierarchyCommandTest.php`, with fixtures in `tests/fixtures/hierarchy/` (see its README):
  - valid import, then a second run that is fully unchanged (identical row snapshot);
  - a full-size 109/774 synthetic set that passes without the flag;
  - **17 validation fixtures**, one per rule, each failing with the right `file:line` message and an unchanged database;
  - multiple errors reported together, a missing required file, optional `wards.csv`;
  - count mismatch failing without the flag and warning with it;
  - `--dry-run` writing nothing, both on an empty and on a populated database;
  - renames updating, a name swap between two LGAs in one import, and the database name-conflict error;
  - missing records reported but left active, `--deactivate-missing` deactivating without deleting, reactivation, and synthetic data reported as missing.
- `tests/Unit/CsvReaderTest.php`: BOM, trimming, header order, line numbers across CRLF, blank lines and quoted line breaks, invalid UTF-8, empty or missing files, and bad headers.

## Files created or changed

- **Created:**
  - Migrations: `database/migrations/2026_09_25_000001` … `000005`.
  - Models: `app/Models/{GeopoliticalZone,State,SenatorialDistrict,Lga,Ward}.php` and `app/Models/Concerns/{HasUlidRouteKey,HasActiveScope}.php`.
  - Factories: `database/factories/{GeopoliticalZone,State,SenatorialDistrict,Lga,Ward}Factory.php`.
  - Data: `database/data/zones_states.csv`, `database/data/README.md`.
  - CSV support: `app/Support/Csv/{CsvReader,CsvFile}.php`.
  - Import: `app/Actions/Hierarchy/{ReadHierarchyFiles,ImportHierarchy,HierarchyDataset,HierarchyImportReport}.php`, `app/Console/Commands/ImportHierarchyCommand.php`.
  - Seeders: `database/seeders/{ZonesAndStatesSeeder,DevelopmentHierarchySeeder}.php`.
  - Tests: `tests/Feature/Hierarchy/*`, `tests/Unit/CsvReaderTest.php`, `tests/Support/HierarchyFixtures.php`, `tests/fixtures/hierarchy/**`.
  - Docs: `docs/prompts/phase-1a.md`, this file.
- **Changed:** `phpstan.neon`, `config/horizon.php`, `composer.json`/`.lock` (sail removed), `database/seeders/DatabaseSeeder.php` (rewritten), `README.md` (level 7, plus a "Reference data" section).
- **Deleted:** `pnpm-workspace.yaml`.

## Deviations and interpretations

1. **Composite foreign keys instead of a CHECK or trigger** for the same-state rules. The reasons are above; they give the same guarantee and are stronger.
2. **`UNIQUE (state_id, name)` on `lgas` is deferrable, initially immediate.** Normal writes are still checked straight away. The import defers the check to commit, so a corrected file can swap two LGA names. I edited the 1A.1 migration in place; it had only run on local development databases. **Any existing dev database needs `make fresh`.**
3. **Missing-record handling covers every level.** The prompt describes it for LGAs. Districts, and wards when `wards.csv` is given, are reported and deactivated the same way, because the summary table has a deactivated count per level.
4. **Reactivation.** A previously deactivated record that reappears in the files is set active again and counted as updated.
5. **Validation rules the prompt did not spell out** (documented in `database/data/README.md`, easy to change):
   - The district code suffix is one or more uppercase letters or digits.
   - A ward code is 1–20 characters: uppercase letters, digits and hyphens, starting with a letter or digit.
   - District names must be unique within a state, and ward names and codes within an LGA.
   - Name comparisons are case-insensitive.
   - Every name is at most 255 characters.
   - The 37-state check counts states in the database.
6. **Extra database-conflict checks.** A district code can't move between states. A file LGA can't take the name of an LGA that stays in the database; without this check the transaction would fail on the unique constraint with a less helpful message.
7. **Extra `--path` option**, used by the tests and useful for staging a new file set.
8. **Synthetic district codes are `LA-D1` … `KN-D3`.** The prompt gave `D11` as an example LGA code. District codes follow the documented `<STATE>-<X>` format, and the `D` makes them impossible to confuse with real codes.
9. **`DevelopmentHierarchySeeder` skips rather than throws when `lgas.csv` exists.** It still throws outside local, which is the tested refusal.
10. **`DatabaseSeeder` no longer seeds the starter kit's `test@example.com` user**, in line with the new `CLAUDE.md` rule against default credentials. It also dropped `WithoutModelEvents`, which would stop ULIDs being generated in the `creating` event.
11. **Serialisation hides numeric IDs and geometry** on all five models. The prompt did not ask for this, but `CLAUDE.md` forbids numeric IDs in the browser.
12. **A blank `headquarters` is stored as NULL.**

## Packages

No new packages. Removed `laravel/sail` (v1.68.0).

## Known issues and notes

- **Owner CSVs are still needed:** `database/data/senatorial_districts.csv` and `lgas.csv` (and optionally `wards.csv`). Until then, only the local environment can be seeded.
- **`make fresh` removes all users.** The Phase 0 verification user `dev@buffalo.local` no longer exists, and no users are seeded. Phase 1B adds the command that creates the first super admin.
- **ULID case.** ULIDs are stored in lowercase, which is Laravel's `Str::ulid()` format. Route binding accepts only valid ULIDs.
- **Boundaries and centroids** are empty, pending the later GeoJSON import. `states.capital` is empty because it isn't in the authoritative CSV.
- **Your `CLAUDE.md` edits are not committed.** The working tree has your uncommitted changes (level 7, fail-closed scoping, passkeys removed, the new decisions and tooling notes). I left them out of my commits; commit them when you're ready.
- **Summary wording.** The import's missing-record heading uses the level label as-is ("6 Senatorial districts in the database are not in the files"). This is cosmetic.

## For the next step (1B)

- Use `App\Models\Concerns\HasUlidRouteKey` on every new model (users included), so URLs and broadcast channels use ULIDs (`users.{ulid}`).
- Jurisdiction-scoped models should store `state_id`/`lga_id` directly. Consider the same composite-FK pattern (`(lga_id, state_id) REFERENCES lgas (id, state_id)`) to keep them consistent. `lgas` already has the required `UNIQUE (id, state_id)`.
- The hierarchy models are deliberately **not** jurisdiction-scoped. Use `->active()` for selectable options. Restricting what a user may select belongs to the jurisdiction options service.
- State codes (`LA`) and LGA codes (`IKJ`-style, 3 letters) are available for reference numbers such as `BUF/LA/IKJ/2026/000123`. Synthetic LGAs use `D11`-style codes.
- Zones can be used to scope `deputy_director_general` assignments (`GeopoliticalZone::lgas()` / `states()`).
- Tests that need real states can run `$this->seed(ZonesAndStatesSeeder::class)`. Everything else should use the factories.
