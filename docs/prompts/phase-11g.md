# BUFFALO — Phase 11G: Security Reports, Menu Clean-up and Person-of-Interest Details

Read `CLAUDE.md` before starting. Read `docs/handover/phase-11-summary.md`, the CRUD coverage handover, `docs/architecture/crud-coverage.md`, `docs/handover/phase-4a.md` (coverage reports) and `docs/handover/phase-5b.md` (evidence).

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

The client reviewed the system again and asked for four changes:

1. Remove the duplicate incident item from the side menu.
2. Rename **Coverage reports** to **Security reports**, and make them mainly a long written report with photo and video attachments, where every other field is optional.
3. Confirm that security reports follow the jurisdiction rules strictly.
4. Add missing details to persons of interest.

## Step 11G.1 — Menu

- Remove the **Report incident** item from the **Incidents** section of the side menu. Keep **Incidents** and **Arrests**.
- Make sure the incidents list has a prominent **Report incident** button (primary style, top right) for users with `incidents.create`, and that each role's dashboard keeps its existing Report incident shortcut, if any. Report what exists.
- Update the navigation config, the navigation test and the browser check.

## Step 11G.2 — Coverage reports become Security reports

Rename and simplify; do **not** create a second module. Existing data, permissions and workflow continue.

### Naming

- Every user-facing label: "Coverage report(s)" → "Security report(s)" (menu, pages, buttons, notifications, dashboards, analytics, exports, PDFs, manuals, quick-reference cards, search results).
- Keep the database table and permission names (`coverage_reports`, `coverage.*`) to avoid a risky rename; add a short note in `CLAUDE.md` that "coverage report" in code means "security report" in the interface. If renaming routes is low-risk, change URLs to `/security-reports` with permanent redirects from the old ones; otherwise keep the URLs and report it.

### The report form (what an LGA chief sees on **New security report**)

1. **Report** (required to submit): a large rich text field (11B editor and sanitiser), **at least 20,000 characters available**: set the plain-text limit to 50,000 (`config/buffalo.php` → `security_reports.max_characters`), with the character counter visible. This replaces `summary` as the main field: migrate existing `summary` content into it (no data loss), or reuse the `summary` column under the new label if simpler; report which.
2. **Attachments**: photos and videos (see below), uploaded while drafting.
3. **Optional details**, in a collapsed section titled "More details (optional)": title (plain text, max 200; if empty, show "Security report — [LGA] — [date]"); report date (defaults to today); period type and period (now optional; default `ad_hoc`); overall rating; gaps identified, recommendations (rich text); agency deployments and covered communities (the existing table; now optional); classification (default `restricted`, as today).
4. **Save draft** and **Submit**.

### Validation changes

- **Submit** requires only the Report text (non-empty plain text). Every other field is optional, including rating, gaps and deployments.
- Remove the one-report-per-LGA-per-period uniqueness for `ad_hoc` reports (it already applies only to non-ad-hoc). Keep it for weekly, monthly and quarterly reports, which remain possible for users who choose a period.
- The workflow is unchanged: draft → submitted → returned or reviewed (locked). Returned reports can be edited and resubmitted. Notifications as before, with the new name.
- **Reminders:** an LGA counts as having reported for a period when **any** security report was submitted during that period (any period type, including ad hoc). Update the overdue job and the dashboard status accordingly.
- Computed figures (totals by agency, uncovered communities, comparison with the previous report) appear only when deployments were entered.

### Attachments

`security_report_attachments` (or `coverage_report_attachments`; keep consistent with the table), reusing the Phase 5B evidence services rather than duplicating them: `id`, `ulid`; report FK; `state_id`, `lga_id` copied from the report and kept in sync (access rule 9), composite FK with cascade; `caption` (plain text, optional, max 200); `media_type` (`image` or `video`, detected from content); `original_filename`, `mime_type`, `size_bytes`, `sha256` as for evidence; `captured_at`, `captured_location` from EXIF when present, editable, optional; `classification` defaults to the report's, never below it; `uploaded_by`, `timestampsTz`, soft deletes.

