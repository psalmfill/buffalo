# Phase 11 summary — post-review changes

Phase 11 delivered the changes requested after the client's review, in five steps. Details are in each step's handover: `phase-11a.md` to `phase-11e.md`.

| Step | Commit | What |
|---|---|---|
| 11A.1 | `3e46f91` | Person role types become an admin-managed lookup |
| 11A.2–4 | `4ab704b` | State lens, national state grid, configurable theme colours |
| 11B | `e1b8b10` | Sanitised rich text for long narrative fields |
| 11C | `f221c01` | Arrests module with offence types and person links |
| 11D | `e929c68` | Organization logos and site photos |
| 11E | `795916c` (plus this summary) | Flashpoint documents, exports for every list, printable records |

## Role types (11A.1)

- `person_role_types` is an admin-editable lookup.
- The five original types are system types: their codes, which code depends on, cannot change, and they cannot be deactivated. Administrators may add types such as youth or market leaders.
- Person roles reference the lookup by id. The LGA and senatorial district pages group community leaders by type, new types included.

## State lens (11A.2)

- National users can focus every page on a state or zone: dashboards, lists, maps and search ranking.
- **It is a default filter only, never a change of scope.** A redirect adds `state=`/`zone=` to list URLs, and an explicit filter always wins.
- `JurisdictionContext::focus()` only narrows the user's own scope.
- The national dashboard gained a state grid with an attention score. `/dashboard/states/{state}` shows any state's dashboard to users who can reach it.

## Theme colours (11A.3)

- Super admins and the DG set brand, accent, chart palette and key-figure tile colours for light and dark mode.
- WCAG contrast is checked on the server and live in the page.
- Severity, classification and urgency colours keep their fixed meanings and cannot be themed.
- Colours are applied as CSS variables, including in PDFs (light mode).

## Rich text and the audit-safe migration (11B)

- Long narrative fields store HTML from a strict allow-list.
- **The server sanitises every save** (`symfony/html-sanitizer`), whatever the browser sent.
- Each field keeps a plain-text copy (`<field>_text`) for search vectors, exports, notifications and character limits.
- The editor is Tiptap (MIT core only). Display builds elements from the stored HTML without `innerHTML`.
- **Append-only, hash-chained tables were never rewritten.** Old follow-ups and assessments keep `body_format` NULL, which is stripped from the hash and means plain text, so `buffalo:audit:verify` passes before and after.

## Arrests (11C)

- A module of its own, with references `ARR/<STATE>/<YEAR>/<5 digits>` (gap-free per state and year).
- An arrest may be linked to an incident. **Arrest made** on an incident pre-fills the form.
- Arrested persons are person references, run the cross-jurisdiction match (`arrest_entry`), and are registered in `PersonReferenceRegistry` with merge and undo rules. Incident consolidation moves arrests.
- Offence types are a lookup mapped to threat categories. Statuses keep an append-only history.
- Suggestions only (casualty mismatch, detain or designate a POI); nothing changes automatically.
- Alerts go out for arrested POIs and escalated incidents.
- Arrests appear in search, dashboards, analytics and the incident, person, LGA and formation pages.

## Logos and site photos (11D)

- **Logos** belong to the national directory entry, so every intelligence role sees them. PNG, JPEG or WebP only, never SVG; checked by content, malware-scanned, re-encoded without metadata, at most 512 px with a 96 px thumbnail. The media ULID is in the URL, and replaced logos are soft-deleted and kept in history.
- **Site photos** are visible exactly when their site is, and carry its classification. EXIF and GPS are stripped; photos are scaled to 2,400 px with queued thumbnail and preview conversions. Up to 30 per site, with cover, order, captions and deletion with a reason.
- Views of classified sites' photos are logged and never cached.
- Media became soft-deletable. Infected evidence is now force-deleted.

## Flashpoint documents (11E.1)

- Each flashpoint or blackspot prints as a PDF with a **location sketch drawn on the server** (LGA outline, point and radius, linked incidents, scale bar, north arrow; no tiles, nothing fetched).
- The PDF includes the assessment history, the visible linked records, and disseminations for owners.
- The flashpoint register is the flashpoints list export; a blackspot register is that export filtered to blackspots.

## List exporters and the sensitive-list controls (11E.2–3)

- **Every list page has an Export button** (XLSX, CSV or PDF table, chosen columns, current filters only).
- A registry of 25 exporters shares each page's own filter rules and scoped query, so a file is exactly the page's list.
- `ListExporterCoverageTest` keeps every list page covered or explicitly excluded with a reason.
- **Pipeline:** every file goes through the 7D report pipeline: built as the requester, marked, logged on download, counted in the 8B quotas, expiring after 30 days, downloadable only by the requester.
- **Sensitive lists** (persons, persons of interest, criminal group members, organization contacts):
  - SD, DG and DDG only;
  - step-up and a stated reason every time;
  - at most 1,000 rows and 3 a day;
  - **no encrypted fields or photos, ever** (ages as bands);
  - an immediate critical alert to the DG and covering DDGs;
  - highlighted on `/audit/exports`.
- **Printable records:** organization sites (with photos; contacts by name and position only), arrests, threat reports, criminal groups, and persons (as on screen, with step-up).

## Permissions added or changed in Phase 11

| Permission | DG | DDG | SD | DSD | LGA | SA | Step |
|---|:-:|:-:|:-:|:-:|:-:|:-:|---|
| `theme.manage` | ✓ | | | | | ✓ | 11A |
| `arrests.view`, `arrests.create`, `arrests.manage` | ✓ | ✓ | ✓ | ✓ | ✓ | | 11C |
| `arrests.delete` | ✓ | ✓ | ✓ | | | | 11C |
| `exports.lists` | ✓ | ✓ | ✓ | ✓ | ✓ | users list only | 11E |
| `exports.sensitive` | ✓ | ✓ | ✓ | | | | 11E |
| ~~`reports.export_data`~~ | | | | | | | removed in 11E (folded into `exports.lists`) |

Logos and site photos use the existing `organizations.view` and `organizations.manage` and the site policy. The state lens needs no permission; it is gated by national jurisdiction.

## Packages added in Phase 11

- **11B:** `symfony/html-sanitizer` (MIT), Tiptap 3.31.4 core packages (MIT), `jsdom` (dev only, MIT; some transitive dev licences need approval, listed in `phase-11b.md`).
- **11D:** vite `^8.0.0` → `^8.3.3` for a published security advisory (MIT).
- **11A, 11C and 11E:** none.

## Open issues

- **Browser checks:** none of Phase 11's new screens were tried by hand. Signing in to the dev stack needs a TOTP account. That covers the state grid and lens, the theme page, the rich text editor, the arrest form, the photo gallery and logo upload, the Export dialog and the Print buttons. Server behaviour is covered by tests (1,914 Pest tests at the end of 11E); the frontend passes `tsc`, ESLint and Vitest. A UAT pass on these screens is recommended.
- **Licences:** jsdom's transitive dev dependencies (BlueOak-1.0.0, CC0-1.0, MIT-0) await your approval (11B).
- **Release signing key:** the committed key is still the TEST key; the owner must generate the real one (Phase 9).
- **Phone photos above 6,000 px on a side** (48–50 MP full resolution) are refused; 12 MP photos are fine (11D).
- **Person PDFs carry no photo** (11E decision 6); ask if one is wanted.
- **The community page's tabs** (institutions, figures, notes) have no Export button yet; the exporters accept a `community` filter.
- **Location sketches** have no outline where no LGA boundary has been imported.
- The **dev database** has a cosmetic stray space in some `_text` copies converted before an 11B refinement; production is unaffected.
