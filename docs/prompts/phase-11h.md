# BUFFALO — Phase 11H: Tasking from Directors to LGA Security Chiefs

Read `CLAUDE.md` before starting. Read `docs/handover/phase-11g.md` (security reports, attachments) and `docs/handover/phase-11-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

The client wants State Directors to send written instructions ("taskings") to LGA Security Chiefs (LGSCs): to one LGA chief, several chosen LGA chiefs, or every LGA chief in their state, all from **one** form with **one** recipient picker: the director ticks one LGA, a few, or uses **Select all**.

Each recipient sees it on their dashboard as a new message from the Director. A tasking is an instruction that needs an answer, so recipients acknowledge it and can report back, and the director sees who has read and acted on it.

---

## Step 11H.1 — Tables

**`taskings`**

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `reference_no` | `TSK/<STATE>/<YEAR>/<5-digit seq>`, assigned on **send**, gap-free per state and year (counter table, as for threats) |
| `state_id` | the sender's state; required |
| `title` | plain text, optional, max 200; if empty, show "Tasking from the State Director, [date]" |
| `body` | rich text (11B editor and sanitiser), required to send; plain-text limit **50,000 characters** (`config/buffalo.php` → `taskings.max_characters`), counter visible, so at least 20,000 characters are always available |
| `priority` | `routine`, `priority`, `immediate`; default `routine` |
| `response_due_at` | nullable `timestampTz` |
| `target_mode` | derived on send, never chosen by the user: `single` (1 LGA), `selected` (some) or `all_lgas` (every active LGA of the state at send time); used for display and filters |
| `classification` | default `restricted`; within the sender's clearance |
| `status` | `draft`, `sent`, `recalled` |
| `sent_by`, `sent_at` | |
| `recalled_by`, `recalled_at`, `recall_reason` | reason required |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes (drafts only) | |

**`tasking_recipients`**: one row per LGA, created on send.

| Column | Notes |
|---|---|
| `tasking_id` | |
| `state_id`, `lga_id` | the recipient LGA; composite FK (access rule 9) |
| `status` | `delivered`, `read`, `acknowledged`, `completed` |
| `read_at`, `read_by` | set when an LGA chief first opens it |
| `acknowledged_at`, `acknowledged_by` | |
| `completed_at`, `completed_by` | |
| `response` | rich text, nullable: the chief's report back; required to mark **completed** |

Unique (`tasking_id`, `lga_id`).

**`tasking_attachments`**: optional photos, videos and documents on a tasking, and on a recipient's response, reusing the 11G attachment service exactly (content detection, malware scanning, SHA-256, EXIF-stripped previews, streaming, access logging, backup inclusion). Attachments on the tasking carry the tasking's state; attachments on a response carry the recipient's `state_id` and `lga_id`.

**Recipients are LGAs, not people.** A tasking addressed to Uyo belongs to Uyo LGA: if the Uyo chief is reposted, their successor sees it. When sending to an LGA with no current chief (no open officer posting), warn the sender ("Uyo LGA has no Security Chief assigned; the tasking will wait for the next chief") and allow sending.

## Step 11H.2 — Rules

1. **Senders:** the State Director and Deputy State Director, for LGAs **in their own state only**. The LGA picker uses `JurisdictionOptions`; the server re-checks every recipient LGA against the sender's state.
2. **After sending, the tasking is fixed:** title, body, priority, classification and recipients cannot be edited. Corrections are made by **Recall** (with a reason; recipients see "Recalled by [name]: [reason]") and sending a new tasking, or by adding a **follow-up note** (append-only, rich text, notifies recipients). This keeps a reliable record of what was instructed and when.
3. **Visibility:**

| User | Sees |
|---|---|
| LGA Security Chief | Only taskings addressed to **their own LGA**, and only their own recipient row (never which other LGAs received a "selected" or "all LGAs" tasking, or their responses) |
| State Director, Deputy | All taskings of **their state**, every recipient's status and response |
| DG, DDG | All taskings within reach (read-only oversight) |
| Super Admin | None |

   Implement through `HasJurisdiction` on `taskings` (state) and on `tasking_recipients` (state and LGA), so an LGA chief reaches a tasking only through their own recipient row. Classification applies on top: a chief never sees a tasking above their clearance; the send form warns the sender when the chosen classification would hide it from LGA chiefs, and refuses to send in that case.
4. **Read and response:** opening a tasking for the first time marks the LGA's row `read`. The chief can **Acknowledge** (one click) and **Mark completed** with a response (required) and optional attachments. The chief can add further responses (append-only) after completing.
5. **Not grantable and not disseminable:** taskings never cross jurisdictions.
6. **Overdue:** a recipient row not `completed` by `response_due_at` is overdue. A job notifies the chief once at the due time and the sender once a day while any recipient is overdue (counts only).

## Step 11H.3 — Notifications

Payload: reference, priority and link only; never the body. Recipients must be able to see the tasking.

| Event | Recipients | Severity |
|---|---|---|
| Sent | The current chief of each recipient LGA | `immediate` → critical (live toast), `priority` → warning, `routine` → info |
| Follow-up note added | Each recipient LGA's chief | info |
| Recalled | Each recipient LGA's chief | warning |
| Acknowledged, completed, response added | The sender (and the other director of the state) | info |
| Overdue | As in 11H.2 | warning |

Broadcast on the LGA chief's user channel and the state channel (ULID and event name only), so dashboards update live.

## Step 11H.4 — Screens

**Director dashboard (State Director and Deputy):** a **Tasking** card at the top with one primary button, **New tasking**.

**Recipient picker** (the first part of the form): a searchable checklist of every active LGA in the director's state, each row showing the LGA and its current chief (or "No chief assigned"). At least one LGA must be ticked.

- Above the list: a **Select all** checkbox (with the indeterminate state when only some are ticked), **Select by senatorial district** (ticks every LGA of a chosen district; can be used several times), and **Clear**.
- Search filters the visible rows; **Select all** applies to the whole state, not just the filtered rows, and says so ("Select all 31 LGAs").
- A running count, "[n] of [total] LGAs selected", and the chosen LGAs as removable chips (collapsed to "All [n] LGAs" when every LGA is ticked).
- Opening the form from an LGA's page (a **Send tasking** action on the LGA page for directors) pre-ticks that LGA.

**Send** opens a confirmation that states the recipients plainly: "Send to the Uyo LGA chief", "Send to 3 LGA chiefs: Uyo, Ikot Ekpene, Eket", or, for everyone, "Send to **all 31** LGA chiefs in Akwa Ibom", with vacant LGAs listed.

Below the buttons: the latest sent taskings with a progress line per tasking (for example "12 of 31 read · 5 acknowledged · 2 completed · 3 overdue").

**Tasking form:** Title (optional), Body (large editor with character counter), Priority, Response due (optional), Classification, Attachments; **Save draft** and **Send** (Send shows a confirmation with the recipient count).

**Tasking page (directors):** the tasking, follow-up notes, and a recipients table (LGA, chief, status, read, acknowledged, completed, overdue) with filters; clicking a row shows that LGA's response and attachments. Actions: Add follow-up note, Recall.

**LGA chief dashboard:** a **Messages from the Director** card at the top: unread count badge, the latest taskings (priority, title or first line, sent date, due date, status), unread ones in bold, overdue ones flagged. Clicking opens the tasking with **Acknowledge** and **Mark completed** (response editor plus attachments).

**Navigation:** a **Taskings** item (for directors: Sent, Drafts; for LGA chiefs: Inbox) with an unread badge for LGA chiefs and an overdue badge for directors. National users see Taskings as an oversight list filtered by state (state lens applies).

Run `make wayfinder` after route changes.

## Step 11H.5 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `taskings.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `taskings.send` (create, send, follow up, recall) | | | ✓ | ✓ | |
| `taskings.respond` (acknowledge, complete, respond) | | | | | ✓ |

