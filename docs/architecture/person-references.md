# References to persons (Phase 10A.1)

Every place in the schema that points at a `persons` row, and what a merge
does with it. The source of truth in code is
`App\Support\Persons\Merge\PersonReferenceRegistry`;
`tests/Feature/Persons/Merge/PersonReferenceRegistryTest.php` fails when a
foreign key to `persons`, or a polymorphic `*_type`/`*_id` pair, exists in
the schema but is not registered. **A new module that references persons
must add itself to the registry and give its rows a merge rule (and an undo
path) in `MergePersons` and `UndoPersonMerge`.**

Terms: the **survivor** remains; the **source** is merged into it and
soft-deleted with `merged_into_person_id`.

## Foreign keys to `persons.id`

All are `ON DELETE RESTRICT` (persons are never hard-deleted).

| Table.column | What it is | Merge rule (10A.4) | Undo |
|---|---|---|---|
| `person_aliases.person_id` | Aliases | **Union**, deduplicated by the normalised alias (`search_alias`). Source aliases the survivor lacks are repointed; duplicates stay with the source. Names not chosen in the preview are **added** to the survivor as aliases. | Repointed rows move back; aliases added by the merge are soft-deleted. |
| `person_roles.person_id` | Roles (political, traditional, religious, community leader, POI) | **Union.** A role only on the source is repointed. A role on both: the survivor's row stays, active if active on either. | Repointed rows move back; the survivor row's `is_active` is restored. |
| `person_associations.person_id`, `.associated_person_id` | Associations (both sides) | **Repointed.** An association between the two merged records is dropped (soft-deleted). A pair that would duplicate one the survivor already has: the survivor's row is kept with the notes concatenated; the source's row is soft-deleted. | Each repointed side moves back; dropped rows are restored; the kept row's notes are restored. |
| `persons_of_interest.person_id` (also the composite `(person_id, lga_id, state_id)` → persons, `ON UPDATE CASCADE`) | POI record (one per person) | Only one has a POI row: it **moves** to the survivor (with the survivor's place). Both: the survivor's row is kept and **combined** (higher risk, status chosen, union of offence categories, offences, modus operandi and last known location combined and labelled by source reference, latest `last_sighted_at`, earliest `review_due_at`); the source's row is soft-deleted with `merged_into_poi_id`. Named activity events on both POIs. | A moved row goes back with its prior place; a combined row's prior values are restored and the source's row is restored. |
| `political_offices.person_id` | Political offices | **Repointed.** | Moved back. |
| `criminal_group_members.person_id` | Criminal group memberships | **Repointed.** Same group on both: the survivor's row is kept with the stronger status (`confirmed` > `suspected` > `former`), earliest `since`, notes concatenated; the source's row is soft-deleted. | Moved back; the kept row's prior values restored; the soft-deleted row restored. |
| `incident_persons.person_id` | Persons on incidents | **Repointed**, including rows on incidents in other jurisdictions (linked under a grant) and on **closed or invalid** incidents, which only the merge exception in `buffalo_incident_child_locked` allows (transaction-local `buffalo.person_merge` setting; only `person_id`, `notes`, `deleted_at`, `updated_at` may change). Same incident with the same involvement: the survivor's row is kept with notes concatenated; the source's row is soft-deleted. | Moved back under the same exception; kept rows' notes restored; dropped rows restored. |
| `arrest_persons.person_id` | Persons arrested (Phase 11C) | **Repointed**, including rows on arrests in other jurisdictions (linked under a grant). Both records on the same arrest: the survivor's row is kept; the source's offences the survivor lacks move to it (`arrest_person_offences.arrest_person_id`), identical person-offence rows are soft-deleted, notes are concatenated, and the source's row is soft-deleted. | Moved back; moved offences return to the source's row; dropped rows restored; the kept row's notes restored. |
| `communities.traditional_ruler_id` | Traditional ruler of a community | **Repointed.** | Moved back. |
| `religious_institutions.leader_person_id` | Leader of a religious institution | **Repointed.** | Moved back. |
| `organization_contacts.person_id` | Organization contact linked to a person record | **Repointed.** | Moved back. |
| `persons.merged_into_person_id` | Merge bookkeeping (10A.2) | Set on the source. A merged person cannot be a survivor. Redirects follow chains. | Cleared. |
| `person_merges.survivor_person_id`, `.source_person_id` | Merge record (10A.2) | Bookkeeping: written once. | `undone_*` set. |
| `person_merge_requests.person_a_id`, `.person_b_id`, `.suggested_survivor_id` | Merge requests (10A.2) | The executed request is marked `executed`; any other **pending** request involving the source is cancelled ("record merged"). | Not restored (the requester can ask again). |
| `person_duplicate_dismissals.person_a_id`, `.person_b_id` | "Not a duplicate" decisions | Bookkeeping: unchanged. | Unchanged. |

## Polymorphic references (`*_type` = `App\Models\Person`)

| Table.columns | What it is | Merge rule | Undo |
|---|---|---|---|
| `media.model_type/model_id` | Person photos (collection `photos`) | **All moved** to the survivor; the executor picks the current photo (`order_column` renumbered so it is the newest). Files are stored by media ULID, so nothing moves on disk. | Moved back with their prior `order_column`. |
| `blind_indexes.indexable_type/indexable_id` | NIN, phone, DOB blind indexes | The survivor's are **regenerated** by saving through the model (never copied as ciphertext). The source's stay with the (soft-deleted) source. | Regenerated again when the survivor's prior values are written back. |
| `record_access_grants.subject_type/subject_id` | Cross-jurisdiction grants | Active grants on the source are **revoked** (reason "record merged"), holders notified, `AccessGrantEnded` broadcast. **Never transferred.** | **Not restored.** |
| `record_access_requests.subject_type/subject_id` | Access requests | Open (pending or escalated) requests on the source are **cancelled** (decision note "record merged"), requesters notified. **Never transferred.** | **Not restored.** |
| `record_match_events.subject_type/subject_id` | Cross-jurisdiction match events | **Unchanged** (history stays on the source's ID). | Unchanged. |
| `record_access_logs.subject_type/subject_id` | Read access log | **Unchanged.** | Unchanged. |
| `activity_log.subject_type/subject_id` | Activity log | **Unchanged.** Named events `person_merged_into` (source) and `person_merged_from` (survivor) are added. The source's History stays reachable from the survivor's "Merged records". | Named events `person_merge_undone` on both. |

Polymorphic columns that never hold a person: `activity_log.causer_type`
(users), `boundary_simplified.place_type` (places),
`dissemination_gap_alerts`, `dissemination_requests` and
`record_disseminations` `.subject_type` (threats and flashpoints),
`model_has_permissions` / `model_has_roles` `.model_type` and
`notifications.notifiable_type` (users).

## Other references (JSON and text)

| Where | What | Rule |
|---|---|---|
| `notifications.data` (`url`) | Links such as `/persons/{ulid}` in earlier notifications | Unchanged. The source's URL **redirects** to the survivor for viewers who can see it, otherwise 404. |
| `activity_log.properties` | May name person ULIDs (e.g. association events) | History: unchanged. |
| `search_logs` | Query hash only | No person reference. |
| `report_runs.parameters`, `report_schedules.parameters`, `incident_saved_filters.filters`, `analytics_exports.filters` | Report and filter parameters | No person references (persons are never exported in bulk; incident filters have no person filter). |
| `persons_of_interest.offence_categories` | Offence category values | No person reference; combined as a union when both records have a POI. |
