# CRUD coverage

This file records, for each entity users manage, whether list, create, view, edit and delete (or deactivate) can be reached in the UI. Audit date: 2026-10-30 (after Phase 11F, commit `53dc94e`). The tables show the state **after** the CRUD coverage step fixed the gaps; **(fixed)** marks what changed and the last section lists the gaps as they were found.

It was checked against the routes (`docs/security/route-inventory.md`), the policies (`app/Policies`) and the buttons on the React pages and panels. A ✅ means the action exists **and** a button or link reaches it, shown only where the policy allows it.

**Key**

- ✅ exists, with where it is reached from.
- ➖ absent on purpose, with the reason.
- ❌ a gap.
- ❓ absent, and the intent is unclear (needs the owner's decision).

## Summary

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

## Detail

### Hierarchy

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **States** | List | ✅ `/states` | Side menu: States & LGAs › States | |
| | Create | ➖ | | Intentional: exactly 37 states, seeded from `database/data/zones_states.csv` (Phase 1A). |
| | View | ✅ `/states/{ulid}` | State list, `StateLink` everywhere | |
| | Edit | ✅ capital only | State page › Profile (`state_profiles.update`, whole-state reach); Administration › Hierarchy › **Edit capital** **(fixed)** | Name, code and zone are seed-only. |
| | Delete | ➖ | | Intentional: fixed set; `is_active` exists but has no UI. |
| **Senatorial districts** | List | ✅ `/senatorial-districts` | Side menu | |
| | Create | ✅ **(fixed)** | Administration › Hierarchy › state › **New district** | Also the CSV import (`senatorial_districts.csv`), which stays the source of truth. |
| | View | ✅ `/senatorial-districts/{ulid}` | District list | |
| | Edit | ✅ rename **(fixed)** | Hierarchy › **Rename** | Codes never change (the import's matching key). |
| | Deactivate | ✅ **(fixed)** | Hierarchy › **Deactivate** / **Reactivate** | Not while it has active LGAs. Never deleted (FK `restrictOnDelete`). |
| **LGAs** | List | ✅ `/lgas` | Side menu | |
| | Create | ✅ **(fixed)** | Hierarchy › state › **New LGA** | Also the CSV import (`lgas.csv`). |
| | View | ✅ `/lgas/{ulid}` | LGA list, district page, state page › LGAs | |
| | Edit | ✅ profile (`lgas.profile.update`); name and headquarters **(fixed)** | LGA page › Profile; Hierarchy › **Rename** | The code never changes (incident references, the import's matching key). The district changes only through the import. |
| | Deactivate | ✅ **(fixed)** | Hierarchy › **Deactivate** / **Reactivate** | Not while it has active wards. Records keep it. |
| **Wards** | List | ✅ **(fixed)** | LGA page › **Wards** (read-only, exporter `wards`); Hierarchy › LGA | |
| | Create, rename | ✅ **(fixed)** | Hierarchy › LGA › **New ward** / **Rename** | Also the CSV import (`wards.csv`, optional). Codes never change. |
| | View | ➖ | Row in the Wards tab | A ward has only a code and a name. |
| | Deactivate | ✅ **(fixed)** | Hierarchy › **Deactivate** / **Reactivate** | Records keep it. |

### LGA information

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **Communities** | List | ✅ `/communities` | Side menu; LGA page › Communities | |
| | Create | ✅ `/lgas/{ulid}/communities/create`; CSV import | `/communities` › **New community** with an LGA picker **(fixed)**; LGA page › Communities › **Add community**; `/communities` › **Import CSV** | |
| | View | ✅ | Community list, LGA tab, map | |
| | Edit | ✅ | Community page (`can.update`) | |
| | Delete | ✅ soft delete | Community page (`can.delete`) | |
| **Religious institutions** | List | ✅ LGA tab | LGA page › Religious institutions (exporter page `lgas.show#religious_institutions`) | Intentional: LGA-level information, no national list (Phase 2C). |
| | Create | ✅ | LGA tab › **Add** | |
| | View | ➖ | Row in the LGA tab | Intentional: few fields, all shown in the row; no detail page was specified. |
| | Edit, delete | ✅ | Row actions in the LGA tab | |
| **Vulnerable population figures** | List | ✅ LGA tab | LGA page › Vulnerable population | Intentional: aggregate counts per LGA (CLAUDE.md §5). |
| | Create | ✅ | LGA tab › **Add** | |
| | View | ➖ | Row in the LGA tab | Intentional: a row of counts. |
| | Edit, delete | ✅ | Row actions in the LGA tab | |
| **Security notes** | List | ✅ LGA tab | LGA page › Security notes | Intentional: LGA-level. |
| | Create | ✅ | LGA tab › **Add security note** | |
| | View | ✅ `/security-notes/{ulid}` | LGA tab, flashpoint links | |
| | Edit, delete, status | ✅ | Security note page | |

### Persons and political information

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **Persons** | List, create | ✅ | Side menu; **New person** on `/persons` | |
| | View, edit, delete | ✅ | Person page (`can.update`, `can.delete`); merge and merge requests (Phase 10) | |
| **Persons of interest** | List | ✅ `/persons-of-interest` | Side menu | |
| | Create | ➖ | Person page › **Designate as person of interest** | Intentional: a POI is a designation of an existing person (Phase 3C), so `/persons-of-interest` has no New button. |
| | View | ✅ | POI list → person page › POI panel | Intentional: no separate POI page. |
| | Edit, status, review, delete | ✅ | Person page › POI panel | |
| **Political offices** | List | ✅ | LGA page › Leadership; state page › Leadership; person page › Political offices | Intentional: no national office list was specified. |
| | Create | ➖ | Person page › **Add office** | Intentional: an office is held by a person (Phase 3B). |
| | View | ➖ | Rows in the panels above | |
| | Edit, reassign, delete | ✅ | Person page › Political offices panel; **Edit** on the LGA and state Leadership tabs' rows **(fixed)** | |
| **Criminal groups** | List, create, view, edit, delete | ✅ | Side menu; **New group**; group page | |
| **Criminal group members** | List, add, edit, remove | ✅ | Group page › Members | Intentional: a membership row, no detail page. |

### Security agencies, coverage and organizations

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **Formations** | All | ✅ | Side menu; **New formation**; formation page; LGA tab › **Add formation** | |
| **Coverage reports** | List, create, view | ✅ | Side menu; **New report**; report page | |
| | Edit | ✅ while editable | Report page | Intentional: locked once submitted and reviewed (CLAUDE.md §10). |
| | Delete | ✅ draft, by its creator | Report page | Intentional, as above. |
| **Organizations** | All | ✅ | Side menu; **New organization**; organization page | |
| **Organization sites** | List | ✅ | Organization page › Sites; LGA page › Organizations | Intentional: no national site list (sites belong to an organization). |
| | Create | ✅ | Organization page › **Add site** | |
| | View, edit, delete | ✅ | Site page | |
| **Organization contacts** | List, add, edit, delete | ✅ | Site page › Contacts | Intentional: rows, no detail page (encrypted fields). |

### Incidents, arrests, flashpoints, threats

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **Incidents** | List, create, view | ✅ | Side menu; **New incident**; incident page | |
| | Edit | ✅ | Incident page (`can.update`) | Locked when closed or invalid; reasons required after submission (Phase 5). |
| | Delete | ➖ | **Invalidate** on the incident page | Intentional: incidents are never deleted. Duplicates are consolidated and invalidated (Phase 10). |
| **Arrests** | All | ✅ | Side menu; **New arrest**; incident page › **Record arrest**; arrest page | |
| **Flashpoints** | All | ✅ | Side menu; **New flashpoint**; blackspot suggestions; flashpoint page | |
| **Threat reports** | All | ✅ | Side menu; **New threat report**; threat page; plus status, extend and reactivate | |

### Administration

| Entity | Operation | UI | Reached from | Absence intentional? |
|---|---|---|---|---|
| **Reference data** | List, create, view, edit | ✅ | Administration › Reference data | |
| | Delete | ➖ deactivate and activate instead | List and value pages | Intentional: "Values are deactivated, never deleted." In-use values are protected by `ReferenceUsage`. |
| **Users** | List, create, import, view, edit | ✅ | Administration › Users | |
| | Delete | ➖ deactivate and reactivate instead | User page | Intentional: accounts are never deleted (audit). |

## Gaps found (all fixed in this step)

1. **No New community on `/communities`** (create is reachable only from an LGA page).
2. **No hierarchy administration**: districts, LGAs and wards cannot be created, renamed or deactivated in the UI, and state capitals can be edited only through the state profile. This was intentional until now (import-only, CLAUDE.md §5); the new prompt changes it.
3. **Wards have no list or detail page.**
4. **Political office rows on the Leadership tabs** have no Edit; editing is reachable only from the person page.

Every other detail page already showed Edit and Delete (or Deactivate) where its policy allows, hidden otherwise.

**How they were fixed** (decisions in `CLAUDE.md` §10, "Hierarchy administration"):

1. `/communities` has **New community**. It picks from the active LGAs within reach where `LgaPolicy::addCommunity` allows (`JurisdictionOptions::lgaQuery`), then opens the existing create form. With one LGA it goes straight there.
2. **Administration › Hierarchy** (`/system/hierarchy`, `hierarchy.manage`: super admins and the DG). It edits capitals, and creates, renames, deactivates and reactivates districts, LGAs and wards, with step-up and a reason. Codes never change. The import's rules apply, and the CSV import reports screen-created entries instead of deactivating them.
3. Wards have a read-only **Wards** tab on the LGA page (exporter `wards`), hidden when the LGA has none.
4. The LGA and state **Leadership** tabs show **Edit** on office rows where `PoliticalOfficePolicy::update` allows.
