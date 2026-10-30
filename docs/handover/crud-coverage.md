# CRUD coverage and hierarchy administration — handover

Prompt: `docs/prompts/crud-coverage.md`. Date: 2026-10-30.

## Summary

- **Audit:** `docs/architecture/crud-coverage.md` records, for every entity users manage, whether list, create, view, edit and delete (or deactivate) can be reached in the UI, and from where. It also says whether each absence is intentional, with the reason, or a gap. Four gaps were found and shown to the owner before any code changed. The owner's decisions are under **Decisions** below.
- **Navigation fixes:**
  - **New community** on `/communities`, with an LGA picker;
  - **Edit** on political office rows in the LGA and state **Leadership** tabs;
  - a read-only **Wards** tab on the LGA page, with exporter `wards`.
- **Hierarchy administration:** **Administration › Hierarchy** (`/system/hierarchy`), for super admins and the DG, with step-up and a reason for every change:
  - edit state capitals;
  - create, rename, deactivate and reactivate senatorial districts, LGAs and wards, never deleting them.

  Codes never change after creation. The import's rules apply. The CSV import now reports screen-created entries instead of deactivating them.
- Policies, jurisdiction scopes and classification rules are unchanged; one new ability (`StatePolicy::manageHierarchy`) and one new permission (`hierarchy.manage`) were added.

**Results:**

- `make lint` passes.
- `make ci` passes ("All CI checks passed"), including the full suite of **2,000 Pest tests** (17,336 assertions) and Vitest, on the final code.
- An earlier `make test` run had one failure, a spurious `origin` change in the audit log (see Known issues), which was fixed before `make ci`.
- `make browser-check` (local stack): **33 passed**, including the 4 new checks. Screenshots are `docs/uat/results/phase-11/47-…50-…png`.

## Audit (as shown before changes; now updated)

Key: ✅ exists and is reachable (policy-gated); ➖ absent on purpose (reason in the full table); **(fixed)** changed in this step.


| Entity | List | Create | View | Edit | Delete / deactivate |
|---|---|---|---|---|---|
| States | ✅ | ➖ fixed set of 37 | ✅ | ✅ capital (state page; Administration › Hierarchy **(fixed)**) | ➖ |
| Senatorial districts | ✅ | ✅ Hierarchy **(fixed)** | ✅ | ✅ rename, Hierarchy **(fixed)**; code never | ✅ deactivate and reactivate, Hierarchy **(fixed)** |
| LGAs | ✅ | ✅ Hierarchy **(fixed)** | ✅ | ✅ profile; rename and headquarters, Hierarchy **(fixed)**; code never | ✅ deactivate and reactivate, Hierarchy **(fixed)** |
| Wards | ✅ LGA page › Wards; Hierarchy **(fixed)** | ✅ Hierarchy **(fixed)** | ➖ row only | ✅ rename, Hierarchy **(fixed)**; code never | ✅ deactivate and reactivate, Hierarchy **(fixed)** |
| Communities | ✅ | ✅ also **New community** on `/communities` **(fixed)** | ✅ | ✅ | ✅ |
| Religious institutions | ✅ LGA tab | ✅ | ➖ row only | ✅ | ✅ |
| Vulnerable population figures | ✅ LGA tab | ✅ | ➖ row only | ✅ | ✅ |
| Security notes | ✅ LGA tab | ✅ | ✅ | ✅ | ✅ |
| Persons | ✅ | ✅ | ✅ | ✅ | ✅ |
| Persons of interest | ✅ | ➖ from the person | ✅ person page | ✅ | ✅ |
| Political offices | ✅ LGA, state, person | ➖ from the person | ➖ person page | ✅ person page and Leadership tabs **(fixed)** | ✅ person page |
| Criminal groups | ✅ | ✅ | ✅ | ✅ | ✅ |
| Criminal group members | ✅ group page | ✅ | ➖ row | ✅ | ✅ |
| Formations | ✅ | ✅ | ✅ | ✅ | ✅ |
| Coverage reports | ✅ | ✅ | ✅ | ✅ until submitted or reviewed | ✅ draft only |
| Organizations | ✅ | ✅ | ✅ | ✅ | ✅ |
| Organization sites | ✅ organization page, LGA tab | ✅ from the organization | ✅ | ✅ | ✅ |
| Organization contacts | ✅ site page | ✅ | ➖ row | ✅ | ✅ |
| Incidents | ✅ | ✅ | ✅ | ✅ until locked | ➖ invalidated, never deleted |
| Arrests | ✅ | ✅ | ✅ | ✅ | ✅ |
| Flashpoints | ✅ | ✅ | ✅ | ✅ | ✅ |
| Threat reports | ✅ | ✅ | ✅ | ✅ | ✅ |
| Reference data | ✅ | ✅ | ✅ | ✅ | ✅ deactivate and activate (never delete) |
| Users | ✅ | ✅ and CSV import | ✅ | ✅ | ✅ deactivate and reactivate (never delete) |