Super admin: none. Update the matrix, drift tests, `auth.ts`, the route inventory, register a `taskings` list exporter (non-sensitive; recipients' status, never bodies of other LGAs' responses to an LGA chief), add a tasking PDF (the tasking and, for directors, the recipients table), the manuals and quick-reference cards (director and LGA chief), and the "Who can see what" help page.

## Step 11H.6 — Tests

- Sending: one LGA, some LGAs (district quick pick) and all LGAs through the same picker; at least one required; `target_mode` derived correctly; Select all covers the whole state even when the list is filtered; confirmation text per case; recipients only from the sender's state (a forged LGA from another state is refused); reference numbers gap-free under concurrency; vacant-LGA warning; body required and limited by plain text.
- Fixed after sending: edits refused via HTTP; recall with reason; follow-up notes append-only.
- Visibility matrix as in 11H.2 on every route (list, detail, attachments, responses, export, PDF, search, dashboards), including: an Uyo chief sees only Uyo's row of an all-LGAs tasking and cannot see other LGAs' responses; a Lagos director cannot see Akwa Ibom taskings; a reposted chief's successor sees the LGA's taskings; 404 for anything out of reach.
- Classification: send refused when chiefs could not see it.
- Read, acknowledge, complete (response required), further responses; statuses and progress counts.
- Overdue job: once per recipient at the due time, daily summary to the sender.
- Notifications: recipients, severities, payloads without bodies; live dashboard updates.
- Browser check: a State Director sends, through the one picker, to one LGA, to a few LGAs (using the district quick pick) and to all LGAs (Select all); an LGA chief sees the unread badge, opens, acknowledges and completes with a photo; the director sees the progress line update.

## Acceptance checklist

- [ ] The Akwa Ibom State Director clicks **New tasking**, ticks Uyo, writes a long instruction with a photo and sends it; the Uyo chief gets a live notification and sees it in **Messages from the Director**.
- [ ] A tasking with Uyo, Ikot Ekpene and Eket ticked reaches exactly those three chiefs; none of them can see that the others received it.
- [ ] A tasking with **Select all** reaches every Akwa Ibom LGA after a confirmation naming all 31; each chief sees only their own copy; the director sees "n of 31 read".
- [ ] The Uyo chief acknowledges, then marks completed with a written response; the director sees the response.
- [ ] A Lagos LGA chief and the Lagos State Director cannot see any Akwa Ibom tasking.
- [ ] A sent tasking cannot be edited; recalling it shows the reason to recipients.
- [ ] `make test`, `make lint`, `make ci` and `make browser-check` pass.

## Handover

Write `docs/handover/phase-11h.md` per CLAUDE.md section 9 and print it.
