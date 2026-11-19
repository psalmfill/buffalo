# Phase 11H — Taskings from Directors to LGA Security Chiefs

Prompt: `docs/prompts/phase-11h.md`. Date: 2026-11-19.

## Summary

State Directors and Deputy State Directors now send **taskings**, written instructions, to the LGA Security Chiefs of their state: one LGA, several, a senatorial district, or every LGA, all from one form with one picker. Each chief sees it at the top of their dashboard under **Messages from the Director**, acknowledges it, and reports back. The directors see who has read, acknowledged and completed each tasking. National users follow taskings read-only.

**Results:** see "Checks" at the end.

## Acceptance checklist

| Item | Result |
|---|---|
| A director clicks **New tasking**, ticks one LGA, writes a long instruction with a photo and sends it; the chief gets a live notification and sees it in **Messages from the Director** | ✅ Browser check with the Lagos accounts (the dev data has no Akwa Ibom users); `TaskingNotificationsTest` covers the notification and the live event. |
| A tasking with three LGAs reaches exactly those three chiefs; none can see that the others received it | ✅ `TaskingSendingTest`, `TaskingAccessTest` (the chief's page, list, PDF and export carry neither the recipients, the target mode nor other LGAs' answers). |
| **Select all** reaches every LGA after a confirmation naming all of them; each chief sees only their copy; the director sees "n of N read" | ✅ Picker Vitest (Select all with a search active), browser check, `TaskingLifecycleTest` (progress line). |
| The chief acknowledges, then completes with a written response; the director sees it | ✅ `TaskingLifecycleTest`, browser check. |
| A Lagos chief and the Lagos State Director cannot see another state's taskings | ✅ `TaskingAccessTest` (the B1 director and chief against state A1's taskings: 404). |
| A sent tasking cannot be edited; recalling shows the reason to recipients | ✅ HTTP (403) and database triggers; `TaskingLifecycleTest`. |
| `make test`, `make lint`, `make ci`, `make browser-check` pass | See Checks. |

## 11H.1 Tables

Migration `2026_11_25_000001_create_tasking_tables`:

