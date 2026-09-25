# Handover — Phase 2A: Reference data

Date: 2026-09-25

## Summary

The lookup tables that later modules depend on are in place:

- incident types, threat types, security agencies, religions, denominations and property types;
- seeded defaults;
- an admin UI;
- the dynamic-field system that gives each incident type its own detail fields. It has four parts: the definition format, `SchemaValidator` (including evolution rules), `DetailsValidator`, and the React renderer, read-only view and field builder with live preview.

`make test` (544 Pest tests with 3074 assertions, plus 13 Vitest tests) and `make lint` pass. The browser acceptance walkthrough passed.

Commits:

| Step | Commit |
|---|---|
| 2A.1 | `feat(reference-data): add lookup tables and models` |
| 2A.2 (server) | `feat(dynamic-fields): add schema and details validators` |
| 2A.3 | `feat(reference-data): seed default reference data` |
| 2A.2 (frontend) + 2A.4 | `feat(reference-data): add permissions, management UI and field builder` |
| — | `fix(dynamic-fields): keep field builder inputs aligned` (found during acceptance) |
| — | `docs: add phase 2a handover` (this file, README, prompt) |

The 2A.5 tests were written with each step.

## Acceptance checklist

| Item | Result |
|---|---|
| As DG, add a field to "Kidnapping", reorder fields, deprecate one, and see the live preview update | ✅ In Chrome as a Director General:<br>- **Add:** added a field. Typing the label "Number of victims" set the key to `number_of_victims`, and the type was changed to "Whole number" (min/max inputs appeared).<br>- **Reorder:** moved the field up above "Ransom amount".<br>- **Deprecate:** deprecated "Ransom demanded".<br>- **Preview:** it followed every step: the new field appeared, the order changed, and the deprecated field disappeared.<br>- **Save:** stored version 2 with keys `location_type, ransom_demanded (deprecated), number_of_victims, ransom_amount_naira`, and the audit log has the update by the DG.<br>- **Errors:** an invalid key ("Victims Count") was rejected by the server, and the message appeared under the Key field with the card outlined in red. |
| Renaming a key or changing a field type on an in-use type is rejected with a clear message | ✅ With a test double for "in use" (`ReferenceUsage` bound to a class that says every incident type is in use), over HTTP:<br>- renaming: `The field "victims" cannot be removed or renamed because this incident type is in use. Deprecate it instead.`<br>- type change: `The type of "victims" cannot change from integer to text because this incident type is in use.`<br>- removing an option: rejected as well.<br><br>In every case the version is unchanged. The compatible change in the same test (reorder, relabel, deprecate, add a field and an option) is accepted. The edit page shows the "in use" warning banner, and the builder locks keys, types and existing options. |
| An LGA chief sees reference data read-only | ✅ Tested: every table, the view page and the edit page. For an LGA chief, `can.manage` is false, the manage routes return 403, and there are no New/Edit/Deactivate buttons. The navigation test also shows "Reference data" for LGA chiefs. |
| `make test` and `make lint` pass | ✅ |

## What was built

### 2A.1 — Tables and models

**Migration `2026_09_28_000001_create_reference_data_tables`:** six tables with the shared columns `id`, `ulid`, `name`, `code`, `description`, `sort_order`, `is_active`, `created_by`, `updated_by` and timestamps. There are no soft deletes.

| Table | Extra columns |
|---|---|
| `incident_types` | `category`, `default_severity`, `details_schema` (jsonb, default `[]`), `schema_version` (default 1) |
| `threat_types` | `category` |
| `security_agencies` | `short_name`, `agency_type`, `state_id` (nullable) |
| `denominations` | `religion_id`; `code` unique within the religion |

**Database constraints:**

- CHECK constraints on:
  - code format (`^[a-z][a-z0-9_]*$`);
  - the enum columns;
  - `details_schema` being an array;
  - `schema_version >= 1`;
  - `security_agencies`: `(agency_type = 'state') = (state_id IS NOT NULL)`.
- Triggers reject `DELETE` and `TRUNCATE` on all six tables (`buffalo_reject_reference_delete()`).

**Enums:** `IncidentCategory`, `Severity` and `AgencyType`, each with labels.

**Models** (`IncidentType`, `ThreatType`, `SecurityAgency`, `Religion`, `Denomination`, `PropertyType`) extend `ReferenceModel`. It uses the `IsReferenceData` trait, which provides:

- `HasUlidRouteKey`, `LogsBuffaloActivity` and the `active()` scope;
- a `deleting` guard that throws;
- an `updating` guard that throws if `code` changes;
- clearing the cached form options on every save;
- `toOption()`: ULID, name, code and the table's extras.

**Support classes:**

- `ReferenceTable` enum: URL slug → model, labels, table name.
- `ReferenceData`:
  - `options($table)`: active values, cached forever in the default store (Valkey) per table and cleared on change;
  - `find($table, $ulid)`: any value, active or not, for display.
