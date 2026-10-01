# Phase 5 summary — Incident reporting and tracking

Date: 2026-10-01

Phase 5 is complete. Detailed handovers:

| Step | Handover | Scope |
|---|---|---|
| 5A | `phase-5a.md` | Time zone fix, searchable pickers, the incident record and its related data, draft form |
| 5B | `phase-5b.md` | Evidence: integrity, previews, streaming, chain of custody |
| 5C | `phase-5c.md` | Workflow, reference numbers, escalation, follow-ups, notifications, real-time, reminders, duplicates |
| 5D | `phase-5d.md` | Lists, search, saved views, map, full detail page, print, record tabs, dashboards, performance |

Final checks at the end of Phase 5:

- `make lint` passes.
- `make ci` passes: 1225 Pest tests (7993 assertions) and the Vitest suites.
- The completion checklist passes in the browser (see `phase-5d.md`).

## Tables added in Phase 5

All intelligence tables follow the project rules:

- `HasJurisdiction` on their own `state_id`/`lga_id`, with composite FKs;
- ULIDs in URLs;
- authors and timestamps (`timestamptz`);
- activity logging.

| Table | Step | Notes |
|---|---|---|
| `incidents` | 5A, 5C | Type, validated `details` (jsonb object), severity, time and precision, place and point, status, classification, reporting officer, generated English `search_vector` (GIN).<br>5C adds workflow, escalation and invalidation columns.<br>Locked when closed or invalid (trigger). |
| `incident_casualties`, `incident_persons`, `incident_criminal_groups`, `incident_agencies`, `incident_properties` | 5A | Each scoped on its own place and visible only while the incident is.<br>A composite FK to the incident's place with ON UPDATE CASCADE (children follow LGA corrections).<br>Locked with the incident. |
| `incident_evidence` (+ medialibrary `media`) | 5B | Originals unaltered, SHA-256 recorded and checked on download, EXIF-free previews, never below the incident's classification (triggers), soft delete with the file kept. |
| `incident_reference_counters` | 5C | `BUF/<state>/<LGA>/<year>/<6 digits>`, gap-free per state and year (atomic upsert in the submission transaction). |
| `incident_status_histories` | 5C | Append-only (only the place may follow the incident). |
| `incident_updates` | 5C | Follow-ups: append-only, classified (not below the incident when written), access-logged above restricted. |
| `incident_reminders` | 5C | Verification reminders sent, once per submission and kind. |
| `incident_saved_filters` | 5D | Each user's named list views. |

## Workflow

| Step | From → to | Who (plus jurisdiction and classification) | Needs |
|---|---|---|---|
| Submit | draft, returned → submitted | creator, reporting officer, SD/DSD of the state | Core facts only. A reference at the first submission. A critical incident is escalated automatically. |
| Return | submitted → returned | SD, DSD | reason |
| Verify | submitted → verified | SD, DSD | optional comment |
| Start investigation | verified → under investigation | SD, DSD, DG, DDG | |
| Resolve | verified, under investigation → resolved | SD, DSD, DG, DDG | resolution summary |
| Reopen | resolved → under investigation | SD, DG, DDG | reason |
| Close | resolved → closed (**locked**) | SD, DG, DDG | |
| Invalidate | submitted, verified → invalid (**locked**) | SD, DG, DDG | reason; note for "other"; the original for a duplicate |
| Escalate (flag) | submitted, verified, under investigation | SD, DSD | reason |
| Acknowledge (flag) | escalated | DG, DDG covering the state | |

**Editing:**

- Until verified: the reporting side (creator, reporting officer, SD/DSD).
- From verified until closed: holders of `incidents.amend`, each save with an amendment reason.
- After submission, only the SD, DG or DDG may correct the LGA; the reference number stays.

**Evidence:** uploads while editable; once submitted, any viewer with `evidence.upload` may add evidence until the incident is closed or invalid.

**Follow-ups:** any status except closed and invalid.

## Permissions added in Phase 5

