# BUFFALO — Phase 1A: Administrative Hierarchy

Read `CLAUDE.md` before starting. All rules there apply. Read `docs/handover/phase-0.md` for what already exists.

## Context

Phase 0 is complete: Laravel 13 with the React starter kit, Docker stack, PostgreSQL 18 + PostGIS, Valkey, Horizon, Reverb, offline map. No BUFFALO domain tables exist yet.

This step creates the geographic backbone every later module attaches to: geopolitical zones, states, senatorial districts, LGAs and wards.

## Goal

Migrations, models, factories and seeders for the administrative hierarchy, with production data loaded from CSV files and a synthetic dataset for local development when the CSVs are not yet available.

---

## Step 1A.0 — Housekeeping

1. Raise Larastan to level 7 in `phpstan.neon` and fix any resulting errors.
2. Remove `laravel/sail` from Composer and delete `pnpm-workspace.yaml`.

**Commit:** `chore: raise larastan to level 7 and remove unused starter-kit files`

## Step 1A.1 — Migrations

All five tables have `id` (bigint), `ulid` (unique), `name`, `code`, `is_active` (boolean, default true), and timestamps. These are reference tables: no soft deletes, no `created_by`/`updated_by`.

| Table | Additional columns | Constraints |
|---|---|---|
| `geopolitical_zones` | — | `code` unique |
| `states` | `geopolitical_zone_id` FK, `capital` (nullable), `centroid` `geography(Point,4326)` nullable, `boundary` `geography(MultiPolygon,4326)` nullable | `code` unique |
| `senatorial_districts` | `state_id` FK | `code` unique |
| `lgas` | `state_id` FK, `senatorial_district_id` FK, `headquarters` (nullable), `area_km2` decimal nullable, `population_estimate` integer nullable, `centroid` nullable point, `boundary` nullable multipolygon | `code` unique **within** `state_id`; `name` unique within `state_id` |
| `wards` | `lga_id` FK, `state_id` FK (denormalised, per CLAUDE.md) | `code` unique within `lga_id` |

Additional rules:

- Foreign keys use `restrictOnDelete()`. Reference data is deactivated, never deleted.
- GIST indexes on every geography column.
- Index every foreign key.
- A database check or trigger (your choice; explain in handover) guaranteeing an LGA's `senatorial_district_id` belongs to the same `state_id`. Same for a ward's `state_id` matching its LGA's state.
- Boundaries and centroids stay empty in this step. A later step will import them from GeoJSON.

Use a PostGIS-aware approach for the geography columns that works with Laravel 13's schema builder. If native `geography()` column support is available, use it; otherwise use raw statements inside the migration. Do not add a PostGIS package without reporting it per CLAUDE.md.

## Step 1A.2 — Models and factories

- Models: `GeopoliticalZone`, `State`, `SenatorialDistrict`, `Lga`, `Ward` in `app/Models`.
- ULID generated automatically on create; `getRouteKeyName()` returns `ulid`.
- Relationships in both directions (zone → states, state → districts/LGAs/wards, district → LGAs, LGA → wards, plus `belongsTo` for each parent). Also `State::lgas()` and `GeopoliticalZone::lgas()` via `hasManyThrough`.
- A `scopeActive()` query scope on each.
- These models are **not** jurisdiction-scoped. Every authenticated user needs to see place names (e.g. for dropdowns). Restricting which places a user may *select* in forms is handled later by a jurisdiction options service.
- Factories producing clearly synthetic names (e.g. `Test LGA 12`) and codes. Factories must respect the parent relationships (an LGA factory creates or uses a district in the same state).

## Step 1A.3 — Zones and states data

Create `database/data/zones_states.csv` with exactly this content (this list is authoritative and supplied by the project owner):

```csv
zone_code,zone_name,state_code,state_name
NC,North Central,BE,Benue
NC,North Central,KO,Kogi
NC,North Central,KW,Kwara
NC,North Central,NA,Nasarawa
NC,North Central,NI,Niger
NC,North Central,PL,Plateau
NC,North Central,FC,Federal Capital Territory
NE,North East,AD,Adamawa
NE,North East,BA,Bauchi
NE,North East,BO,Borno
NE,North East,GO,Gombe
NE,North East,TA,Taraba
NE,North East,YO,Yobe
NW,North West,JI,Jigawa
NW,North West,KD,Kaduna
NW,North West,KN,Kano
NW,North West,KT,Katsina
NW,North West,KE,Kebbi
NW,North West,SO,Sokoto
NW,North West,ZA,Zamfara
SE,South East,AB,Abia
SE,South East,AN,Anambra
SE,South East,EB,Ebonyi
SE,South East,EN,Enugu
SE,South East,IM,Imo
SS,South South,AK,Akwa Ibom
SS,South South,BY,Bayelsa
SS,South South,CR,Cross River
SS,South South,DE,Delta
SS,South South,ED,Edo
SS,South South,RI,Rivers
SW,South West,EK,Ekiti
SW,South West,LA,Lagos
SW,South West,OG,Ogun
SW,South West,ON,Ondo
SW,South West,OS,Osun
SW,South West,OY,Oyo
```

