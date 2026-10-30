# Reference data files

BUFFALO never invents reference data (see `CLAUDE.md`). Every place name comes from a file in this directory.

| File | Status | Loaded by |
|---|---|---|
| `zones_states.csv` | **Present.** Authoritative list of the 6 geopolitical zones and 37 states (including the FCT), as specified in the Phase 1A prompt. | `ZonesAndStatesSeeder` |
| `senatorial_districts.csv` | **Supplied by the project owner.** Not yet provided. | `php artisan buffalo:import-hierarchy` |
| `lgas.csv` | **Supplied by the project owner.** Not yet provided. | `php artisan buffalo:import-hierarchy` |
| `wards.csv` | **Optional; supplied by the project owner.** Not yet provided. | `php artisan buffalo:import-hierarchy` |

Do not create the owner-supplied files with guessed contents. Until they exist, `make fresh` in a local environment (and the UAT environment) seeds clearly synthetic districts and LGAs for Lagos and Kano (`DevelopmentHierarchySeeder`). In any other environment, seeding fails with a message asking for the files. `php artisan buffalo:data:status` reports any synthetic data still present.

## General rules for all files

- Encoding: UTF-8. A byte-order mark (BOM) is allowed and ignored.
- Separator: comma. Any field that contains a comma must be quoted (`"Ifako-Ijaiye, Lagos"`).
- The header row is required. It must contain exactly the columns listed below, in any order. Missing or unknown columns are errors.
- Leading and trailing spaces around values are removed.
- Codes are case-sensitive and must be uppercase.
- Blank lines are ignored.
- Every row must have the same number of fields as the header.

## `zones_states.csv`

```
zone_code,zone_name,state_code,state_name
```

State codes are the ISO 3166-2:NG suffixes (`LA` = Lagos, `FC` = Federal Capital Territory). They appear in incident reference numbers such as `BUF/LA/IKJ/2026/000123`. The seeder upserts by code, so running it again is safe.

## `senatorial_districts.csv`

```
state_code,district_code,district_name
```

| Column | Rule |
|---|---|
| `state_code` | Required. Must be an existing state code (from `zones_states.csv`). |
| `district_code` | Required. Globally unique. Format `<STATE>-<X>`: the row's `state_code`, a hyphen, then one or more uppercase letters or digits (e.g. `LA-C`, `LA-E`, `LA-W`). |
| `district_name` | Required, at most 255 characters. Unique within the state (compared case-insensitively). |

Nigeria has 109 senatorial districts. A different total is an error unless `--allow-count-mismatch` is given.

Example (synthetic):

```csv
state_code,district_code,district_name
LA,LA-C,Example Central
LA,LA-E,Example East
```

## `lgas.csv`

```
state_code,district_code,lga_code,lga_name,headquarters
```

| Column | Rule |
|---|---|
| `state_code` | Required. Must be an existing state code. |
| `district_code` | Required. Must appear in `senatorial_districts.csv` **for the same state**. |
| `lga_code` | Required. Exactly 3 uppercase letters (`A`–`Z`). Unique within the state. |
| `lga_name` | Required, at most 255 characters. Unique within the state (compared case-insensitively). It must also not match the name of another LGA in the same state that is already in the database but missing from the file. |
| `headquarters` | Optional; may be blank. At most 255 characters. |

Nigeria has 774 LGAs. A different total is an error unless `--allow-count-mismatch` is given.

Example (synthetic):

```csv
state_code,district_code,lga_code,lga_name,headquarters
LA,LA-C,EXA,Example LGA,Example Town
LA,LA-E,EXB,"Example, Second LGA",
```

## `wards.csv` (optional)

```
state_code,lga_code,ward_code,ward_name
```

| Column | Rule |
|---|---|
| `state_code` | Required. Must be an existing state code. |
| `lga_code` | Required. Must appear in `lgas.csv` for the same state. |
| `ward_code` | Required. 1–20 characters: uppercase letters, digits and hyphens, starting with a letter or digit. Unique within the LGA. |
| `ward_name` | Required, at most 255 characters. Unique within the LGA (compared case-insensitively). |

There is no fixed ward total.

## Importing

```sh
make artisan cmd="buffalo:import-hierarchy --dry-run"   # validate and report only
make artisan cmd="buffalo:import-hierarchy"             # validate, then write
```

| Option | Effect |
|---|---|
| `--path=DIR` | Read the CSV files from `DIR` instead of `database/data`. |
| `--dry-run` | Validate and report, write nothing. |
| `--allow-count-mismatch` | Treat totals other than 37 / 109 / 774 as warnings instead of errors. |
| `--deactivate-missing` | Set `is_active = false` on districts, LGAs (and wards, when `wards.csv` is given) that are in the database but not in the files. Without it they are only reported. Nothing is ever deleted. |

