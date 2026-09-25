# BUFFALO — Phase 2A: Reference Data

Read `CLAUDE.md` before starting. Read `docs/handover/phase-1-summary.md` and `docs/handover/phase-1e.md`.

If anything in this prompt conflicts with what Phase 1 actually built (names of classes, permissions, navigation config, etc.), follow the existing code and report the conflict in the handover. Do not restructure Phase 1 work.

## Context

Phase 1 is complete: hierarchy, users, roles, jurisdiction scoping, authentication, audit, notifications, navigation and dashboards.

This step builds the administrator-managed lookup tables that later modules depend on, including the dynamic field system that lets each incident type have its own extra fields.

## Goal

Lookup tables with seeded defaults, an admin UI to manage them, and a reusable dynamic-field system (definition format, server-side validator, React renderer, admin field builder).

---

## Step 2A.1 — Tables

All lookup tables share: `id`, `ulid`, `name`, `code` (unique, lowercase snake_case, immutable once used), `description` (nullable), `sort_order` (integer), `is_active` (default true), `created_by`, `updated_by`, timestamps. **No soft deletes and no deletion**: lookup values are deactivated, never removed, because records will reference them. Deactivated values stay visible on existing records but cannot be chosen for new ones.

| Table | Extra columns |
|---|---|
| `incident_types` | `category` (enum: `crime`, `violence_conflict`, `accident`, `disaster_environmental`, `security_agency`, `other`), `default_severity` (enum: `low`, `medium`, `high`, `critical`), `details_schema` (jsonb, default `[]`), `schema_version` (integer, default 1) |
| `threat_types` | `category` (same enum as incident types) |
| `security_agencies` | `short_name`, `agency_type` (enum: `federal`, `state`, `community`, `private`), `state_id` (nullable FK; set only for state-level outfits) |
| `religions` | — |
| `denominations` | `religion_id` FK; `code` unique within religion |
| `property_types` | — |

These are not jurisdiction-scoped (everyone reads them). Apply the `LogsBuffaloActivity` trait to all of them.

## Step 2A.2 — Dynamic field definitions

`details_schema` is an ordered JSON array of field definitions. **This format is BUFFALO's own; do not use JSON Schema or add a package for it.**

```json
[
  {
    "key": "ransom_amount",
    "label": "Ransom demanded (₦)",
    "type": "number",
    "required": false,
    "min": 0,
    "help": "Leave blank if unknown"
  }
]
```

| Property | Rule |
|---|---|
| `key` | required; lowercase snake_case; unique within the schema; immutable once any record uses the type |
| `label` | required |
| `type` | one of `text`, `textarea`, `integer`, `number`, `boolean`, `date`, `datetime`, `select`, `multiselect` |
| `required` | boolean, default false |
| `options` | required for `select`/`multiselect`: array of `{ "value": "...", "label": "..." }` |
| `min`, `max` | optional, numeric types only |
| `max_length` | optional, text types only (default 255 for `text`, 5000 for `textarea`) |
| `help` | optional |

Build in `app/Support/DynamicFields/`:

1. `FieldDefinition` value object and `SchemaValidator`: validates a schema itself (used when admins edit it). Rejects unknown types, duplicate keys, missing options, invalid key formats.
2. `DetailsValidator`: builds Laravel validation rules from a schema and validates a `details` payload. Unknown keys are **rejected**, not silently dropped. Returns cleaned, correctly typed values.
3. **Schema evolution rules** (enforced by `SchemaValidator` when comparing old and new schemas for a type already in use): fields may be added, relabelled, reordered or have options added; fields may be marked `"deprecated": true` (hidden from new entry, still displayed on old records). Keys cannot be renamed, types cannot change, and options cannot be removed (only deprecated). Each saved change increments `schema_version`.

Frontend in `resources/js/components/dynamic-fields/`:

4. `DynamicFields` component: renders a schema as form inputs (shadcn/ui), shows help text and validation errors, and hides deprecated fields for new entries.
5. `DynamicFieldsView` component: read-only display of saved values, including deprecated fields, with option labels rather than raw values.
6. Admin **field builder** for editing an incident type's schema: add, reorder (up/down buttons are sufficient), edit and deprecate fields; manage options; live preview using `DynamicFields`. Server-side `SchemaValidator` is authoritative.

## Step 2A.3 — Default data

`ReferenceDataSeeder`, idempotent (upsert by `code`; never overwrite fields an admin has changed after seeding — only create missing entries). These are starting defaults the client can edit.

**Incident types** (name → category, default severity, detail fields):

