# Phase 7A handover — Cross-border dissemination of threats and flashpoints

Date: 2026-10-01

## What was built

### 7A.1 Tables (`2026_10_13_000001_create_dissemination_tables.php`)

**`record_disseminations`**, with the columns in the prompt plus `reminded_at` and `expiry_notified_at` (once-only bookkeeping).

| Constraint | Rule |
|---|---|
| `subject_type` | `threat_report` or `flashpoint` |
| `target_level` | `state` or `lga` |
| Target LGA | `target_lga_id` is set exactly when the level is `lga` |
| Reason | at least 20 characters |
| Places | composite FKs `(source_lga_id, source_state_id)` and `(target_lga_id, target_state_id)` → `lgas(id, state_id)` |
| One per target | partial unique index on (subject, level, target) `WHERE revoked_at IS NULL` |

- **Validity:** a target must lie outside the record's own jurisdiction view: never the owner's state as a whole, nor its own LGA. Another LGA of the same state is valid.
- **Audit:** every disseminate, acknowledge, renew, revoke and expiry is a named audit event (`disseminated`, `acknowledged`, `renewed`, `revoked`, `expired`) on the dissemination row.

**`dissemination_requests`:** as specified, plus:
- CHECKs: decline needs a note; reason at least 20 characters;
- one pending request per record and requester;
- jurisdiction-scoped on the record's place.

**`dissemination_gap_alerts`:** records the daily cross-border check has reported, once each.

### 7A.2 Visibility

**`DisseminationResolver`**
- Lets a disseminated record through the jurisdiction scope while a dissemination is active, for:
  - SD/DSD of the target state: state- and LGA-level targets in it, for records of other states;
  - the chief of a target LGA.
- Acts only on models implementing the new `Disseminable` contract: `ThreatReport` and `Flashpoint`, enforced by an architecture test.
- It is wired in through `CombinedAccessResolver`, which is now the bound `AccessGrantResolver`. That keeps the existing hook and person grants unchanged.
- The classification scope is untouched.

**Read-only**
- Only `view` on `ThreatReportPolicy` and `FlashpointPolicy` uses `JurisdictionGate::canAccess`. Every write ability stays on `canAccessOwn`.
- Recipients see no History tab, and every write route returns 403 (tested).

**Linked records and matches**
- Link rows sit on the owner's place. For a viewer who sees the record only through a dissemination, `DisseminatedLinks` (sanctioned, in `App\Support\Jurisdiction`) lifts the jurisdiction scope from the link query only.
- The link models' "both sides visible" scope still applies, so a linked record appears only if the viewer could already see it, and no counts include hidden records.
- Flashpoint assessments are read the same way.
- Threat–incident matches appear when both sides are visible: this follows automatically from the scopes.

**Own-only queries** use the new `ownJurisdiction($user)` scope:
- the flashpoint placeholder count;
- the flashpoint picker;
- the threat-to-flashpoint link target (new `FlashpointPolicy::link`).

A shared flashpoint is therefore never offered as a link target and never counted as the viewer's own.

**Logging:** recipients' views are always logged in `record_access_logs` with context `dissemination`, even for `restricted` records.

**Map**
- The flashpoint and threat point layers (not cached) include shared records with `shared: true`, drawn with a teal outline. A legend note was added.
- The aggregated, cached layers (density, choropleths) use incidents only, so the scope signature is unchanged.

### 7A.3 Who can act (`DisseminationPolicy`)