**Gaps found:**

1. No **New community** on `/communities`.
2. No hierarchy administration (districts, LGAs and wards were import-only).
3. No ward pages.
4. No Edit on Leadership-tab office rows.

All four are fixed. Every other detail page already had policy-gated Edit and Delete or Deactivate.

## Decisions (owner, 2026-10-30)

1. **The CLAUDE.md §5 conflict** (hierarchy names only from owner CSVs): UI edits are allowed, and CLAUDE.md is amended. The §5 bullet now refers to the decisions log, which has a new "Hierarchy administration" entry.
2. **Codes are set on create and never editable, at every level.** Besides incident references, codes are the import's matching key: an edited code would make the next official import create a duplicate. To correct a wrong code, fix the CSV and re-import. For a wrongly coded entry created on screen with nothing linked to it, deactivate it and create a corrected one. This explanation is shown as help text next to every code field and written in `docs/operations/data-loading.md`.
3. **The import and screen-created entries:** the import lists screen-created entries missing from the CSV as warnings, with name, code, creator, time and reason, and leaves them active, even with `--deactivate-missing`. The list ends with the instruction "Add these to the official CSV, or deactivate them in Administration → Hierarchy if they are no longer needed."
4. **Extras:** a ward read page (the LGA page's **Wards** tab) and **Edit** on Leadership rows.

## What was built

### Navigation fixes

- **New community** (`resources/js/components/local-government/new-community-button.tsx`, `CommunityController::index` prop `createLgas`):
  - lists the active LGAs within reach (`JurisdictionOptions::lgaQuery`) where `LgaPolicy::addCommunity` allows, grouped by state;
  - goes on to the existing `/lgas/{ulid}/communities/create`, which keeps its own checks;
  - with a single LGA (an LGA chief), the button links straight to the form;
  - hidden when there are none.
- **Wards tab** on `/lgas/{ulid}` (`LgaController::TABS`, `WardsExporter`):
  - wards with code, status and community count (communities through their own scope), active first;
  - hidden when the LGA has no wards;
  - the page and the export share one query (`lgas.show#wards`).
- **Edit on Leadership rows:**
  - `Leadership` adds `can.update` and `can.delete` to every office row it presents, from `PoliticalOfficePolicy`;
  - `OfficeHoldersTable` shows **Edit** (to `political-offices/{ulid}/edit`) where `can.update` is true;
  - this covers the LGA and state Leadership tabs and the senatorial district page.

### Hierarchy administration

- **Permission** `hierarchy.manage`, for the super admin and the DG, and ability `StatePolicy::manageHierarchy`. Updated together: `Permission` enum, `RolesAndPermissionsSeeder::matrix()` (`system` module), `auth.ts`, `RolesAndPermissionsTest` and the navigation test.
- **Migration** `2026_11_22_000001_add_hierarchy_admin_columns` adds to `senatorial_districts`, `lgas` and `wards`:
  - `origin` (`import` | `admin`, with a CHECK constraint, default `import`);
  - `created_by`;
  - `created_reason`.

  Trait `HasHierarchyOrigin` provides `creator()` and `createdOnScreen()`.
- **Shared rules:** `App\Actions\Hierarchy\HierarchyRules` holds the code formats, the maximum name length and the expected totals. `ReadHierarchyFiles` now uses it, so the screen and the import apply the same rules.
- **Actions** (`app/Actions/Hierarchy/Admin/`):
  - `CreateSenatorialDistrict`, `CreateLga`, `CreateWard`;
  - `RenameHierarchyEntry` (LGAs also headquarters; never the code);
  - `SetHierarchyEntryActive`;
  - `ChangeStateCapital`.

  Their rules:
  - **Uniqueness:** district codes are global; LGA codes are unique within the state and ward codes within the LGA. Names are unique within the parent, case-insensitively, compared with active and deactivated entries alike.
  - **Parents:** an LGA's district must be in the same state and active; a ward's LGA must be active.
  - **Deactivation:** a district or LGA with active children cannot be deactivated; reactivation needs an active parent.
  - **Accountability:** every action takes a reason and writes a named audit event (`HierarchyChange::record`): `senatorial_district_created`, `lga_renamed` (with the old values), `ward_deactivated`, `lga_reactivated`, `state_capital_changed` (before and after), and so on. The model's own change log records the field changes as well.
- **Controller** `System\HierarchyController`, with routes in `routes/system.php`, 14 in total:
  - pages `system.hierarchy.index`, `.state` and `.lga`;
  - PUT and POST routes for the capital, create, rename and active state;
  - parameters `{adminDistrict}`, `{adminLga}` and `{adminWard}`, bound by ULID without the jurisdiction-checked `{lga}` and `{district}` bindings (hierarchy entries are national reference data);
  - every change calls `StepUp::ensure`; the Form Requests (`app/Http/Requests/Hierarchy/`) authorise before validation.
- **Expected-count warning:** `HierarchyTotals` compares the national active totals with 37, 109 and 774. It shows as a banner on the pages and in the toast after a change, and never blocks.
- **Pages** (`resources/js/pages/system/hierarchy/`):
  - `index`: 37 states with capital, district and LGA counts (active and deactivated), and screen-created counts;
  - `state`: districts and LGAs, with **New district**, **New LGA**, **Rename**, **Deactivate** and **Reactivate**;
  - `lga`: wards, with **New ward** and the same actions.

  Each page has a "Show deactivated entries" toggle and a "Created on screen" badge with creator, time and reason. One shared form dialog (`components/hierarchy/entry-dialog.tsx`) requires the reason and follows Inertia's router events, so it closes correctly after the step-up retry.
- **Menu:** Administration › **Hierarchy** (`Waypoints` icon), for `hierarchy.manage`.
- **Import** (`ImportHierarchy`, `HierarchyImportReport::$onScreen`, `ImportHierarchyCommand::reportOnScreen`):
  - imported rows get `origin = import`, so a screen-created entry that appears in the files becomes an imported one;
  - active screen-created entries missing from the files are reported separately (see Decision 3) and never deactivated.

## Files

**New:**

- `app/Actions/Hierarchy/HierarchyRules.php`
- `app/Actions/Hierarchy/Admin/` (7 classes)
- `app/Http/Controllers/System/HierarchyController.php`
- `app/Http/Requests/Hierarchy/` (4 classes)
- `app/Models/Concerns/HasHierarchyOrigin.php`
- `app/Support/Hierarchy/HierarchyTotals.php`
- `app/Support/Exports/Lists/Exporters/WardsExporter.php`
- `database/migrations/2026_11_22_000001_add_hierarchy_admin_columns.php`
- `resources/js/pages/system/hierarchy/{index,state,lga}.tsx`
- `resources/js/components/hierarchy/` (code help, dialog, actions, status, totals warning)
- `resources/js/components/local-government/new-community-button.tsx`
- `resources/js/types/hierarchy.ts`
- `tests/Feature/Hierarchy/HierarchyAdminTest.php`
- `tests/Feature/LocalGovernment/CrudCoverageTest.php`
- `tests/browser/specs/10-crud-coverage.spec.ts`
- `docs/architecture/crud-coverage.md`
- `docs/prompts/crud-coverage.md`

**Changed:**

- **Hierarchy import:** `ImportHierarchy`, `HierarchyImportReport`, `ReadHierarchyFiles`, `ImportHierarchyCommand`.
- **Models:** `Lga`, `SenatorialDistrict` and `Ward` (`Ward::communities()` added).
- **Permissions:** `StatePolicy`, `Permission`, `RolesAndPermissionsSeeder`, `auth.ts`.
- **Controllers and support:** `CommunityController`, `LgaController`, `Leadership`, `ListExporters`.
- **Routes and navigation:** `routes/system.php`, `navigation.ts`.
- **Frontend pages and components:** `communities/index.tsx`, `lgas/show.tsx`, `office-holders-table.tsx`.
- **Tests:**
  - `JurisdictionMatrixTest` (11 exclusions);
  - `ListExporterCoverageTest`, `ListExportsTest`;
  - `RolesAndPermissionsTest`, `navigation.test.ts`.
- **Docs:**
  - `docs/security/route-inventory.md` (391 routes);
  - `docs/operations/data-loading.md`, `database/data/README.md`;
  - manuals: super admin, DG, LGA chief;
  - `CLAUDE.md`.

## Tests

| File | Covers |
|---|---|
| `HierarchyAdminTest` (12) | **Access:** super admin and DG allowed; DDG, SD, DSD and chief 403 on pages and changes. **Step-up** (428) and a required reason. **Capital:** a named event with before and after; no state create or delete. **District rules:** prefix, case, global code, name in any case. **LGA rules:** district of the same state and active, code format and uniqueness, name; the same code allowed in another state. **Ward rules.** **Rename:** the code is kept even if sent; unique within the parent; event with the old values. **Deactivation:** children block it; a community keeps its deactivated ward and LGA; top-down reactivation. **Counts:** the 774 warning toast and banner. **Pages:** creator and reason shown, deactivated entries listed. **Import:** screen-created entries reported with creator and reason, never deactivated, while imported ones still are; a screen-created entry found in the files becomes `import`. |
| `CrudCoverageTest` (5) | **New community LGAs** per role (chief: own LGA; SD and DSD: their state; DG: national), deactivated LGAs excluded, super admin refused; the create form keeps its own 404. **Wards tab:** order, community counts through the scope, page and export parity, never another LGA's wards, hidden when empty. **Leadership rows:** `can` flags equal the policy. |
| `ListExporterCoverageTest`, `ListExportsTest` | The `wards` exporter is registered and exports with markings; `system.hierarchy.index` is excluded with a reason. |
| `JurisdictionMatrixTest`, `RouteInventoryTest` | The new routes are excluded with the covering test and inventoried with Form Request authorisation. |
| `10-crud-coverage.spec.ts` (browser) | **DG:** New community picks an LGA and opens the form; the LGA page's Wards tab; Edit on a Leadership row opens the office edit form; Hierarchy › Lagos › LGA › New ward (code help shown, step-up, "Created on screen") then Deactivate and Show deactivated. **Super admin:** Edit capital dialog. **SD:** no menu item, 403. |

## Deviations and notes

1. **Code locks:** the prompt locks LGA codes once an incident, threat or arrest reference uses them. Only incident references contain LGA codes (`THR/` and `ARR/` carry the state code only). The owner chose a stricter rule: codes never change after create, at every level (Decision 2).
2. **The admin list is not exported:** `system.hierarchy.index` is in `ListExporters::EXCLUDED` rather than having an exporter. Super admins may export only the users list (`ReportPolicy::exportList`), and the prompt keeps policies unchanged. The same entries are exported from the states, LGAs, senatorial districts and new wards lists. Reference data administration is excluded the same way.
3. **The import still wins:** an official import overwrites screen renames for entries in the files, and reactivates entries in the files that were deactivated on screen. This is the source-of-truth rule; it is documented in `data-loading.md`.
4. **LGA district changes** stay import-only. The prompt asks for create, rename and deactivate, and moving an LGA between districts changes which senator represents it, so it stays with the official files.
5. **Deactivation does not cascade:** a district or LGA with active children is refused, and its children are deactivated first. Reactivation goes top-down. No active entry ever sits under a deactivated one through the screen. The import can still create that situation; this is existing behaviour.
6. **Pickers:** deactivated entries were already left out of forms (`JurisdictionOptions` and the ward lists use active entries), and records keep their existing references (nothing is deleted; the foreign keys `restrictOnDelete`). The New community picker lists active LGAs only.
7. **Political office Edit flags** also appear on the senatorial district page, which shares the same table component.

## New packages

None.

## Known issues and environment notes

- **Local Valkey:** the append-only file on the local stack was corrupt (a half-written last command), so Valkey, Horizon and the backup container were restart-looping before this step. I backed up the data directory to the session scratchpad, then truncated the incomplete final 1,044 bytes with `valkey-check-aof --fix` on the incremental file. Valkey is healthy again. The local backup container still restarts with "permission denied" on its restic volume; that is unrelated to this step and was not changed.
- **The audit default:** the three models declare `origin`'s default in `$attributes`. Without it, the first update of a freshly created model logged a spurious `origin` change (`AuditLogTest` caught it in the first full run).
- **Memory:** during `make ci` and the browser check, the local `buffalo-uat` containers were stopped (with the owner's approval) because `vite build` ran out of Docker memory (7.7 GB shared with other projects). They were started again afterwards.
- **Re-seed permissions** on every environment (done on the local dev database for the browser check): `php artisan db:seed --class=RolesAndPermissionsSeeder --force`. Run migrations as usual.

## For the next step

- New hierarchy writes must go through the `app/Actions/Hierarchy/Admin` actions (reason, audit event, `HierarchyRules`), never ad-hoc updates.
- Any new list page needs an exporter or a justified exclusion (`ListExporterCoverageTest`). Any new detail page should show policy-gated Edit and Delete or Deactivate; record it in `docs/architecture/crud-coverage.md`.