| Name | Category | Severity | Detail fields |
|---|---|---|---|
| Road mishap | accident | medium | `vehicles_involved` (integer, min 1); `vehicle_types` (multiselect: car, bus, truck/trailer, tanker, motorcycle, tricycle, other); `suspected_cause` (select: overspeeding, brake failure, bad road, dangerous overtaking, impaired driving, unknown, other); `road_name` (text) |
| Fire outbreak | disaster_environmental | medium | `fire_type` (select: residential, market, industrial, bush, vehicle, oil/gas facility, other); `suspected_cause` (text); `fire_service_responded` (boolean) |
| Cult-related incident | violence_conflict | high | `groups_involved` (text); `weapons_used` (multiselect: firearms, machetes/knives, explosives, other, unknown) |
| Murder | crime | high | `weapon_used` (select: firearm, knife/machete, blunt object, poison, other, unknown); `suspected_motive` (text) |
| Armed robbery | crime | high | `target_type` (select: residence, business, bank, highway/vehicle, POS operator, other); `weapons_used` (multiselect as above); `estimated_loss_naira` (number, min 0) |
| Kidnapping | crime | critical | `location_type` (select: home, highway, farm, school, place of worship, workplace, other); `ransom_demanded` (boolean); `ransom_amount_naira` (number, min 0) |
| Sea piracy | crime | high | `vessel_name` (text); `vessel_type` (select: cargo, tanker, fishing, passenger, supply/offshore, other); `waterway` (text) |
| Oil bunkering | crime | high | `activity_type` (select: pipeline vandalism, illegal refinery, illegal loading/barge, other); `facility` (text); `estimated_volume_litres` (number, min 0); `vehicles_or_barges_seized` (integer, min 0) |
| Religious violence | violence_conflict | critical | `trigger` (textarea) |
| Banditry | violence_conflict | critical | `estimated_attackers` (integer, min 0); `livestock_rustled` (integer, min 0); `villages_affected` (integer, min 0) |
| Terrorist attack | violence_conflict | critical | `attack_method` (select: IED, suicide attack, armed assault, abduction, arson, other); `group_claiming` (text) |
| Flood | disaster_environmental | medium | `cause` (select: heavy rainfall, dam release, river overflow, blocked drainage, other); `households_affected` (integer, min 0); `persons_displaced` (integer, min 0); `farmland_hectares` (number, min 0) |
| Erosion | disaster_environmental | medium | `erosion_type` (select: gully, coastal, sheet/other); `structures_threatened` (integer, min 0); `road_cut_off` (boolean) |
| Security agency-related incident | security_agency | high | `involvement` (select: attack on personnel, attack on facility, misconduct allegation, accidental discharge, inter-agency clash, other) |
| Other security incident | other | medium | (none) |

Casualties, persons, agencies and property are **not** detail fields; they have their own tables in Phase 5.

**Threat types:** kidnapping, banditry, cultism, communal clash, farmer-herder conflict, terrorism, armed robbery, sea piracy, oil theft, political violence, religious tension, flooding, erosion, other.

**Security agencies** (all `federal`): Nigeria Police Force (NPF), Nigerian Army (NA), Nigerian Navy (NN), Nigerian Air Force (NAF), Department of State Services (DSS), Nigeria Security and Civil Defence Corps (NSCDC), Federal Road Safety Corps (FRSC), Nigeria Customs Service (NCS), Nigeria Immigration Service (NIS), National Drug Law Enforcement Agency (NDLEA), Economic and Financial Crimes Commission (EFCC), Federal Fire Service (FFS), National Emergency Management Agency (NEMA). State and community outfits are added by administrators.

**Religions and denominations:** Christianity (Catholic, Anglican, Methodist, Baptist, Pentecostal, Other Christian); Islam (Sunni, Shia, Other Islamic); Traditional religion; Other.

**Property types:** residential building, commercial building, vehicle, government facility, place of worship, school, farmland/crops, livestock, pipeline/oil facility, vessel, telecom infrastructure, other.

## Step 2A.4 — Permissions and UI

1. Permissions: `reference_data.view` for **all six roles** (reference data is configuration, not intelligence, so `super_admin` included), and `reference_data.manage` for `super_admin` and `director_general`.
2. Navigation: new "Administration → Reference data" item (`reference_data.view`).
3. `/reference-data` index with one tab per table. List, create, edit, activate/deactivate. Read-only for users without `reference_data.manage`.
4. Incident type edit page includes the field builder (2A.2), with a warning banner that changes to a type in use are restricted.
5. `ReferenceData` query helper returning **active** options for forms, cached in Valkey and invalidated on change.
6. Run `make wayfinder`.

## Step 2A.5 — Tests

- `SchemaValidator`: every rule, including all evolution rules against an in-use type.
- `DetailsValidator`: each field type, required, min/max, max_length, options, unknown keys rejected, type casting.
- Seeder: creates all defaults, is idempotent, and does not overwrite an admin's edits.
- Deactivated values are rejected for new selections but still resolve for display.
- Deletion is impossible (no route; model delete throws or is blocked).
- Permissions: manage only for super admin and DG; view for all.
- Cache invalidation on change.
- Frontend: `DynamicFields` renders every field type (Vitest if present).

## Acceptance checklist

- [ ] As DG, add a field to "Kidnapping", reorder fields, deprecate one, and see the live preview update.
- [ ] Attempting to rename a key or change a field type on an in-use type is rejected with a clear message. (Simulate "in use" with a test double; incidents do not exist yet.)
- [ ] An LGA chief sees reference data read-only.
- [ ] `make test` and `make lint` pass.

## Out of scope

LGA profiles and communities (2B); religious institutions, vulnerable population statistics, security notes (2C).

## Handover

Write `docs/handover/phase-2a.md` per CLAUDE.md section 9 and print it.