State codes are the ISO 3166-2:NG suffixes. They are used later in incident reference numbers (e.g. `BUF/LA/IKJ/2026/000123`).

## Step 1A.4 — CSV formats for owner-supplied data

These files will be supplied by the project owner later. **Do not create them and do not invent their contents.** Document the formats in `database/data/README.md`:

**`senatorial_districts.csv`**
```
state_code,district_code,district_name
```
`district_code` is globally unique, format `<STATE>-<X>` (e.g. `LA-C`, `LA-E`, `LA-W`).

**`lgas.csv`**
```
state_code,district_code,lga_code,lga_name,headquarters
```
`lga_code` is exactly 3 uppercase letters, unique within the state. `headquarters` may be blank.

**`wards.csv`** (optional)
```
state_code,lga_code,ward_code,ward_name
```

All files: UTF-8, header row required, commas as separators, fields with commas quoted.

## Step 1A.5 — Seeders and import command

1. `ZonesAndStatesSeeder`: reads `zones_states.csv`, upserts by code. Idempotent.
2. `php artisan buffalo:import-hierarchy` command:
   - Reads `senatorial_districts.csv`, `lgas.csv` and, if present, `wards.csv`.
   - Validates **everything before writing anything**: header row, required fields, code formats, every `state_code` exists, every `district_code` referenced by an LGA exists in the same state, no duplicate codes or names within a state.
   - Reports all validation errors together with file name and line number, then exits non-zero without writing.
   - Checks expected totals: 37 states, 109 senatorial districts, 774 LGAs. A mismatch is an **error** unless `--allow-count-mismatch` is passed, in which case it is a warning.
   - Writes inside a single database transaction, upserting by code (idempotent; re-running with corrected data updates names).
   - Never deletes. LGAs present in the database but missing from the CSV are reported and, only with `--deactivate-missing`, set to `is_active = false`.
   - `--dry-run` validates and reports without writing.
   - Prints a summary table of created, updated, unchanged and deactivated counts per level.
3. `DevelopmentHierarchySeeder` (runs only when `APP_ENV=local` **and** `lgas.csv` is absent):
   - Creates synthetic senatorial districts and LGAs for **Lagos** and **Kano** only: 3 districts each, 4 LGAs per district, named `Demo District LA-1`, `Demo LGA LA-1-1`, etc., with codes such as `D11`.
   - Prints a clear warning that synthetic data is in use.
4. `DatabaseSeeder` calls `ZonesAndStatesSeeder`, then the import command if the CSVs exist, otherwise `DevelopmentHierarchySeeder` in local only. In any non-local environment with no CSVs, seeding fails with a clear message.

## Step 1A.6 — Tests

- Migrations: the check constraints reject an LGA whose district belongs to another state, and a ward whose state differs from its LGA's state.
- `ZonesAndStatesSeeder`: produces 6 zones and 37 states with the correct zone for each; running it twice creates no duplicates.
- Import command, using CSV fixtures in `tests/fixtures/hierarchy/` built from synthetic names:
  - Valid files import successfully, and a second run reports everything unchanged.
  - Each validation rule has a failing fixture, and the database is untouched after a failure.
  - Count mismatch fails without the flag and warns with it.
  - `--dry-run` writes nothing.
  - `--deactivate-missing` deactivates and never deletes.
- `DevelopmentHierarchySeeder` refuses to run outside `local`.
- Model relationships, including `hasManyThrough`.
- Route key is `ulid`.

## Acceptance checklist

- [ ] `make fresh` works on a machine with no owner CSVs, producing 37 states and the synthetic Lagos/Kano data with a visible warning.
- [ ] `buffalo:import-hierarchy --dry-run` on the fixtures reports correctly.
- [ ] The database check constraints exist and are tested.
- [ ] Larastan level 7 passes.
- [ ] `make test` and `make lint` pass.

## Out of scope

Users, roles, jurisdiction scoping (1B); LGA profiles, communities (Phase 2); boundary GeoJSON import (later).

## Handover

Write `docs/handover/phase-1a.md` per CLAUDE.md section 9 and print it.
