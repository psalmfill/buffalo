# BUFFALO — Phase 7A: Cross-Border Dissemination of Threats and Flashpoints

Read `CLAUDE.md` before starting (access rule 8 now allows dissemination). Read `docs/handover/phase-6-summary.md` and the dissemination notes in `docs/handover/phase-6b.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 6 found that threat reports and flashpoints are visible only within their own jurisdiction plus national, so a neighbouring LGA or state never sees a threat whose area reaches it. Cross-border matches between threats and incidents are recorded but invisible to both sides.

This step adds **dissemination**: the owning state deliberately **pushes** read-only access to a threat report or flashpoint to named neighbouring states or LGAs. It differs from person access requests (Phase 3), which are **pulled** by the requester.

Phase 7 is split into:

- **7A (this step):** dissemination.
- **7B:** global search.
- **7C:** analytics.
- **7D:** reports and exports.

---

## Step 7A.1 — Disseminations table

`record_disseminations`:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `subject_type`, `subject_id` | `threat_report` or `flashpoint` only (CHECK) |
| `source_state_id`, `source_lga_id` | the subject's place at the time of dissemination |
| `target_level` | enum `state`, `lga` |
| `target_state_id` | required |
| `target_lga_id` | required when `target_level = lga`; composite FK to `lgas(id, state_id)` |
| `parent_dissemination_id` | nullable; set for onward dissemination (7A.3) |
| `reason` | text, required, ≥ 20 characters |
| `disseminated_by`, `disseminated_at` | |
| `expires_at` | `timestampTz`; threat reports: follows the threat's `expires_at` (kept in sync when extended, reactivated or expired); flashpoints: 90 days (`config/buffalo.php`), renewable |
| `acknowledged_by`, `acknowledged_at` | by a recipient |
| `revoked_by`, `revoked_at`, `revocation_reason` | |
| `timestampsTz` | |

- One active dissemination per subject and target (partial unique index).
- A target must lie outside the subject's own jurisdiction view (no disseminating a Lagos record to Lagos State as a whole). An LGA within the same state **is** a valid target (the neighbouring LGA chief cannot otherwise see it).
- Every create, acknowledge, renew, revoke and expiry is a named activity event.

## Step 7A.2 — Visibility

- Extend the existing grant resolver pattern (the `AccessGrantResolver` hook) with a `DisseminationResolver` for `ThreatReport` and `Flashpoint` **only**. A user sees a disseminated record when an active dissemination targets their state (state-level roles) or their LGA (LGA chief, plus the state roles of that LGA's state).
- **Classification stays a hard ceiling** (the classification scope is unchanged). The dissemination form warns the sender when a target's officers will not be able to see the record because of its level, and lists which roles in the target can.
- **Read-only:** recipients can view and acknowledge, nothing else. All write policies use direct jurisdiction (`canAccessOwn` / `canAccessDirectly`), as with person grants.
- **Linked records** (incidents, groups, security notes, flashpoints on a threat) remain under their own scopes: recipients see a linked record only if they could already see it. No hidden counts.
- Threat–incident matches (6B) become visible to a recipient when they can see both sides through their own scopes plus the dissemination.
- Views by recipients are logged in `record_access_logs` with context `dissemination`.
- **Map layers:** flashpoint and threat layers (uncached) include disseminated records, styled distinctly ("shared with you"). Confirm the aggregated, cached layers do not depend on threats or flashpoints; if any do, add a dissemination version to the scope signature.

## Step 7A.3 — Who can disseminate

| Action | Who |
|---|---|
| Disseminate to any state or LGA | SD and DSD of the owning state; DG; DDGs covering the owning state |
| Request dissemination | LGA chief of the owning LGA (or the creator): sends a request with suggested targets and a reason to the SD/DSD, who disseminates or declines with a note |
| Onward dissemination | SD/DSD of a **recipient state**, only to LGAs within their own state (`parent_dissemination_id` set). No onward dissemination to other states. |
| Acknowledge | Recipient LGA chief, or recipient SD/DSD |
| Renew (flashpoints) | The original disseminator's state roles, DG, DDG |
| Revoke | The disseminator, SD of the owning state, DG, DDGs covering it. Revoking a dissemination also revokes its onward disseminations. |

`dissemination_requests` table for LGA chiefs' requests: `ulid`, `subject_type`, `subject_id`, `state_id`, `lga_id`, `requested_by`, `suggested_targets` (jsonb list of state/LGA ULIDs), `reason`, `status` (`pending`, `actioned`, `declined`), `decided_by`, `decided_at`, `decision_note`, `timestampsTz`.

## Step 7A.4 — Target suggestions

When a threat report or flashpoint has a location and radius, compute suggested targets: LGAs and states (other than the owner's) whose boundary intersects the area (`ST_Intersects` with the boundary, in system context, IDs only). Without boundaries, fall back to LGAs whose centroid lies within the area plus 20 km. Show them in the dissemination form, pre-ticked for `immediate` threats.

**Prompting:**
- On creation of an `immediate` or `priority` threat, or a `high`/`severe` flashpoint, whose area crosses into another LGA or state, show the creator a prompt: "This area extends into [names]. Disseminate now?" (SD/DSD) or "Request dissemination?" (LGA chief).
- Add a daily check that notifies the SD/DSD about active `immediate`/`priority` threats and `high`/`severe` flashpoints whose areas cross borders but have **no** dissemination to the affected places (once per record).

## Step 7A.5 — Notifications

Payload: subject reference or name, kind, source state, link. Nothing else. Recipients must be able to see the record (clearance check per recipient).

| Event | Recipients | Severity |
|---|---|---|
| Disseminated to a state | SD and DSD of the target state | by urgency/concern: immediate or severe → critical; priority or high → warning; else info |
| Disseminated to an LGA | the LGA chief, and the SD/DSD of that state | as above |
| Acknowledged | the disseminator | info |
| Unacknowledged after 2 hours (immediate) or 24 hours (others) | recipients again, once | warning |
| Revoked or expired | recipients | info |
| Dissemination requested (by LGA chief) | SD and DSD of the owning state | warning |

## Step 7A.6 — UI

- On threat report and flashpoint pages (owners): a **Shared with** panel listing disseminations with status (acknowledged or not), expiry, and actions (disseminate, renew, revoke); suggested targets; the LGA chief's **Request dissemination** button.
- For recipients: a banner "Shared by [State/LGA] on [date] — read-only. Reason: …", with **Acknowledge**.
- **Inbox:** `/disseminations` with tabs "Shared with us" (default filter: unacknowledged first) and "Shared by us", plus "Dissemination requests" for SD/DSD. Navigation item under "Threats & flashpoints" with a badge for unacknowledged items.
- Threat and flashpoint lists gain a "Shared with us" filter and a distinct marker style.
- Dashboards: recipients see "Shared with us, unacknowledged" (state and LGA); national sees cross-border threats without dissemination.

Run `make wayfinder` after route changes.

## Step 7A.7 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `disseminations.view` (inbox, acknowledge) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `disseminations.create` (disseminate, renew, onward) | ✓ | ✓ | ✓ | ✓ | |
| `disseminations.request` | | | | | ✓ |
| `disseminations.revoke` | ✓ | ✓ | ✓ | | |

Always combined with the "who" rules in 7A.3. Update the matrix, drift tests and `auth.ts`.

## Step 7A.8 — Tests

- Visibility matrix: state-target and LGA-target disseminations, per recipient role; non-targets see nothing; expiry and revocation remove access immediately (and broadcast an end event like 4A.0's `AccessGrantEnded` so open pages close).
- Classification ceiling: a `secret` threat disseminated to an LGA stays invisible to its LGA chief; the sender sees the warning.
- Read-only: every write route denies recipients.
- Linked records stay under their own scopes; no hidden counts; matches visible only when both sides are.
- Onward dissemination only within the recipient's own state; revoking the parent revokes children.
- Threat expiry changes update dissemination expiry.
- Target suggestions with boundaries and with centroids only.
- Notifications and reminders: recipients, severities, once-only rules, payload contents.
- Views logged with context `dissemination`.
- Architecture: only `ThreatReport` and `Flashpoint` use `DisseminationResolver`.

## Acceptance checklist

- [ ] A Lagos `immediate` threat whose radius crosses into a neighbouring demo LGA prompts the Lagos State Director to disseminate; the neighbouring LGA's chief gets a critical notification and sees the threat read-only with the banner. (Demo data covers only Lagos and Kano, so test cross-state dissemination by targeting Kano manually.)
- [ ] The Lagos threat is also disseminated to Kano State; the Kano State Director forwards it to one Kano LGA; Lagos revokes the Kano dissemination and both Kano recipients lose access instantly.
- [ ] A Lagos LGA chief requests dissemination of a flashpoint; the State Director actions it.
- [ ] A `secret` threat cannot be seen by a recipient LGA chief even when disseminated.
- [ ] `make test` and `make lint` pass.

## Handover

Write `docs/handover/phase-7a.md` per CLAUDE.md section 9 and print it.