Behaviour:

- The command checks every rule above in every file before writing anything. It reports all errors together, with file name and line number, and exits non-zero without touching the database.
- Writes happen in a single database transaction, upserting by code: districts by `district_code`, LGAs by state + `lga_code`, wards by LGA + `ward_code`. Running it again with the same files changes nothing; running it with corrected names updates them. A previously deactivated record that reappears in a file is reactivated.
- A summary table shows created, updated, unchanged and deactivated counts for each level.
- Entries created on screen in Administration › Hierarchy and missing from the files are listed separately (with who created them, when and why) and never deactivated, even with `--deactivate-missing`. An entry that appears in the files becomes an imported one. Screen-created entries follow the same code and name rules as the files (`App\Actions\Hierarchy\HierarchyRules`), and their codes never change. See `docs/operations/data-loading.md`.

`php artisan db:seed` (and `make fresh`) runs the import automatically when `lgas.csv` exists.

## Boundaries (GeoJSON, Phase 6A)

LGA and state boundaries come from an owner-supplied GeoJSON file, for example the Nigeria administrative boundaries published by GRID3 or OCHA. BUFFALO never downloads them. Import the LGA level first; state boundaries are then derived from the union of each state's LGAs, unless a state-level file is imported as well.

```sh
make artisan cmd="buffalo:import-boundaries database/data/lga_boundaries.geojson --level=lga --code-property=lga_code --state-property=state_code --attribution='GRID3 Nigeria LGA boundaries, CC BY 4.0' --dry-run"
```

### The file

- A GeoJSON `FeatureCollection`, UTF-8.
- Coordinates in WGS 84 (EPSG:4326), longitude first, as GeoJSON requires. A file declaring any other `crs` is refused; reproject it first (e.g. `ogr2ogr -t_srs EPSG:4326`).
- Each matched feature's geometry must be a `Polygon` or `MultiPolygon`. Both are stored as MultiPolygon.
- Invalid geometries (self-intersections and similar) are repaired with PostGIS `ST_MakeValid`, and each repair is listed. A geometry that cannot be repaired is an error.
- Every geometry must lie within Nigeria's bounding box (latitude 4.2–13.9, longitude 2.6–14.7, plus 0.05° slack).
- Each LGA (or state) may be matched by at most one feature.

### Matching features to our LGAs

**By properties:** `--code-property` names the feature property holding our LGA code, and `--state-property` the one holding our state code. Codes are compared case-insensitively with the `lga_code` and `state_code` of `lgas.csv`. With `--level=state`, only `--code-property` (holding the state code) is needed.

**By a mapping CSV:** when the file's own codes differ from ours, give `--mapping=FILE` with the columns below. `feature_id` is the feature's `id`, or the property named by `--id-property` (default `id`). `lga_code` is ignored with `--level=state`.

```
feature_id,state_code,lga_code
```

```csv
feature_id,state_code,lga_code
NGA001,LA,IKJ
NGA002,LA,EPE
```

### Options

| Option | Effect |
|---|---|
| `--level=lga\|state` | Which boundaries the file holds. |
| `--attribution=TEXT` | **Required.** Credit line for the source, shown on every map. |
| `--source-name=TEXT` | Name recorded for the import (default: the file name). |
| `--user=EMAIL` | The operator, recorded in `boundary_sources`. |
| `--dry-run` | Validate, match and report only; write nothing. |

### Behaviour

- Everything is validated before anything is written. Any error stops the import with nothing changed. Unmatched features, and LGAs (or states) left without a boundary, are listed but are not errors.
- Writes run in one transaction: the boundary, a centroid (`ST_PointOnSurface`, always inside the shape), a `boundary_sources` row, derived state boundaries, and simplified display geometries at three tolerances (national, state and LGA zoom).
- Afterwards, existing records whose point now lies outside their LGA's boundary are listed in `storage/app/private/reports/boundary-check-<time>.csv`. The list covers communities, religious institutions, formations, organization sites, incidents and persons of interest. It holds table, ULID, LGA and coordinates only. Nothing is changed and the import does not fail; correct the records (or the boundaries) by hand.
- From then on, new and edited locations must lie inside their LGA's boundary.

### Demo boundaries (local only)

`make artisan cmd="buffalo:generate-demo-boundaries"` gives every LGA a synthetic square, a grid around a rough state centre, so maps and boundary checks can be tried before the real file arrives. It refuses to run outside the `local` environment. The import is recorded as synthetic, and maps credit it as "SYNTHETIC demo boundaries (not real LGA shapes)". Replace it by importing the real file.
