# BUFFALO — Phase 10B: Consolidating Duplicate Incidents and Reassigning Political Offices

Read `CLAUDE.md` before starting (the "Merging (Phase 10)" entry). Read `docs/handover/phase-10a.md` and, for the incident model, `docs/handover/phase-5-summary.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 5C lets a verifier invalidate an incident as a `duplicate` of another, which locks it. But anything useful recorded only on the duplicate (a photo, a witness, a responding agency, a follow-up) stays stranded on a locked record. This step lets the verifier **consolidate** that content into the original as part of invalidating the duplicate, in one transaction.

Incidents are **not merged** like persons (CLAUDE.md): the duplicate remains as an invalid, locked record with its own reference number and history, so the audit trail of what was reported, and when, is preserved.

This step also adds the political office reassignment carried over since Phase 3.

---

## Step 10B.1 — Consolidation rules

| Data on the duplicate | Rule |
|---|---|
| Evidence | **Moved** to the original (the evidence row's `incident_id`, `state_id` and `lga_id` change; the stored file, SHA-256, custody history and ULID are unchanged). A custody event `consolidated` records the move (from and to references). If the original's classification is higher than the evidence's, the evidence is raised to match (logged). |
| Follow-ups (`incident_updates`) | **Copied**, never moved (they are append-only): each becomes a new follow-up on the original, with the same type, body and classification, the original author and time shown as "Originally recorded on BUF/… by [name] at [time]", and the consolidating officer as creator. The originals stay on the duplicate. |
| Persons involved | Added to the original when not already linked with the same involvement (same `person_id`; unidentified persons are always added, with "(from BUF/…)" appended to the description). Person links require `linkToIncident` as in Phase 5. |
| Criminal groups, agencies, property | Added when not already present (same group; same agency and formation; same property type and site); otherwise skipped. Notes from skipped rows are appended to the existing row's notes, labelled. |
| Casualties | **Never summed or moved automatically.** The consolidation screen shows both incidents' casualty tables side by side; the verifier may edit the original's figures (an ordinary amendment with a reason), and the duplicate's figures stay on the duplicate. Since invalid incidents are excluded from statistics, nothing is double-counted. |
| Type-specific details, description, location, time | Not changed. The verifier may amend the original separately if the duplicate had better information. |
| Flashpoint and threat links | Added to the original where the user can see the flashpoint or threat and manage the link; otherwise left. |
| Access requests citing the duplicate | Repointed to the original. |

Every moved, copied, added or skipped item is recorded in an `incident_consolidations` row: `ulid`, `original_incident_id`, `duplicate_incident_id`, `state_id`, `lga_id` (the original's place), `manifest` (jsonb), `consolidated_by`, `consolidated_at`; append-only and hash-chained. Consolidation is **not undoable** (the duplicate is locked afterwards); the preview says so.

## Step 10B.2 — Action and locks

Extend the 5C **invalidate** action:

- When the reason is `duplicate`, the dialog offers **Consolidate into the original** (default on), opening the consolidation preview.
- Conditions: the user may invalidate the duplicate (5C rules) **and** may update or amend the original (5C editing rules, including the amendment reason when the original is verified); the original is not closed or invalid; both are within the user's own jurisdiction and clearance; every item above the user's clearance on the duplicate is listed as "not consolidated (above your clearance)" and left in place.
- Order inside **one transaction**: lock both incidents (ID order), apply consolidation, write `incident_consolidations`, then invalidate the duplicate (which triggers the lock). If anything fails, nothing changes.
- Named activity events on both incidents; the timeline of the original shows "Content consolidated from BUF/…".
- Notifications: the duplicate's reporting officer (as for any invalidation) and the original's reporting officer ("Information from another report was added to your incident"), payloads as in 5C.
- The Phase 5C duplicate detection ("Possible duplicates") links straight to this flow.

## Step 10B.3 — Political office reassignment

Correcting a political office recorded against the wrong person was impossible since Phase 3.

- `ReassignPoliticalOffice` action (SD, DG, DDG; step-up not required; reason ≥ 20 characters): changes `person_id` on an office to another person the user can see in their own jurisdiction. The new holder gains the `political_leader` role if missing; the old holder's role is deactivated if they hold no other office.
- Named activity event with the old and new holder IDs and the reason; both persons' histories show it.
- Distinct from ending an office (`ended_on`) and recording a new holder, which remains the correct action when the office actually changed hands. The dialog explains the difference.
- Permission `political.reassign` for DG, DDG, SD. Update the matrix, drift tests, `auth.ts` and the route inventory.

## Step 10B.4 — UI

- **Consolidation preview** inside the invalidate dialog: two columns (duplicate, original); each item with its rule outcome (move, copy, add, skip, not consolidated); the casualty comparison with a link to amend the original; the irreversibility notice; confirm.
- Original incident's Evidence tab marks consolidated items "From BUF/…"; its timeline shows copied follow-ups with their original author and time.
- Political office row action **Reassign holder** with a person picker and reason.

Run `make wayfinder` after route changes.

## Step 10B.5 — Tests

- Each rule in 10B.1, including: evidence hash and custody intact after the move, `consolidated` custody event, classification raised when needed; follow-ups copied with attribution and originals unchanged; unidentified persons always added; skipped rows' notes appended; casualties untouched; access requests repointed.
- Items above the user's clearance are left in place and listed.
- All-or-nothing: a failure part-way leaves both incidents unchanged and the duplicate not invalidated.
- Authorisation: invalidate rights on the duplicate plus update or amend rights on the original; closed or invalid originals refused; amendment reason required when the original is verified.
- Statistics: after consolidation, totals count the original only (duplicate is invalid).
- `incident_consolidations` append-only and hash-chained.
- Office reassignment: permission, own-jurisdiction persons, role changes, activity event, reason length.

## Phase 10 (merging) completion checklist

- [ ] Two reports of the same kidnapping: the duplicate has a photo, a witness and a follow-up. The State Director invalidates it as a duplicate with consolidation; the original now has the photo (same hash), the witness and the copied follow-up with its original author; the duplicate is invalid and locked; the dashboard counts one incident.
- [ ] Items above the consolidator's clearance are left in place and listed (covered by tests with a lowered-clearance fixture: every role that can invalidate currently has `secret` clearance, so this cannot be shown in the browser today).
- [ ] A senator recorded against the wrong person is reassigned with a reason, and both persons' histories show it.
- [ ] Person merging from 10A still passes its acceptance checks.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-10b.md` per CLAUDE.md section 9, and `docs/handover/phase-10-merging-summary.md` summarising person merging, incident consolidation and office reassignment, the reference inventory, and open issues. Print both.
