# BUFFALO — Phase 11E: Printing Flashpoints and Exports for Every List

Read `CLAUDE.md` before starting, especially the export rules (now including the sensitive list controls) and the Phase 11 entry on list exporters. Read `docs/handover/phase-11d.md` and, for the report pipeline, `docs/handover/phase-7d.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

The client wants to print and download flashpoints and blackspots, and to export **every** list in the app. All exports reuse the Phase 7D report pipeline (`report_runs`), so every file is built as the requester through their own scopes, carries classification markings and the requester's name, is logged, counts toward quotas (8B), expires, and is downloadable only by the requester.

**Sensitive lists** (persons, persons of interest, criminal group members, organization contacts) get stricter controls (CLAUDE.md): SD, DG or DDG only; step-up **and** a stated reason every time; at most 1,000 rows; encrypted fields (NIN, phone, address, date of birth, contact phone and email) and photos **never** included; an immediate alert to the DG and covering DDGs.

This step completes Phase 11.

---

## Step 11E.1 — Flashpoint and blackspot documents

1. **Single flashpoint PDF** (report type `flashpoint`; anyone who can view it):
   - header: name, kind, threat type, concern level, status, classification banner;
   - a **location sketch** generated on the server as SVG: the LGA boundary outline (from `boundary_simplified`), the flashpoint point and radius circle, and the linked incidents as dots, with a scale bar and north arrow. **No map tiles** (Gotenberg cannot fetch them, by design). Coordinates printed beneath it.
   - description and recommended action (rich text);
   - current assessment and the full assessment history;
   - linked incidents visible to the requester (reference, type, date, status);
   - linked security notes and threat reports visible to the requester;
   - disseminations (owners only, as on screen);
   - markings as in 7D.
2. **Flashpoint register** (report type `flashpoint_register`; PDF table, XLSX, CSV): the current flashpoint list filters, one row per flashpoint (name, kind, threat type, concern, status, state, LGA, community, coordinates, radius, last assessed, review due, linked incidents count visible to the requester, classification). Plain text versions of rich fields in spreadsheets.
3. Buttons: **Print / Download** on each flashpoint page (PDF), and **Export** on the flashpoint list (PDF register, XLSX, CSV).

## Step 11E.2 — Exports for every list

1. **List exporter registry.** Each list page registers an exporter defining: the list's filter object (reused from the page), the scoped query (the same as the page's), the exportable columns (with plain text for rich fields), the default columns (the page's visible columns), whether it is **sensitive**, its row cap, and an optional PDF table layout.
2. **Coverage test:** a test enumerates every list (index) page route and fails if any has no registered exporter, unless it is on an explicit exclusion list with a reason (for example the audit log, which already has its own review screens, or the login history). Report the full mapping of pages to exporters and any exclusions.
3. **Export button** on every list page: choose format (XLSX, CSV, PDF table), columns (from the allowed set), and scope ("current filters" only; never "everything regardless of filters"). Large exports over 10,000 rows require step-up, as in 8B. Files go through `report_runs`.
4. **Lists covered** include at least: incidents (existing export, now via the registry), arrests, threat reports, flashpoints, blackspot suggestions, coverage reports, agency formations, organizations, organization sites, communities, religious institutions, vulnerable population figures (aggregates only), community security notes, LGAs and senatorial districts, political offices, access requests and grants (oversight lists), disseminations, users (super admin only, with step-up), and the four sensitive lists below.
5. **Sensitive lists** (persons, persons of interest, criminal group members, organization contacts):
   - permission `exports.sensitive` (SD, DG, DDG only);
   - step-up and a reason (≥ 20 characters) for **every** export, stored on the run;
   - at most 1,000 rows (`config/buffalo.php`); a separate daily limit of 3 sensitive exports per user, counted in the 8B quotas;
   - columns never include encrypted fields, photos, or anything above the requester's clearance. Persons: name, other names, aliases, gender, approximate age band (for example 30–39, computed from date of birth without revealing it), occupation, roles, LGA, state, classification, last updated. POIs: those plus risk level, status, offence categories, last sighted date, review due. Group members: group, person name, role in group, membership status, since. Contacts: organization, site, name, position, management and security flags (no phone or email).
   - an immediate `critical` notification to the DG and the DDGs covering the requester's jurisdiction, naming the requester, the list type, the row count and the reason (no record content);
   - the exports review screen (`/audit/exports`) highlights sensitive exports.
6. **Non-sensitive lists:** permission `exports.lists` for all five intelligence roles (the existing `reports.export_data` remains for the incident list until it moves into the registry; then fold it into `exports.lists` and report the change). Super admin: users list only.
7. All markings, logging (`record_access_logs` action `export`), quotas, expiry and requester-only downloads exactly as in 7D and 8B.

## Step 11E.3 — Other printable records

Add PDF downloads, through the same pipeline, for single records that do not yet have one: **organization site** (details, security notes, government security, contacts by name and position only, cover and up to 6 photos from 11D), **arrest**, **threat report**, **criminal group** (members only as the requester can see them), and **person** (one record at a time, as on screen, including encrypted fields only if the requester can see them on screen; step-up required, matching the evidence and secret-download rules). Each is logged.

## Step 11E.4 — Permissions

| Permission | DG | DDG | SD | DSD | LGA | SA |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `exports.lists` | ✓ | ✓ | ✓ | ✓ | ✓ | users list only |
| `exports.sensitive` | ✓ | ✓ | ✓ | | | |

Update the matrix, drift tests, `auth.ts` and the route inventory.

## Step 11E.5 — Tests

- Registry coverage test passes; every exclusion has a reason.
- For each exporter: content equals the page's scoped, filtered list; clearance ceiling; rich fields exported as plain text; formula injection neutralised; markings present.
- Sensitive exports: permission, step-up, reason, 1,000-row cap, daily limit, no encrypted fields or photos (check every column and the raw file bytes for a planted NIN and phone), age bands computed without leaking date of birth, DG and DDG alerts with no record content.
- Flashpoint PDF: sketch generated without any network request; only visible linked records; markings.
- Person and site PDFs: content matches the requester's screen view; step-up for person PDFs.
- Quotas and logging for every export type; requester-only download.

## Phase 11 completion checklist

- [ ] The DG downloads a flashpoint PDF with its location sketch and assessment history, and a blackspot register for one state as XLSX.
- [ ] Every list page has an Export button; the coverage test lists no unregistered pages.
- [ ] A State Director exports the persons of interest list for their state: asked for TOTP and a reason; the file has no NIN, phone, address or birth date; the DG receives an alert immediately.
- [ ] A Deputy State Director cannot export persons, but can export communities and arrests.
- [ ] Exports appear on `/audit/exports` with sensitive ones highlighted.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-11e.md` per CLAUDE.md section 9, and `docs/handover/phase-11-summary.md` summarising all of Phase 11: role types, the state lens, theme colours, rich text and the audit-safe migration, arrests, logos and site photos, flashpoint documents, list exporters with the sensitive-list controls, permissions, and open issues. Print both.