- **`tasking_counters`**: references `TSK/<STATE>/<YEAR>/<5 digits>`, assigned on send, gap-free per state and year (`TaskingReferenceAllocator`, the threat report pattern; concurrency test with forked processes).
- **`taskings`**: the prompt's columns, plus `body_text` (11B plain copy), `draft_lga_ids` (a draft's chosen LGAs; recipient rows exist only once sent) and `overdue_summary_sent_on`. CHECKs on every enum, on a sent tasking being complete (reference, sender, time, target mode, body, no draft list), on a recall having a reason, and on only drafts being soft-deleted.
- **`tasking_recipients`**: one row per LGA, with its own `state_id`/`lga_id` and the composite FK to `lgas` (access rule 9), unique (`tasking_id`, `lga_id`), a ULID for URLs, `overdue_notified_at`. CHECKs: read, acknowledged and completed each need their time and user; completed needs a non-empty response.
- **`tasking_notes`** (directors' follow-up notes) and **`tasking_responses`** (further reports back after completing): append-only (the audit `buffalo_reject_audit_change` trigger, no truncate). Responses carry the recipient's place through a composite FK. Both are in `DatabaseRoles::APPEND_ONLY` (the application role may only SELECT and INSERT them).
- **`tasking_attachments`**: the 11G attachment columns, plus `tasking_id` and `tasking_recipient_id`. An attachment of the tasking has no LGA (the tasking's state); one of a report back has the recipient's state and LGA (composite FK to the recipient row; a CHECK keeps both or neither). Documents are allowed besides photos and videos.
- **Triggers:**
  - a sent tasking's title, body, priority, due date, classification, target mode, state, reference and sender are fixed; status moves draft → sent → recalled only; a recall is fixed; sending needs at least one recipient; taskings are never deleted;
  - recipients are inserted only while the tasking is still a draft (that is, in the sending transaction), only in its state, never removed or moved; statuses only move forward; the completion report is fixed; nothing is acknowledged or completed after a recall;
  - attachments are never below the tasking's level; the tasking's own only change while it is a draft, a report back's only while the tasking is sent; never deleted (soft deletes only). Scan and integrity columns can always be updated.
- `report_runs_type` now allows `tasking` (the PDF).

**Recipients are LGAs.** Who sees a tasking is decided by the user's LGA, so a successor chief sees their predecessor's taskings (tested). The "current chief" (`LgaChiefs`) is the active user of the LGA's open LGA-chief officer posting; it names the chief in the picker, warns about vacant LGAs and addresses notifications.

## 11H.2 Rules as built

1. **Senders** (`TaskingPolicy::create`): `taskings.send` and a state-level jurisdiction. The picker lists the active LGAs of the sender's state (`JurisdictionOptions`); the request resolves the ticked ULIDs against that list (a forged or inactive LGA is refused, 422), and `SendTasking` checks again inside its transaction, under a row lock (a double click sends once).
2. **Fixed after sending:** edit, update and discard routes answer 403 for sent taskings; the triggers refuse direct changes. **Recall** needs a reason (5–2,000 characters); recipients see "Recalled by [name] on [time]: [reason]" and can no longer acknowledge, complete or respond. **Follow-up notes** are rich text, sanitised, append-only, by the state's directors, and notify the recipient chiefs.
3. **Visibility:**
   - `Tasking` uses `HasJurisdiction` on its state, and an `LgaVisibilityRule`: an LGA chief sees a tasking only once it is sent, and only when a recipient row exists for their LGA.
   - `TaskingRecipient` and `TaskingResponse` are scoped on their own state and LGA, so a chief loads only their LGA's row and responses; both are also visible only while their tasking is (which adds its classification).
   - `TaskingNote` is visible wherever its tasking is (same rule for chiefs).
   - `TaskingAttachment`: the tasking's own attachments follow the tasking (chiefs: when their LGA is a recipient); a report back's attachments carry that LGA.
   - Drafts are seen only by the state's directors (`TaskingPolicy::view`); national users see sent taskings only.
   - The recipients table, progress counts and the target mode are given only to users who see every row (`viewRecipients`: never an LGA chief); the tasking page, list rows, dashboard card, export and PDF all leave the target mode out for chiefs.
   - **Classification:** LGA chiefs are cleared for Restricted only, so sending is refused above it (request and action); the form warns as soon as a higher level is chosen. Drafts may keep a higher level.
4. **Read and response:** the first opening by the LGA's chief marks the row read (a director opening it does not). **Acknowledge** is one click; **Mark completed** needs a report back (rich text, 50,000 characters) and acknowledges and reads the row too if needed; **further responses** follow completion only.
5. **Not grantable, not disseminable:** none of the tasking models is a `Person` or `Disseminable`, and every policy check uses the user's own jurisdiction (`canAccessOwn`). Tested.
6. **Overdue** (`SendTaskingOverdueAlerts`, command `buffalo:taskings:overdue`, every five minutes): a recipient not completed by the due date is overdue. Each overdue LGA's chief is told once at the due time (claimed with `overdue_notified_at`); the sender gets a summary with the count only, the first when the tasking falls due, then once a day from `buffalo.taskings.overdue_summary_hour` (08:00). Recalled taskings are never overdue.

## 11H.3 Notifications

`TaskingNotification`: the reference, the priority and a link only; never the title, body, notes, responses or other LGAs (tested on every event).

| Event | Recipients | Severity |
|---|---|---|
| Sent | Each recipient LGA's current chief (vacant LGAs: nobody yet) | routine info, priority warning, immediate critical |
| Follow-up note | Each recipient LGA's chief | info |
| Recalled | Each recipient LGA's chief | warning |
| Acknowledged, completed, further response | The sender and every SD and DSD of the state; the link opens that LGA's row | info |
| Overdue | The LGA's chief once; the sender daily (count) | warning |

**Live updates** (`TaskingChanged`, payload: tasking ULID and event name): on `states.{ulid}.taskings` (new channel: users with `taskings.view` whose jurisdiction covers the whole state, so the state's directors and national users, never LGA chiefs) and on each recipient chief's own `users.{ulid}` channel. Dashboards, the list and the tasking page refetch their props.

## 11H.4 Screens

- **State dashboard** (SD and DSD): a **Tasking** card at the top with **New tasking**, the five latest sent taskings with their progress lines, and links to all taskings and drafts.
- **Recipient picker** (`components/taskings/recipient-picker.tsx`): every active LGA of the state with its current chief or "No chief assigned"; **Select all [n] LGAs** (indeterminate when some are ticked; always the whole state, even when a search hides rows), **Select by senatorial district** (adds a district each time), **Clear**, search, "[n] of [total] LGAs selected", and removable chips (collapsed to "All [n] LGAs").
- **Form** (`pages/taskings/form.tsx`): the picker, vacant-LGA warnings, Title (optional), Instruction (large editor with counter), Priority, Response due, Classification (with the warning), attachments once the draft is saved, **Send**, **Save draft**, **Discard draft**. Send opens a confirmation stating the recipients ("Send to the Uyo LGA chief", "Send to 3 LGA chiefs: …", "Send to **all 31** LGA chiefs in …") and listing vacant LGAs.
- **Send tasking** on an LGA's page (directors of its state) opens the form with that LGA ticked.
- **Tasking page** (`pages/taskings/show.tsx`):
  - directors and national users: the instruction, attachments, notes, the progress line and the recipients table (LGA, chief, status, read, acknowledged, completed, overdue) with status and overdue filters; a row opens that LGA's report back, further responses and attachments; **Add follow-up note**, **Recall**, **Print / Download**;
  - LGA chiefs: the instruction, notes and their LGA's panel: **Acknowledge**, the report back with attachments and **Mark completed**, then further responses.
- **LGA dashboard:** **Messages from the Director** at the top: unread count, the latest taskings (priority, title or first line, sent and due dates, status), unread in bold, overdue flagged.
- **Navigation:** **Taskings** under Overview (`taskings.view`), with a badge: unread taskings for LGA chiefs, taskings with an LGA overdue for directors. The page has boxes: Sent and Drafts (directors), Inbox (chiefs), and a read-only list with state and zone filters for national users (the state lens applies).
- The 11G attachment component (`components/security/report-attachments.tsx`) now takes its endpoints, accepts documents (listed with a download link) and reloads a named prop, so taskings reuse it unchanged for security reports.

## 11H.5 Permissions

| Permission | DG | DDG | SD | DSD | LGA | SA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `taskings.view` | ✓ | ✓ | ✓ | ✓ | ✓ | |
| `taskings.send` | | | ✓ | ✓ | | |
| `taskings.respond` | | | | | ✓ | |

Updated together: `Permission`, `RolesAndPermissionsSeeder::matrix()`, `auth.ts`, the navigation test and `RolesAndPermissionsTest`. Also:

- **Route inventory** regenerated (21 new routes; every one authorises in a policy or a Form Request before validation).
- **Jurisdiction matrix:** a sent tasking with a photo and its recipient row in `MatrixRecords`, the new GET routes in `ROUTE_PERMISSIONS`, and two inside exceptions (sent taskings are never edited; photo streams).
- **List exporter** `taskings` (registered; boxes and filters shared with the page): never a body, note or response. Directors and national users get recipient counts; an LGA chief gets only their LGA's status (counts and target mode empty).
- **PDF** `ReportType::Tasking` through the 7D pipeline: the tasking, attachments list and notes; for directors and national users the progress and the recipients table; for an LGA chief only their LGA's status, report back and responses.
- **Help:** a new **Taskings** guide; **Who can see what** gains a Taskings section; the role manuals and quick-reference cards of the DG, DDG, SD, DSD and LGA chief describe it.

## Reuse of the 11G attachment service

The storing code of `StoreReportAttachment` moved into `App\Actions\Evidence\StoreAttachmentFile`, which both security report and tasking attachments use (hash first, unaltered original on the evidence disk, malware scan refusing uploads when the scanner is down, quarantine for large files, EXIF only from clean files, integrity verified). `ScanQuarantinedEvidence`, `VerifyEvidenceIntegrity`, `ProcessEvidenceJob`, `ServesStoredFiles` and `buffalo:malware:scan-quarantined` accept tasking attachments too (quarantine folder `tasking-attachments/`, upload kind `tasking_attachment`). The evidence backups therefore include them.

## Deviations and decisions

1. **No search provider.** The prompt lists search among the routes to check; taskings had no reason to be in global search and the safest choice was to keep them out. `TaskingAccessTest` proves search never returns one. Say if you want search by reference and title.
2. **The state channel excludes LGA chiefs.** The prompt says to broadcast "on the LGA chief's user channel and the state channel". The existing state channels admit every user covering any part of the state, which would let chiefs learn that taskings went to other LGAs, so the new `states.{ulid}.taskings` channel admits only users covering the whole state; chiefs get events on their own channel.
3. **Directors' notifications don't name the LGA** (reference, priority and link only, as the prompt says); the link opens that LGA's row.
4. **Notifications go to the chief of the open posting**; visibility follows the user's LGA. A chief without a posting still sees the LGA's taskings but is not notified.
5. **Response due date** is fixed after sending too, with the other content (the prompt lists title, body, priority, classification and recipients).
6. **Removing report-back attachments** is allowed until the LGA completes (soft delete with a reason, file kept); files can still be added with further responses.
7. **Navigation:** one **Taskings** item with boxes on its page (Sent, Drafts, Inbox) rather than menu sub-items, which the sidebar does not support.
8. **The browser check uses Lagos** (`sd.lagos`, `chief.lagos`), as in 11G: the dev data has no Akwa Ibom or Uyo accounts.

## New packages

None. **Security patch updates of existing packages** (all MIT, patch or minor releases, `composer.lock` only): new upstream advisories (12, high severity, published after 11G) made `composer audit` fail in `make ci`, so `symfony/html-sanitizer`, `http-foundation`, `http-kernel`, `routing` and `serializer` went to 8.1.11, with their dependencies (`symfony/error-handler` and `var-dumper` 8.1.10, the polyfills, contracts 3.8.1, `league/uri` 7.10.0). The two low and medium advisories CI already ignores remain (`laravel/framework` < 13.40, `symfony/mime` < 8.1.10).

## Known issues and notes

- **Deploy:** run the migration, `RolesAndPermissionsSeeder` (new permissions), the database role grants (two new append-only tables), `make wayfinder`, and restart Horizon (job changes).
- **Larastan** runs out of memory in the 1 GB app container with parallel workers on this machine; it passes single-process (a temporary config, no project change).
- **PDF tests need Gotenberg** running (`docker compose up -d gotenberg`).
- **New tests:** `TaskingSendingTest` (14), `TaskingLifecycleTest` (8), `TaskingAccessTest` (17), `TaskingNotificationsTest` (11), `TaskingAttachmentsTest` (6), `TaskingReferenceConcurrencyTest` (1), and the picker Vitest (4). Updated: the jurisdiction matrix, permissions and navigation drift tests, help and database roles tests.

## For the next step

- Any new person-free record sent to LGAs can follow this pattern: a state-level parent with an `LgaVisibilityRule`, and per-LGA rows with their own place.
- New evidence-like uploads should go through `StoreAttachmentFile` and extend the union types of the shared evidence services.

## Checks

- **`make ci`** (which runs the lint and test suites, then `composer audit` and `npm audit`): **passed**, with 2,114 Pest tests (18,853 assertions) and all 29 Vitest tests passing. Run sequentially, never `--parallel`. Larastan also passes single-process (see Known issues).
- **`make browser-check`** (local stack): the first full run had 31 passed and 3 failed:
  - Spec 12 failed because the local database had not been re-seeded with the new permissions, so there was no Taskings menu and no card.
  - Two failures were environmental timeouts: a 09 state-profile PDF wait, and a 2FA sign-in in the 11G `beforeAll`.
  - After running `RolesAndPermissionsSeeder` on the local database, a re-run of specs 09, 11G and 12 passed: **16/16**.
  - Screenshots 60–64 are in `docs/uat/results/phase-11/`.
- **Local stack note:** another project's Mailpit held port 8025, so `make up` stopped before nginx. nginx was started on its own; the browser check does not need mail.
- No orphan `app-run` containers.
