# Phase 10 (merging) summary

Phase 10 so far has delivered the "merging" extension: merging duplicate
persons (10A), consolidating duplicate incidents (10B) and correcting
political office holders (10B). Details: [`phase-10a.md`](phase-10a.md) and
[`phase-10b.md`](phase-10b.md). The other optional extensions listed in the
Phase 9 summary (offline PWA, mobile app, integrations, passkeys) are not
started.

## 1. What was delivered

| Area | Delivered |
|---|---|
| Duplicate persons | **Duplicate persons** list, own jurisdiction only. Signals: same NIN (certain), same phone or same DOB with a similar name (strong), near-identical names in one LGA (possible). Pairs can be marked **Not a duplicate**. A nightly count goes to the State Director's dashboard. |
| Merge requests | LGA chiefs and Deputy State Directors request merges; State Directors, the DG and DDGs execute or decline them. |
| Person merge | Review page side by side: NIN conflicts block; DOB and deceased differences need confirmation; the owning LGA and its visibility warning; step-up. One transaction under advisory and row locks, with every rule in the reference inventory. Grants and open requests on the merged record end ("record merged") and are never transferred; the merged record's URL redirects to the survivor for viewers who can see it. |
| Undo | 30 days, all or nothing, from the survivor's **Merged records**. Exact reversal of the manifest; the survivor's prior values are kept encrypted and erased after the window. |
| Incident consolidation | Inside **Invalidate → Duplicate**: evidence moved (hash and custody intact), follow-ups copied with attribution, persons, groups, agencies, property and links added, access requests repointed, casualties never summed. Not undoable; the duplicate stays invalid and locked with its own reference. |
| Office holders | **Reassign holder** corrects an office recorded against the wrong person, with a reason, role changes and events on both persons. |

## 2. Rules (CLAUDE.md "Merging (Phase 10)")

- **Persons:**
  - merged only within one state, by SD, DG or DDG, with step-up;
  - two different NINs are a hard stop;
  - the higher classification wins;
  - grants and requests are revoked or cancelled, never transferred;
  - audit history stays on the original IDs;
  - every merge has a manifest and can be undone for 30 days.
- **Incidents:**
  - never merged; content is consolidated into the original and the
    duplicate is invalidated in the same transaction;
  - casualty figures are never summed;
  - consolidation is final.

## 3. The reference inventory

`docs/architecture/person-references.md` and
`App\Support\Persons\Merge\PersonReferenceRegistry` list every foreign key
and polymorphic reference to `persons` with its merge rule.
`PersonReferenceRegistryTest` fails when the schema gains an unregistered
reference, so a future module that links persons must decide what a merge
(and an undo) does with its rows.

Incident children have no registry. Their rules live in
`App\Support\Incidents\Consolidation`, whose docblock is the list; a new
incident child table must be added there.

## 4. Audit and integrity

- **New audit tables:**
  - `person_merges`: append-only except the undo columns, set once; its
    encrypted prior values are erasable;
  - `incident_consolidations`: append-only; its place follows the
    original.

  Both are hash-chained and verified nightly by `buffalo:audit:verify`.
- **Named events:**
  - persons: `person_merged_into` / `person_merged_from`,
    `person_merge_undone`, `duplicate_dismissed`,
    `person_merge_requested` / `person_merge_declined`,
    `political_office_reassigned`;
  - POIs: `poi_combined` / `poi_merged_into`;
  - incidents: `consolidated_from` / `consolidated_into`;
  - evidence custody: `consolidated`.
- **Locked incidents:** two exceptions, both tested.
  - `incident_persons` may be repointed by a person merge or its undo
    (transaction-local `buffalo.person_merge`; person, notes and deletion
    only).
  - The general lock now also refuses moving a row off a closed or invalid
    incident. That was a pre-existing gap, fixed in 10A.

## 5. Verification

- `make test` (1,693 Pest tests, 14 Vitest), `make lint` and `make ci`
  pass.
- **Acceptance on a fresh UAT environment** (production images,
  `APP_ENV=uat`):
  - 10A: all 6 checklist items (9 scripted steps), run at the end of 10A
    and again after 10B;
  - 10B: the kidnapping consolidation and the senator reassignment (see the
    10B handover).

## 6. Open issues

- **Common names:** in a big LGA they make many "possible" pairs. The list
  is capped at the strongest 200; **Not a duplicate** clears known
  different people. Thresholds are in `config/buffalo.php` (`merges.*`).
- **Duplicate-candidate performance** was measured on synthetic data (1.9 s
  for 20,000 persons in one LGA, the worst case). Re-measure on the real
  server with real names.
- **Open grant pages** close through the existing live event. That was
  verified by tests and by the grant's revocation and 404 in the
  acceptance run, not by watching a browser.
- **Clearance-limited consolidation** cannot be shown in the browser today,
  because every role that can invalidate has secret clearance; it is
  covered by tests with a lowered-clearance fixture.
- **Not built:**
  - merging of persons of interest on their own (they follow their person);
  - merging criminal groups or organizations;
  - undoing a consolidation.

  None was requested.
- **Carried over from Phase 9:** the real owner reference data and the
  production release signing key are still to be provided.