| Action | Rule implemented |
|---|---|
| `disseminate` | `disseminations.create` + SD/DSD/DG/DDG who hold the record in their own jurisdiction and clearance |
| `request` | `disseminations.request` + LGA chief of the owning LGA (or the record's creator) |
| `onward` | `disseminations.create` + SD/DSD whose state is the parent's target state; LGA targets of that state only; ends with its parent |
| `acknowledge` | `disseminations.view` + a recipient (not an owner), once |
| `renew` | flashpoints; SD/DSD of the disseminating state (the owner, or the forwarding state for onward ones), DG, DDGs covering the owner |
| `revoke` | `disseminations.revoke` + the disseminator, the owning SD, DG or DDGs covering it; cascades to onward disseminations |
| `decideRequest` | anyone who may `disseminate` the record |

**Gates:** `disseminate`, `request` and `decideRequest` are Gate abilities that take the record. `RecordDissemination` has its own policy.

**Not found:** a `{dissemination}` whose record the viewer cannot see returns 404.

### 7A.4 Target suggestions and prompts

**`DisseminationTargets`** (sanctioned; ids and the record's location only):
- LGAs other than the record's own whose boundary intersects the area (`ST_DWithin` with the radius, GIST index);
- without a boundary, LGAs whose centroid lies within the radius plus `centroid_margin_meters` (20 km);
- states other than the owner's whose boundary intersects the area, or that contain such an LGA.

`uncovered()` drops places an active dissemination already reaches.

**Prompts:**
- Suggestions are pre-ticked for `immediate` threats.
- After creating an active `immediate`/`priority` threat, or an active or monitoring `high`/`severe` flashpoint, whose area reaches uncovered places, the creator is shown a prompt, once:
  - "This area extends into [names]. Disseminate now?" (SD/DSD);
  - "Request dissemination?" (LGA chief).
- `buffalo:check-dissemination-gaps` (daily, 07:20) notifies the owning SD/DSD once per record.

### 7A.5 Notifications (`DisseminationNotification`)

- **Payload:** the record's reference or name, its kind, the source state (plus the acknowledging place for `acknowledged`), and a link. Nothing else (tested).
- **Recipient check:** every recipient is checked individually. While access lasts they must be able to `view` the record (clearance included). After it ends, clearance alone is checked.

| Event | Recipients | Severity |
|---|---|---|
| Disseminated to a state | SD, DSD of the target state (not owners) | by the record's urgency or concern |
| Disseminated to an LGA | the LGA chief, plus the SD/DSD of that state (not owners); onward: the LGA chief only | as above |
| Acknowledged | the disseminator | info |
| Not acknowledged (2 h immediate, 24 h others) | recipients, once | warning |
| Revoked or expired | recipients (by clearance) | info |
| Requested | SD, DSD of the owning state | warning |
| Request declined | the requester | info |
| Cross-border gap | SD, DSD of the owning state, once | warning |

Severity "by urgency or concern":
- immediate threat or severe flashpoint: critical;
- priority threat or high flashpoint: warning;
- otherwise: info.

**`DisseminationEnded`** (`users.{ulid}`, event `dissemination.ended`, ULID only) is broadcast to everyone the dissemination reached when it is revoked or expires. Pages open through it close at once.

### 7A.6 UI

- **Owners (threat and flashpoint pages):**
  - a "Shared with" panel: status (acknowledged or not), expiry, disseminator, reason, Renew and Revoke (with a note);
  - the places the area reaches;
  - pending requests (Disseminate pre-filled from the request, or Decline);
  - the LGA chief's "Request dissemination" button.
- **Dissemination dialog:**
  - suggested places as checkboxes, and any state or LGA added by hand;
  - a classification warning naming which officers of each target cannot see the record, and which can;
  - a reason.
- **Recipients:**
  - a banner "Shared by [State] ([LGA]) on [date] — read-only. Reason: …", with Acknowledge;
  - for recipient SD/DSD, "Forward to LGAs";
  - the page closes when the sharing ends.
- **Inbox `/disseminations`:** tabs "Shared with us" (active and unacknowledged first), "Shared by us", and "Dissemination requests" (SD/DSD).
  - Navigation: "Shared threats & flashpoints" under Threats & flashpoints, with a badge for unacknowledged items.
- **Lists:** threat and flashpoint lists gain an "Origin: Shared with us" filter and a "Shared with you" marker. Their maps add a teal ring.
- **Dashboards:**
  - state and LGA: "Shared with us, unacknowledged";
  - national: "Cross-border threats not disseminated".

### 7A.7 Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `disseminations.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `disseminations.create` | ✓ | ✓ | ✓ | ✓ | |
| `disseminations.request` | | | | | ✓ |
| `disseminations.revoke` | ✓ | ✓ | ✓ | | |

Updated together: the matrix (`'disseminations'` group), `auth.ts`, `RolesAndPermissionsTest` and `navigation.test.ts`.

### 7A.8 Tests

`tests/Feature/Disseminations/DisseminationTest.php`: 21 tests.

- **Visibility:**
  - state and LGA targets per recipient role;
  - non-targets see nothing;
  - same-state LGA targets are allowed; the owner's own state and LGA are not;
  - the owner is not a recipient (no banner, inbox entry or badge).
- **Lists and map:** markers, the shared filter, map `shared`.
- **Ending access:** revocation and expiry remove access at once, broadcast `DisseminationEnded`, notify, and write audit events; renewing restores access.
- **Classification ceiling:**
  - a `secret` threat sent to an LGA stays invisible to its chief and DSD, while its SD sees it;
  - no notification and no inbox row for those who cannot see it;
  - the sender's audience warning is correct.
- **Read-only:** every write route returns 403 for recipients; a shared flashpoint is not a link target (policy and picker).
- **Links and matches:** linked records stay under their own scopes; matches appear only once both sides are visible.
- **Onward:**
  - only to the recipient state's own LGAs;
  - other states cannot forward (404);
  - only the target LGA chief is notified;
  - revoking the parent revokes the children and closes their pages.
- **Expiry sync:** threat expiry changes (extend, expire, reactivate) update dissemination expiry.
- **Suggestions:** with centroids only, and with boundaries; `uncovered()`.
- **Prompts:** SD/DSD and LGA chief variants, shown once.
- **Requests:** request, action (request marked `actioned`), decline (requester told).
- **Notifications:** recipients, severities (critical, warning, info) and payload content; the reminder is sent once, after 2 h or 24 h, and not when acknowledged.
- **Daily gap check:** once per record; shown on the national dashboard.
- **Inbox and dashboards:** ordering, badge counts, "Shared by us" scoping.
- **Audit and logging:** named audit events; views logged with context `dissemination`.
- **Permissions:** the "who" rules (DSD may not revoke; another state's SD and an uncovered DDG get 404; super admin is forbidden).
- **Architecture:** only `ThreatReport` and `Flashpoint` implement `Disseminable`; the resolver adds nothing for other models.

## Acceptance checklist (browser, dev)

- [x] **Neighbouring-LGA prompt and banner.** The Lagos SD recorded THR/LA/2026/00004 (immediate) in LA-1-2 at 6.37 N, 3.235 E with a 3 km radius.
  - The page opened with "Disseminate now? This area extends into Demo LGA LA-1-1 LGA, Lagos", with the place pre-ticked.
  - After sharing, both LA-1-1 chiefs received a **critical** "Shared with you" notification; the Lagos SD and DSD received none.
  - chief.lagos saw the threat read-only (no actions, no History tab) under the banner, and acknowledged it. The badge cleared, and the SD's panel shows "Acknowledged".
- [x] **Kano, forwarding and revocation.** The Lagos SD also shared THR/LA/2026/00004 with Kano State.
  - The Kano SD saw it with the banner and forwarded it to KN-1-1; the Kano LGA chief could open it.
  - With the Kano SD's page open, the Lagos SD revoked the Kano dissemination.
  - The Kano SD's page closed at once (redirect to the inbox).
  - The forwarded dissemination was revoked with "The dissemination it was forwarded from was revoked."
  - The Kano LGA chief now gets 404.
- [x] **LGA chief's request.** chief.lagos requested dissemination of the "Toll gate kidnapping corridor" blackspot to LA-1-2. The SD saw it under Dissemination requests and on the record, and actioned it with the targets pre-filled. The request shows "Actioned", and the blackspot is shared with LA-1-2 until 30 Dec 2026.
- [x] **Secret threat.** The Lagos SD created secret threat THR/LA/2026/00005 and shared it with LA-1-1.
  - The form warned that "the LGA Security Chief and Deputy State Director are not cleared for its classification. Only the State Director can see it."
  - chief.lagos gets 404; their inbox lists only THR/LA/2026/00004; no notification was sent.
- [x] **Checks:** `make lint`, `make test` and `make ci` pass: 1332 Pest tests (9495 assertions) plus Vitest.

## Files

### Created

- **Database:** `database/migrations/2026_10_13_000001_create_dissemination_tables.php`
- **Enums:** `app/Enums/{DisseminationTargetLevel, DisseminationRequestStatus}.php`
- **Models:** `app/Models/{RecordDissemination, DisseminationRequest}.php`, `app/Models/Concerns/IsDisseminable.php`
- **Jurisdiction support:**
  - `app/Support/Jurisdiction/Contracts/Disseminable.php`
  - `app/Support/Jurisdiction/{DisseminationResolver, CombinedAccessResolver, DisseminatedLinks, DisseminationSubjects, DisseminationTargets}.php`
- **Other support:**
  - `app/Support/Disseminations/DisseminationNotifier.php`
  - `app/Support/Dashboard/DisseminationFigures.php`
- **Actions:** `app/Actions/Disseminations/{DisseminateRecord, AcknowledgeDissemination, RenewDissemination, RevokeDissemination, ProcessDisseminations, SyncThreatDisseminations, RequestDissemination, DeclineDisseminationRequest, CheckDisseminationGaps}.php`
- **Commands:** `app/Console/Commands/{ProcessDisseminations, CheckDisseminationGaps}.php`
- **Events and notifications:** `app/Events/DisseminationEnded.php`, `app/Notifications/DisseminationNotification.php`
- **Policy and HTTP:**
  - `app/Policies/DisseminationPolicy.php`
  - `app/Http/Controllers/Disseminations/DisseminationController.php`
  - `app/Http/Presenters/DisseminationPresenter.php`
  - `app/Http/Requests/Disseminations/{DisseminateRequest, DecisionNoteRequest}.php`
  - `routes/disseminations.php`
- **Frontend:**
  - `resources/js/types/disseminations.ts`
  - `resources/js/components/disseminations/{disseminate-dialog, sharing-panel, shared-banner, dissemination-prompt, dissemination-ended-watcher, note-dialog, status-badge, shared-marker}.tsx`
  - `resources/js/components/dashboard/dissemination-figures.tsx`
  - `resources/js/pages/disseminations/index.tsx`
- **Tests:** `tests/Feature/Disseminations/DisseminationTest.php`
- **Docs:** `docs/prompts/phase-7a.md`

### Changed

- **Models:** `ThreatReport`, `Flashpoint` (`Disseminable`, `IsDisseminable`)
- **Policies:**
  - `ThreatReportPolicy` (`view` through dissemination; `linkFlashpoint` needs `link`)
  - `FlashpointPolicy` (`view`; new `link`)
- **Jurisdiction and classification:**
  - `JurisdictionScope` (`applyOwn`)
  - `ClassificationGate` (`maxLevelForRole`)
  - `AppServiceProvider` (binding, gates, policy)
- **Controllers:**
  - `ThreatReportController` and `FlashpointController` (banner, panel, prompt, shared filter and markers, logging context, history hidden for recipients)
  - `PickerController` (own flashpoints only)
- **Support:**
  - `ThreatLinks`, `FlashpointLinks` (`DisseminatedLinks`)
  - `PointLayers` (`shared`)
  - `PlaceholderCards` (own flashpoints)
  - the three dashboard builders
- **Other back end:**
  - `SetThreatExpiry` (syncs dissemination expiry)
  - `HandleInertiaRequests` (`badges.disseminations`)
  - `config/buffalo.php` (`disseminations`)
  - `routes/console.php`, `routes/web.php`
  - `Permission`, `RolesAndPermissionsSeeder`
- **Frontend:**
  - pages: threats and flashpoints `show` and `index`; the dashboard pages; `map/index.tsx`
  - map: `BuffaloMap` (`shared` ring, `SHARED_COLOUR`), `operational-layers.ts`, `threat-display.tsx`, `flashpoint-areas.ts`
  - navigation: `navigation.ts`, `nav-main.tsx`
  - types: `types/{dashboard, threats, flashpoints, global.d}.ts`, `auth.ts`
- **Tests:** `RolesAndPermissionsTest`, `navigation.test.ts`
- **Docs:** `README.md`

## Deviations and decisions

1. **Resolver composition.** The prompt says to extend the `AccessGrantResolver` hook. The container held a single resolver, so a `CombinedAccessResolver` now delegates to `GrantAccessResolver` (persons) and `DisseminationResolver` (threat reports and flashpoints). Neither part acts on the other's models.
2. **`record_disseminations` is not `HasJurisdiction`.** It has two places (source and target), not one location. Rows are read through `receivedBy()`, `about()` and the policy. Pages show a row only when its record is visible to the viewer, so the inbox never reveals a record above the viewer's clearance.
3. **State roles never "receive" their own state's records.** A dissemination to an LGA of the owner's state reaches only that LGA's chief: the owning SD/DSD hold the record already, so they get no banner, badge or notice. Found in acceptance: the Lagos SD's badge counted their own dissemination.
4. **Onward notifications go to the LGA chief only.** The forwarding state's SD/DSD hold the parent already. Found in acceptance.
5. **Unrevoked expired rows keep the unique slot.** A partial index cannot use `now()`. A new dissemination to the same target supersedes (revokes) an expired one. A threat's reactivation re-opens its rows, because they follow the threat's expiry.
6. **Mitigated, withdrawn or materialised threats stay shared until their expiry,** so neighbours see the outcome. Only extend, reactivate and expire move the dissemination expiry, as specified.
7. **DSD cannot revoke, even their own dissemination.** The prompt's matrix gives `disseminations.revoke` only to DG, DDG and SD, and permissions are always combined with the "who" rules.
8. **DDGs are not recipients.** "State-level roles" was read as SD/DSD. DDGs see records in their zones anyway, and none outside them.
9. **Recipients do not see the History tab.** It holds edit diffs and the owners' names. Assessments, links and the timeline are shown, through the viewer's scopes.
10. **Owner-only writes and links.** Linking a threat to a flashpoint now needs `link` (own jurisdiction) on the flashpoint, not `view`. Otherwise a shared flashpoint could be linked from the owner side.
11. **`DisseminationSubjects` reads a record by id in a briefly entered system context, restored in `finally`.** Policies evaluate an explicit user (for example a notification recipient) and must not depend on the current viewer's scopes.
    - It is not `runAsSystem()`, because that would add an audit row to every policy check.
    - Nothing read this way is shown without authorising it for the user concerned.
    - An earlier version removed `ClassificationScope` explicitly. The classification architecture test caught that, and the system context makes the explicit removal unnecessary.
12. **Live updates for recipients.** Recipients cannot join the owning state's threat channel. The threat page subscribes them to their own `users.{ulid}` channel, which carries the end-of-sharing event, so they get no live refresh of the record itself.

## New packages

None.

## Known issues and notes

- **Real boundaries:** suggestions use LGA boundaries when imported. With the dev demo boundaries, only Lagos and Kano LGAs have shapes.
- **Scale of the dissemination form:** it loads every state and LGA (774 LGAs, about 40 KB) for users who may disseminate or request. That is acceptable for now; a picker would be lighter.
- **Badge cost:** the `badges.disseminations` count runs on every page for intelligence users (one small query per unacknowledged row).
- **Dev data changes:**
  - THR/LA/2026/00004 (shared with LA-1-1, acknowledged; Kano shares revoked);
  - THR/LA/2026/00005 (secret, shared with LA-1-1);
  - the Toll gate corridor blackspot shared with LA-1-2 (request actioned);
  - musa.bello@buffalo.local reactivated, and its password set to the local acceptance password (it was inactive and had never been set);
  - roles re-seeded.