Key: **DG** — Director General; **DDG** — Deputy Director General; **SD** — State Director; **DSD** — Deputy State Director; **LGA** — LGA Security Chief. Super admin holds none of these.

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `incidents.view`, `incidents.create`, `incidents.update`, `incidents.submit` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `incidents.verify` (verify, return), `incidents.escalate` | | | ✓ | ✓ | |
| `incidents.investigate` (start, resolve), `incidents.amend` | ✓ | ✓ | ✓ | ✓ | |
| `incidents.close` (close, reopen), `incidents.invalidate` | ✓ | ✓ | ✓ | | |
| `incidents.acknowledge` | ✓ | ✓ | | | |
| `evidence.upload`, `evidence.download_original` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `evidence.verify`, `evidence.delete` | ✓ | ✓ | ✓ | | |

Saved filters and printing need only `incidents.view`. Every permission is combined with jurisdiction, classification and the "who" rules above. Incidents and evidence are never grantable across jurisdictions; only persons are.

## Notification rules

All go through `BuffaloNotification`. Each recipient is checked to be able to see the record (the incident, or the follow-up). The payload is the reference, incident type, LGA and a link only.

| Event | Recipients | Severity |
|---|---|---|
| Submitted | SD and DSD of the state | critical → critical; high → warning; else info |
| Submitted critical (auto-escalated) | also the DG and the DDGs covering the state | critical |
| Returned, verified, resolved, closed, invalidated | reporting officer and creator | info |
| Escalated | DG and DDGs covering the state | warning (critical for a critical incident) |
| Acknowledged | the escalating officer (or the submitter for an automatic escalation) | info |
| New follow-up | reporting officer and SD/DSD, not the author | info |
| Intelligence or action taken on an escalated incident | also the DG and covering DDGs | info |
| Verification overdue | SD and DSD once; at twice the deadline, the DG and covering DDGs once | warning |
| Evidence integrity failure | DG and super admins (evidence ULID only; super admins get no link) | critical |

**Real-time:** `states.{state_ulid}.incidents` and `national.incidents` carry only `{incident, event}`. Open incident pages and the dashboards refetch through their scoped routes.

**Deadlines:** `BUFFALO_VERIFY_*_MINUTES`; critical 60, high 360, medium 1440, low 2880.

## Performance (5D)

On dev with 50,004 incidents (50,000 synthetic from `buffalo:seed-demo-incidents`), server response times:

- **Incident list:** 0.13–0.14 s.
- **Full-text search over all:** 0.14–0.17 s.
- **Map data** (5,000-point cap): 0.13–0.29 s.
- **Dashboards:** 0.19–0.29 s.
- **Main queries:** 2.5–10 ms each, except the list's total count at 49 ms (16,000 rows).

Full figures and `EXPLAIN` output are in `phase-5d.md`.

## Decisions taken during Phase 5 (for the CLAUDE.md log)

- The pgsql connection time zone is Africa/Lagos; new columns use `timestamptz`.
- `PersonPolicy::linkToIncident` (own jurisdiction or an active grant) is used for incident person links; `link` stays own-jurisdiction only.
- HEIC evidence is not accepted. PDFs get no thumbnail (no Imagick or Ghostscript in the stack).
- **Charts: Chart.js (MIT) replaces ApexCharts,** whose current versions are no longer permissively licensed. **CLAUDE.md section 2 needs updating.**
- The statistics rule: counts exclude drafts and invalid reports unless a view is explicitly about them.

## Open issues for Phase 6

- **Maps:** heatmaps and flashpoint or blackspot layers should aggregate on the server. Point payloads reach 1.2 MB at the 5,000 cap. Dashboard maps show 500 recent points for now.
- **Flashpoint links:** incidents will link to flashpoints (out of scope in Phase 5). Use the 5A child-table pattern: own place, composite FK with cascade, and the lock trigger.
- **LGA boundaries:** location checks against LGA boundaries already run by trigger when boundaries exist. Dev has no LGA centroids or boundaries yet, so they await the owner's data.
- **Statistics:** Phase 7 analytics and exports must keep the statistics rule and the per-viewer scopes. The list's total count may need an estimate at much larger volumes.
- **Carried over:**
  - real video playback was not exercised in acceptance (no encoder in the environment);
  - malware scanning is Phase 8;
  - no merging of duplicate persons;
  - dev data includes 50,000 demo incidents (`--purge` removes them).