- `ReferenceUsage` contract: "is this value used by records?", bound to `NoReferenceUsage` (always false) until records exist.
- `App\Rules\ActiveOption($model, $keep)`: a new selection must be an active value. `$keep` (the record's current value) stays valid, so existing records remain editable. It is reusable for Phase 3–6 forms.
- `details_schema` is not mass-assignable; only `UpdateIncidentTypeSchema` writes it.

### 2A.2 — Dynamic fields

**Server** (`app/Support/DynamicFields/`):

- **Value objects:** `FieldType` (with the text limits of 255 and 5000), `FieldOption` and `FieldDefinition`. `toArray()` gives a canonical stored form, leaving out properties that don't apply.
- **`SchemaValidator::validate()`** checks:
  - that the schema is a list of objects;
  - no unknown properties (on fields or options);
  - key present, lowercase snake_case, at most 64 characters, unique;
  - label present, at most 255 characters;
  - type from the list; `required` and `deprecated` are booleans;
  - options only and always on select types: value and label, unique values, and at least one non-deprecated option unless the field itself is deprecated;
  - `min`/`max` only on numeric types (whole numbers for `integer`), with min ≤ max;
  - `max_length` only on text types, from 1 up to the type's limit;
  - help text at most 500 characters;
  - a deprecated field cannot be required.

  Errors are keyed `schema.{i}.{property}`.
- **`SchemaValidator::assertCompatible($current, $proposed)`** (types in use): no removed or renamed keys, no type changes, and no removed or renamed option values. Adding, relabelling, reordering and deprecating (fields and options) are allowed.
- **`DetailsValidator::validate($schema, $details, $existing = [])`:**
  - builds Laravel rules per type (text/textarea `max`; integer/number with bounds; `boolean`; `date_format` for date and datetime; `in` for select; list plus `distinct` plus `in` for multiselect items);
  - rejects unknown keys;
  - returns typed values: ints, int-or-float, booleans, dates as `Y-m-d`, datetimes as `Y-m-d\TH:i`, and multiselect values in option order. Empty optional values are left out;
  - **deprecated fields and options** cannot be chosen for new values. A record's existing value (`$existing`) is accepted when resubmitted unchanged;
  - messages use the field labels.
- **`UpdateIncidentTypeSchema`:** validates the schema; checks compatibility when `ReferenceUsage` says the type is in use; compares canonical forms (jsonb does not keep key order); and saves with `schema_version + 1` only if something changed. The change is audited.

**Frontend** (`resources/js/components/dynamic-fields/`):

- **`DynamicFields`:**
  - renders every type with shadcn/ui inputs:
    - boolean is a three-state select (not recorded, yes, no);
    - multiselect is a checkbox group;
    - date and datetime use the native pickers;
  - shows help text, required markers and server errors (including item errors);
  - in `mode="new"`, hides deprecated fields and options; in `edit` mode, shows them (disabled) when the record has a value.
- **`DynamicFieldsView`:** read-only display with option labels, Yes/No, formatted numbers and datetimes, and deprecated fields marked "(no longer used)".
- **`FieldBuilder`:**
  - collapsible field cards with up/down, deprecate/restore and remove;
  - label, key, type, required, help, min/max, max length and options (add, deprecate, remove);
  - keys and option values follow the label until edited by hand;
  - when the type is in use, it locks keys, types and existing options, and removal is disabled with a "deprecate instead" hint. `SchemaValidator` remains authoritative.
- **`types/dynamic-fields.ts`** holds the format, and `components/ui/textarea.tsx` is shadcn's standard textarea, added locally.

### 2A.3 — Default data

- **What it seeds:** `ReferenceDataSeeder` creates every default from the prompt:
  - 15 incident types with their detail fields;
  - 14 threat types;
  - 13 federal agencies;
  - 4 religions with 9 denominations;
  - 12 property types.
- **Codes and option values:** snake_case of the names; for example "Truck/trailer" → `truck_trailer` and "IED" → `ied`.
- **Validation:** each incident type's fields pass through `SchemaValidator` before saving, so the defaults obey the same rules as admin edits.
- **Non-destructive:** an entry is created only when its code (or religion + code) is missing. Existing rows are never touched.
- **Order:** `DatabaseSeeder` calls it before the hierarchy step, which stops outside `local` until the owner's LGA files exist. `make setup` therefore seeds reference data everywhere.

### 2A.4 — Permissions and UI

- **Permissions:** `reference_data.view` goes to all six roles and `reference_data.manage` to the super admin and DG. They are in `App\Enums\Permission`, the seeder matrix and `PERMISSIONS` in `types/auth.ts`.
- **`ReferenceDataPolicy`**, registered for all six models: `viewAny`/`view` need view; `create`/`update` need manage; `delete` is always false. Reference data is not jurisdiction-scoped.
- **Routes** (`routes/reference-data.php`; `{table}` is the `ReferenceTable` enum, and unknown slugs are 404):
  - `GET /reference-data` redirects to incident types;
  - `GET {table}` — index;
  - `GET {table}/create` and `POST {table}` — create;
  - `GET {table}/{ulid}` — view;
  - `GET {table}/{ulid}/edit` and `PUT {table}/{ulid}` — edit;
  - `POST {table}/{ulid}/activate|deactivate`;
  - `PUT incident-types/{ulid}/schema` — the field builder's save.

  There is no delete route.
- **Pages** (`resources/js/pages/reference-data/`):
  - **`index`:** a tab per table, search and status filter, extra columns per table, status badges, and Edit and Activate/Deactivate actions (with confirmation) only for managers;
  - **`show`:** read-only details, and for incident types the field list plus "how the form looks";
  - **`form`:** create/edit with the table's extra fields (server-described: selects, a state field shown only for state outfits, religion fixed after creation). For incident types it adds the "Detail fields" section: the in-use warning banner (or a notice that changes become restricted), the field builder, a separate "Save detail fields" button and the live preview.
- **Presenter:** `ReferenceValuePresenter` builds rows, form values and form-field metadata, using ULIDs and labels only.
- **Navigation:** "Reference data" under Administration, gated on `reference_data.view`, is one line in `config/navigation.ts`.
- **`make wayfinder`** was run.

## Deviations and conflicts

- **`code` is fixed once created, not "once used".** The seeder recognises its defaults by code. If an admin could rename a default's code before first use, re-seeding would recreate the original as a duplicate value. Names, descriptions and order stay editable. The model guard and the `prohibited` rule on update enforce this.
- **A denomination's religion is also fixed once created**, because its code is unique within the religion.
- **`security_agencies.state_id` without `HasJurisdiction`.** `CLAUDE.md` rule 2 says every model with a `state_id` uses the jurisdiction scope. The prompt says the lookup tables are not jurisdiction-scoped. I followed the prompt, as with `lgas.state_id` on the Phase 1A hierarchy: the state is an attribute of the reference value, not the location of intelligence. Please confirm.
- **"In use" is a contract** (`ReferenceUsage`), because no records exist yet. `NoReferenceUsage` returns false. Phases 3 (persons: religions and denominations), 5 (incidents: incident types, agencies, property types) and 6 (threats) must bind a real implementation, for example one that composes per-table checks. Until then every incident type accepts incompatible schema changes, as intended before first use.
- **Additional schema rules beyond the prompt:**
  - unknown properties are rejected;
  - a deprecated field cannot be required;
  - a select field keeps at least one available option;
  - `max_length` may lower the default limit, not raise it.

  On an in-use type, changing `required`, `min`, `max` or labels is allowed (the prompt does not forbid it). Making an old field required also applies when old records are edited.
- **Extra pages and endpoints:**
  - a read-only **view page** per value, so viewers can see an incident type's fields and form preview;
  - a **separate schema endpoint**, because detail fields are saved separately from name, category and severity.
- **`ReferenceModel` base class:** alongside the `IsReferenceData` trait, so typed code can use one class for all six models.
- **Frontend test approach:** the `DynamicFields` Vitest test renders with `react-dom/server` in Node, so no jsdom or Testing Library package was added.
- **Updated tests:** `RolesAndPermissionsTest` (new permissions; the LGA chief's restored set is now `reference_data.view`) and the navigation test's role table.

## New packages

None. `components/ui/textarea.tsx` is shadcn's textarea source, copied locally like the other UI components.

## Known issues and notes

- **Cache invalidation relies on model events.** Raw query-builder updates to lookup tables would leave the cached options stale until the next model save. Always change reference data through the models or Actions.
- **jsonb reorders keys inside each field definition** (not the field order). Compare schemas through `FieldDefinition` (as `UpdateIncidentTypeSchema` does), never with `===` on the stored array.
- **Dev database changes:**
  - I created a local Director General, `dg@buffalo.local` (password `Director#General2026`, 2FA secret set via Tinker), for the walkthrough;
  - the dev "Kidnapping" type is now at schema version 2 (the fields added, reordered and deprecated during acceptance);
  - `make fresh` resets both.
- **Your `CLAUDE.md` edits** are still uncommitted; I left them out of my commits.

## For the next steps

- **Forms that pick reference values** should use `ReferenceData::options(ReferenceTable::X)` for choices and `new ActiveOption(Model::class, $currentUlid)` for validation. For display, use `ReferenceData::find()` or the relation, since deactivated values still resolve.
- **Incident details (Phase 5):** validate `details` with `DetailsValidator::validate(FieldDefinition::listFromSchema($type->details_schema), $input, $incident->details ?? [])`. Store the cleaned result, and consider storing the `schema_version` the record was entered under. Render with `DynamicFields` (`mode="edit"` for existing incidents) and `DynamicFieldsView`.
- **When records start using lookups:** bind a real `ReferenceUsage` in `AppServiceProvider`, and consider restricting deactivation of values in heavy use (not required now).
