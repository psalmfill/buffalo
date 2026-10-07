# BUFFALO — Phase 10A: Merging Duplicate Persons

Read `CLAUDE.md` before starting, especially the "Merging (Phase 10)" entry in the decisions log. Read `docs/handover/phase-9-summary.md` and, for the person model, `docs/handover/phase-3-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

The core system is complete. Duplicate person records are inevitable: two LGAs record the same suspect, an officer misses the duplicate warning, a name is spelt two ways. Each duplicate splits the intelligence picture: incidents, POI history, group memberships and associations hang off different copies.

Merging is delicate because a person record is linked from almost every module, holds encrypted fields with blind indexes, may be a person of interest, and may be the subject of cross-jurisdiction grants. This step builds merging carefully: candidates, a request and approval flow, a field-by-field preview, an atomic merge with a full manifest, and undo within 30 days.

**Rules from CLAUDE.md (summarised):**

- Merges happen only **within one state**. Cross-state duplicates are never merged.
- Executed by SD, DG or DDG, with step-up. LGA chiefs and DSDs may request.
- Two different non-null NINs: hard stop.
- The survivor takes the higher classification.
- The merged record is soft-deleted with `merged_into_person_id`, redirecting only for viewers who can see the survivor.
- Grants and open requests on the merged record are revoked or cancelled, never transferred.
- Every merge stores a manifest so it can be undone within 30 days.

**Terms:** the **survivor** is the record that remains; the **source** is the record merged into it.

---

## Step 10A.1 — Inventory of person references

Before any code, produce `docs/architecture/person-references.md`: every table and column referencing `persons.id` (foreign keys **and** polymorphic or JSON references, e.g. `subject_type/subject_id` in match events, access requests, grants, access logs, activity log), and for each one the merge rule from 10A.4 that applies. Add a test that fails if a new foreign key to `persons` appears that is not listed in a `PersonReferenceRegistry` class, so future modules cannot be forgotten.

## Step 10A.2 — Schema

- `persons`: add `merged_into_person_id` (nullable self-FK), `merged_at`, `merged_by`. A CHECK ensures it is set only on soft-deleted rows. A merged person cannot be the survivor of a later merge (merge into its survivor instead); redirects follow chains to the final survivor.
- `person_merges`: `ulid`, `state_id` (the state of both records), `survivor_person_id`, `source_person_id`, `request_id` (nullable), `field_choices` (jsonb: which value was chosen for each conflicting field, recorded as "survivor" or "source", **never the values themselves**, since several are encrypted), `manifest` (jsonb: every moved, deduplicated, revoked or cancelled row by table and ID, with the prior foreign key values needed to reverse it), `survivor_prior_values` (text, nullable: the survivor's scalar fields and place **before** the merge, serialised and encrypted with Laravel `Crypt`; needed because the activity log never stores encrypted values), `executed_by`, `executed_at`, `undone_by`, `undone_at`, `undo_reason`. Append-only except the `undone_*` columns and nulling `survivor_prior_values` (trigger), and hash-chained like other audit tables, with `survivor_prior_values` excluded from the hash so it can be erased. A daily job sets `survivor_prior_values` to null once the undo window has passed (or after an undo), so the copy of personal data does not outlive its purpose. It is never logged, never sent to the browser, and excluded from activity properties.
- `person_merge_requests`: `ulid`, `state_id`, `lga_id` (the requester's), `person_a_id`, `person_b_id`, `suggested_survivor_id`, `reason` (≥ 20 characters), `requested_by`, `status` (`pending`, `executed`, `declined`, `cancelled`), `decided_by`, `decided_at`, `decision_note`, `timestampsTz`. One pending request per pair.
- `person_duplicate_dismissals`: `person_a_id`, `person_b_id` (stored with the lower ID first), `dismissed_by`, `reason`, `created_at`. A dismissed pair never reappears as a candidate.

## Step 10A.3 — Duplicate candidates

`PersonDuplicateFinder`, running entirely through the user's **own** jurisdiction scope (`ownJurisdiction`; grants and disseminations never contribute), so it never reveals records the user does not own:

| Signal | Strength |
|---|---|
| Same NIN (blind index) | certain |
| Same phone (blind index) | strong |
| Same DOB (blind index) and name or alias similarity ≥ 0.75 | strong |
| Name or alias similarity ≥ 0.85, same LGA | possible |

- Excludes merged, soft-deleted and dismissed pairs, and pairs in different states.
- Index-backed operators only (CLAUDE.md).
- **Candidates view** (`/persons/duplicates`) for SD/DSD (their state), national users (filter by state), and LGA chiefs (their LGA only): pairs with signals and strength, sorted by strength. Actions: **Request merge** (LGA chief, DSD), **Review and merge** (SD, DG, DDG), **Not a duplicate** (with reason; SD and above).
- A nightly job counts candidate pairs per state for the SD's dashboard (counts only).

## Step 10A.4 — Merge rules per data type

| Data | Rule |
|---|---|
| Scalar fields (first, last and other names, gender, DOB, approximate age, NIN, phone, address, occupation, nationality, description, community) | Where only one record has a value, keep it. Where both differ, the executor chooses per field in the preview. **NIN conflict (two different non-null values) blocks the merge.** A DOB conflict requires an explicit confirmation. Encrypted fields are written through the model so ciphertext and blind indexes are regenerated, never copied as raw ciphertext. |
| Names not chosen | Added to the survivor as aliases automatically (deduplicated), so searches by the old name still find the person. |
| Ownership (`state_id`, `lga_id`) | The survivor's, unless the executor chooses the source's place (same state only). |
| Classification | The higher of the two. |
| `is_deceased` | True if either is true; confirmation required. |
| Photos (media) | All moved to the survivor; the executor picks the current photo. |
| Roles | Union; active if active on either. |
| Aliases | Union, deduplicated by normalised alias. |
| Associations | Repointed to the survivor. An association between the two merged records is dropped (recorded in the manifest). Duplicate pairs after repointing: keep one, concatenate notes. |
| Persons of interest | If only one has a POI row, it moves to the survivor. If both do: keep the survivor's row; risk level = higher; status chosen by the executor; offence categories = union; known offences, modus operandi and last known location combined (labelled by source reference), latest `last_sighted_at`, earliest `review_due_at`; the source POI row is soft-deleted with a link to the survivor's. Named activity events on the POI. |
| Political offices | Repointed to the survivor. |
| Criminal group memberships | Repointed; where both are members of the same group, keep one row with the stronger status (`confirmed` > `suspected` > `former`), earliest `since`, concatenated notes. |
| Incident persons | Repointed. Where both are linked to the same incident with the same involvement, keep one row (concatenate notes). Incidents that are **closed or invalid** are locked: the repointing of their `incident_persons` rows is allowed **only** through a narrowly scoped, documented exception in the lock trigger for merges (a session setting set by the merge action), tested. |
| Traditional ruler, religious leader, organization contact links | Repointed. |
| Cross-jurisdiction grants on the source | Revoked (reason "record merged"), holders notified, live `AccessGrantEnded` broadcast. **Never transferred.** |
| Open access requests on the source | Cancelled (reason "record merged"), requesters notified. |
| Match events, access logs, activity log, search logs | **Unchanged.** History stays attached to the source's ID. The source's History tab remains reachable from the survivor's page ("Merged records"). |

Any other reference found in the 10A.1 inventory must be given an explicit rule; do not silently skip anything.

## Step 10A.5 — Merge action

`MergePersons` action:

1. Authorise: executor holds `persons.merge`; both records are in the executor's **own** jurisdiction (`canAccessOwn`), in the same state, within the executor's clearance; neither is merged or deleted; step-up confirmed.
2. Take row locks on both persons (in ID order) and an advisory lock per person ID, so concurrent merges or edits cannot interleave.
3. Validate field choices (NIN hard stop, DOB confirmation).
4. Apply every rule in 10A.4 in **one transaction**, building the manifest as it goes.
5. Soft-delete the source with `merged_into_person_id`; write `person_merges`; named activity events `person_merged_into` (source) and `person_merged_from` (survivor) with the merge ULID; mark any related merge request `executed`.
6. Notify (payloads: survivor link only; no names): the LGA chief(s) of both records' LGAs and the SD; revoked grant holders; cancelled requesters.

**Visibility side-effect:** when the two records were owned by different LGAs, officers of the LGA that loses ownership no longer see the person's details in their own incidents (the Phase 5 display rule shows "Person on record (not visible to you)"). The preview must state this explicitly with the number of affected incidents per LGA, and the executor may choose which LGA owns the survivor.

## Step 10A.6 — Undo

`UndoPersonMerge` (SD, DG, DDG; step-up; reason required), allowed within 30 days (`config/buffalo.php` → `merges.undo_days`) while neither person has been merged again:

- Restore the source (clear soft delete and `merged_*`; its own fields were never changed by the merge, only soft-deleted). Restore the **survivor's** scalar fields and place from `survivor_prior_values` (decrypted, written through the model so ciphertext and blind indexes are regenerated). Move back **exactly** the rows listed in the manifest, recreating dropped duplicate rows from the manifest. If the survivor's fields were edited after the merge, show those edits in the undo dialog and let the executor keep or discard each one.
- Rows created on the survivor **after** the merge stay with the survivor.
- Revoked grants and cancelled requests are **not** restored (they were cancelled with notice; requesters can request again). Say so in the undo dialog.
- Aliases added from the source's names are removed from the survivor if they were added by the merge.
- Mark `person_merges.undone_*`; named activity events on both; notify the same recipients as the merge.
- If any manifest row can no longer be moved back (for example, hard constraint conflicts), abort the whole undo with a clear explanation; never partially undo.

## Step 10A.7 — UI

- **Review and merge** page (`/persons/merge?a=…&b=…`): side-by-side comparison of every field, with radio choices for conflicts, conflicts highlighted, the NIN hard stop and DOB confirmation shown clearly; counts of what will move (aliases, photos, roles, associations, offices, memberships, incidents, POI details); grants and requests to be revoked or cancelled; the visibility side-effect warning with affected incident counts; the survivor's resulting classification; a choice of survivor (swap button) and of owning LGA.
- **Merge request** dialog for LGA chiefs and DSDs from the candidates view or a person page ("This may be the same person as…", with a person picker limited to their own jurisdiction).
- **Merge requests** list for SD/DSD and national users, with the same review page for execution and a decline note.
- Survivor's person page: a "Merged records" section listing merges (source reference, date, executor) with **Undo** (within the window) and a link to each source's history.
- Visiting a merged record's URL redirects to the survivor with a notice, or 404s for viewers who cannot see the survivor.

Run `make wayfinder` after route changes.

## Step 10A.8 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `persons.duplicates.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `persons.merge_request` | | | | ✓ | ✓ |
| `persons.merge` (execute, undo, dismiss) | ✓ | ✓ | ✓ | | |

