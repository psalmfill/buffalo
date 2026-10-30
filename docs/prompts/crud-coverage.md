# BUFFALO — CRUD coverage audit and hierarchy administration

Audit create, view, edit and delete coverage for every entity users manage, and fix the gaps.

1. Audit first. Produce docs/architecture/crud-coverage.md: one row per entity (states, senatorial districts, LGAs, wards, communities, religious institutions, vulnerable population figures, security notes, persons, POIs, political offices, criminal groups and members, formations, coverage reports, organizations, sites, contacts, incidents, arrests, flashpoints, threats, reference data, users). For each operation (list, create, view, edit, delete or deactivate), record whether it exists in the UI, where it's reached from, and whether its absence is intentional (state the reason, such as append-only, locked or import-only) or a gap. Show me this table in the handover before guessing at intent.

2. Fix navigation gaps. Every list page whose entity can be created gets a visible New button (for example, New community on /communities, with an LGA picker limited by JurisdictionOptions). Every detail page gets Edit and Delete or Deactivate actions where the policy allows, hidden otherwise.

3. Hierarchy administration (Super Admin and DG, with step-up), under Administration:

States: edit capital; no create or delete, since there are exactly 37.
Senatorial districts, LGAs, wards: create, rename and deactivate, never delete.
Codes are editable only while unused: once any incident, threat or arrest reference uses an LGA code, it's locked.
Same rules as the import: uniqueness, district within state, ward within LGA, and the expected-count warning.
Accountability: every change is a named audit event with a reason, and deactivated entries remain on existing records.

4. Keep everything else intact: all policies, jurisdiction and classification rules unchanged. Register new list pages' exporters (11E coverage test), add the new routes to the route inventory, and add the new buttons to make browser-check.