- **Accepted:** JPEG, PNG, WebP images up to 25 MB; MP4 and WebM videos up to 100 MB (same limits and content detection as evidence). Malware-scanned before acceptance (fail closed, quarantine for large files as in 8B). Originals kept unaltered with SHA-256 verified on download; EXIF-stripped previews and thumbnails; video streamed with range support.
- Up to 30 attachments per report (`config/buffalo.php`).
- Added, captioned or removed only while the report is a draft or returned; locked with the report once reviewed. Removal is a soft delete with a reason.
- Every view and download logged in `record_access_logs`.
- Gallery on the report page (images in a lightbox, inline video player), and in the report PDF (thumbnails of up to 12 images with captions; videos listed by caption and capture date).
- Register the attachment table wherever evidence tables are registered (8C evidence backups must include these files; consolidation and merge registries if they apply).

## Step 11G.3 — Access restrictions (verify and prove)

The client's rules must hold for security reports and their attachments **without exception**: LGA Security Chief: their own LGA only; Deputy State Director, State Director: their own state only; Deputy Director General: all states and LGAs (or only their assigned zones); Director General: all; Super Admin: none.

1. Add a dedicated test file proving this matrix for security reports **and** their attachments (list, detail, attachment preview, stream and download routes, export, PDF, search, dashboards and analytics), including 404 for out-of-reach records.
2. Confirm in the handover that security reports are **not** grantable (Phase 3) and **not** disseminable (7A).
3. Add a plain-language **"Who can see what"** page to the in-app help (all roles), describing jurisdiction, classification, and the only two deliberate cross-jurisdiction mechanisms; include that LGA chiefs can read their own state's reference profile (11F).

## Step 11G.4 — Person-of-interest details

Verify each requested item against the current schema and report it in a table (exists as / added as / why not): age (computed), criminal record (`criminal_record` rich text; keep `known_offences`; show linked arrests), height (`height_cm` 50–250; cm or feet and inches), state of origin, LGA of origin (composite FK), hometown, residential address (relabel only), marital status, religion (lookup code, encrypted, no blind index), political affiliations (encrypted, no blind index), hair colour, eye colour, tribal marks (yes/no/unknown plus description), phone numbers (`person_phones`, each encrypted with a blind index, label, `is_primary`; migrate the existing number as primary).

Rules: new descriptive fields on `persons_of_interest` (data minimisation; origin never affects jurisdiction); special categories shown only on the POI page, never in lists, filters, search, analytics or any export or PDF list; tribal marks on the POI page and single-person PDF only; form help text "Record only where relevant to identification or the investigation."; PersonMatchService, duplicate finder, merge rules, `PersonReferenceRegistry` and search phone detection use every number; sensitive list exports never include special categories, phones or the address; encrypted values never in activity logs; new POI fields in the `full` grant scope only.

Page: Personal details, Physical description, Criminal record next to Known offences and linked arrests, and a separate Sensitive details card. Form: the same groups, every new field optional.

## Step 11G.5 — Permissions

No new permissions.

## Step 11G.6 — Tests

Menu; security reports (text-only submit, 50,000-character limit, optional fields, ad hoc unlimited, period uniqueness, reminders, `summary` preserved); attachments (content detection, EICAR, size limits, integrity, EXIF-stripped previews, streaming, locking, logging, backup inclusion); the 11G.3 access matrix; POI fields (validation, origin, age, encryption without blind index, special categories absent everywhere, multiple phones matched by PersonMatchService, duplicates and search, merge and undo, grant scopes); browser check.

## Acceptance checklist

- The Incidents menu shows Incidents and Arrests only; Report incident is a button on the list.
- The menu shows Security reports; an Uyo LGA chief writes a report of more than 20,000 characters, attaches two photos and a video, and submits without filling anything else.
- The Uyo chief cannot open another LGA's security report (404); the Akwa Ibom State Director sees all Akwa Ibom LGAs' reports but no other state's; the DG sees all.
- A POI shows age, height, origin, hometown, residential address, marital status, hair and eye colour, tribal marks, phone numbers and criminal record; religion and political affiliations appear only in the Sensitive details card and in no export.
- A search with a POI's second phone number finds them.
- `make test`, `make lint`, `make ci` and `make browser-check` pass.

## Handover

Write `docs/handover/phase-11g.md` per CLAUDE.md section 9, including the field verification table from 11G.4, and print it.