Super admin: none. Update the matrix, drift tests and `auth.ts`, and regenerate the route inventory (8B).

## Step 10A.9 — Tests

- Reference inventory test: a new FK to `persons` not registered fails.
- Candidate finder: each signal; own jurisdiction only (never through grants or disseminations); cross-state pairs excluded; dismissals respected; index use (`EXPLAIN` in the handover).
- Authorisation: who can request, execute, undo, dismiss; both records must be own-jurisdiction, same state, within clearance; step-up required.
- Every rule in 10A.4, one test each, including: NIN hard stop; DOB confirmation; encrypted fields re-encrypted and blind index searches find the survivor by the source's NIN/phone; losing names become aliases; higher classification; both-POI combination; same-group membership deduplication; same-incident person deduplication; locked-incident exception scoped to the merge action only (a normal edit of a closed incident's persons is still rejected).
- Grants on the source revoked with live end events; open requests cancelled; nothing transferred.
- History stays on the source ID and remains reachable.
- Concurrency: two simultaneous merges involving the same person cannot both succeed.
- `survivor_prior_values` is encrypted at rest, absent from logs, props and activity properties, excluded from the hash chain, and erased after the window or after undo.
- Manifest completeness: after merge then undo on a rich fixture (POI, offices, memberships, associations, incidents including a closed one, photos, contact link, traditional ruler link), every row is back where it was, except rows created after the merge.
- Undo window, re-merge block, and all-or-nothing abort.
- Redirect for viewers who can see the survivor; 404 otherwise; chains of merges redirect to the final survivor.
- Notifications contain no names.

## Acceptance checklist

- [ ] The candidates view in a demo state lists a planted NIN duplicate as certain and a misspelt-name pair with the same DOB as strong.
- [ ] An LGA chief requests a merge; the State Director reviews, chooses fields, sees the visibility warning, confirms with TOTP, and the merge completes.
- [ ] Searching by the source's old name and NIN finds the survivor; the source's URL redirects.
- [ ] A Kano officer's active grant on the source is revoked and their open page closes.
- [ ] Undoing the merge restores both records exactly.
- [ ] A pair with two different NINs cannot be merged.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-10a.md` per CLAUDE.md section 9 and print it.
