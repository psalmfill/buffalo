# Phase 11B — Rich text editing

Prompt: `docs/prompts/phase-11b.md`.

## What was built

### 11B.1 Editor and sanitiser

- **`RichText` service** (`app/Support/RichText/RichText.php`) is the one place rich text is sanitised. It uses `symfony/html-sanitizer` with a strict allow-list: `p h3 h4 strong em u ul ol li blockquote br table thead tbody tr th td` and `a`. Links keep `href` only (schemes `http`, `https`, `mailto`, plus relative URLs) and always get `rel="noopener noreferrer"` and `target="_blank"`. Every other attribute is dropped, including `style`, `class` and `on*`.
  - **Dropped with their content:** `script`, `style`, `iframe`, `img`, `svg`, `math`, `form` and its controls, `object`/`embed`, media, `template`, `noscript`, `meta`/`base`/`link`, and anything not listed.
  - **Unwrapped (text kept):** formatting wrappers pasted from Word or web pages (`span`, `font`, `code`, …).
  - **Renamed before sanitising:** `b`/`i` become `strong`/`em`, `h1`/`h2` become `h3`, `h5`/`h6` become `h4`, and `div`/`section`/`pre`/… become `p`. Only tag names change in this step; the sanitiser still decides what stays.
  - **Size limit:** HTML longer than `buffalo.rich_text.max_html_length` (200,000) is refused before sanitising.
  - **Output:** a `RichTextValue` holding the HTML and a **plain text copy**. In the copy, blocks become line breaks (a blank line between paragraphs), list items become "- " lines (nested ones indented), and table cells are joined with " | ".
  - **Helpers:** `fromPlainText()` (escape, `<p>` per paragraph, `<br>` per line break), `combine()` (merges), `fromStored()` (snapshots that may predate 11B), and `display()` (re-sanitises for Blade output).
- **`RichTextCast` + `HasRichText`**: models list their fields in `richTextFields()`. Setting a field always sanitises it and fills **`<field>_text`**, one plain text column per field. I chose per-field columns because search vectors, exports and validation each need a specific field's text. Empty rich text (formatting only) is stored as null in both columns. The copies are hidden from serialisation and left out of the activity log, since the field's own change is already logged.
- **`RichTextRule`** validates the plain text: `max`, `min`, and "required" meaning some text (formatting alone is empty). It also caps the HTML at `html_factor` × limit (10×; `BUFFALO_RICH_TEXT_HTML_FACTOR`).
- **`RichTextEditor`** (`resources/js/components/rich-text/`; Tiptap, MIT core only):
  - **Toolbar:** paragraph, heading, subheading, bold, italic, underline, bulleted and numbered lists, quote, link (add, edit and remove in an inline field, with no browser dialogs), table (insert, add or remove rows and columns, remove the table), undo, redo and clear formatting.
  - **Schema** (`extensions.ts`): exactly the allow-list, so pasted images, colours, fonts and code are dropped.
  - **Character counter:** counts plain text with `plainText()` in `resources/js/lib/rich-text.ts`, which mirrors the server, so the browser count equals the validated count.
  - **Accessibility:** a toolbar role with labelled, pressed-state buttons. The editor is named by its `<label for>` and focuses when the label is clicked.
  - **Modes:** light and dark styles (`.rich-content` in `app.css`) and a read-only mode.
  - **CSP:** the injected base styles carry the CSP nonce.
- **`RichTextView`** builds React elements from the stored HTML node by node, with no `innerHTML`. Anything outside the allow-list renders as text only, and script and style content never renders. Links open in a new tab with `rel="noopener noreferrer"`, and off-site links show an icon plus "(external link)" for screen readers. `format="text"` shows pre-11B append-only rows exactly as before, with line breaks kept.

### 11B.2 Fields converted

| Module | Fields |
|---|---|
| Incidents | `description`, `action_taken`, `resolution_summary` |
| Incident follow-ups | `body` (append-only) |
| LGA profiles | `history`, `economic_activities`, `major_roads`, `waterways`, `borders`, `general_notes` |
| Communities, religious institutions | `notes` |
| Community security notes | `description` |
| Persons | `description` |
| Persons of interest | `known_offences`, `modus_operandi` |
| Criminal groups | `description` |
| Agency formations | `notes` |
| Coverage reports | `summary`, `gaps_identified`, `recommendations` |
| Organizations | `description` |
| Organization sites | `security_notes` |
| Flashpoints | `description`, `recommended_action` |
| Flashpoint assessments | `assessment`, `recommended_action` (append-only) |
| Threat reports | `description`, `recommended_action`, `target_description` |

Plain-text limits are unchanged from before (for example incident description 20,000, follow-up 20,000, threat target 2,000).

**Arrests (11C)** do not exist yet. When 11C builds them, `notes` should use `HasRichText`, `RichTextRule` and the editor.

**Other long text fields found and left as plain text (my proposal; nothing changed silently):**
- `incident_evidence.description`, `incident_persons.unidentified_description`, `incident_properties.description`: short labels on child rows, already shown in lists and consolidation summaries.
- `coverage_report_deployments.areas_covered` / `remarks`, `criminal_group_members.notes`, `criminal_group_areas.notes`, `organization_contacts.notes`, `organization_site_formations.notes`, `person_associations.notes`, `person_roles.notes`, `incident_agencies.notes`, `incident_criminal_groups.notes`: short notes on link rows ("stay plain text" in the prompt).
- `persons_of_interest.last_known_location_description`, `incidents.location_description`: location descriptions.
- Reference-data `description` columns, `golive_decisions.details`, `uat_feedback.text`: configuration, decisions and feedback, not intelligence narrative.
- Unchanged as required: every reason or justification, status note or comment, `source_notes` on threats, and dynamic incident type details.

### 11B.3 Data migration and the audit trail

Migration `2026_11_17_000001_add_rich_text`:

- **Mutable tables:** adds the `_text` columns, then converts existing plain text through `RichText::fromPlainText` (escaped, paragraphs in `<p>`, line breaks as `<br>`) in batches of 500. It writes no per-row activity events.
  - **Locked rows:** closed and invalid incidents and reviewed coverage reports refuse updates. The migration disables only `incidents_locked` and `coverage_reports_locked`, inside its own transaction, for this conversion. The content is unchanged; only its format changes. The locks are on again before the migration ends, and a test proves a converted closed incident is still locked.
  - **Rows converted on the development database:** incidents 50,047 (mostly synthetic performance data); lga_profiles 1; communities 18; religious_institutions 0; community_security_notes 3; persons 0; persons_of_interest 2; criminal_groups 2; agency_formations 0; coverage_reports 2; organizations 0; organization_sites 0; flashpoints 2; threat_reports 5. The migration prints these counts per table when it runs in production.
- **Append-only, hash-chained tables** (`incident_updates`, `flashpoint_assessments`) are never rewritten. They get a nullable `body_format` (CHECK `text`/`html`) and nullable plain-text copies (`body_text`; `assessment_text`, `recommended_action_text`).
  - **Hash approach:** the chain's canonical row is `jsonb_strip_nulls(to_jsonb(row))` (Phase 8A), so new columns left NULL on existing rows leave their hashes unchanged.
  - Existing rows therefore keep `body_format = NULL`, which means plain text, and are shown exactly as before. New rows are written as `'html'`, which is included in their hash from the start.
  - `buffalo:audit:verify` passed on the development database before and after the migration ("Every audit chain is intact"). `RichTextMigrationTest` runs the migration down and up again with pre-11B rows in between and checks every chain before and after.
- **Search vectors:** `incidents`, `flashpoints` and `threat_reports` `search_vector` now use `description_text` (`ALTER COLUMN … SET EXPRESSION`, PostgreSQL 18). Trigram columns are built from names only and were unaffected. Tests confirm a word in the text is found, while `strong`, `<p>` and `li` find nothing.
- **Exports and PDFs:**
  - **PDFs:** the incident, coverage and LGA profile PDFs render the sanitised HTML (re-sanitised on output with `RichText::display`), with print styles in `report.css`. Old follow-ups appear in the PDF timeline as escaped plain text with their line breaks.
  - **Print view:** the incident print view is a React page and uses `RichTextView`.
  - **Spreadsheets:** no current export contains these fields (the incident list export deliberately has no free text; a test confirms no markup appears). Exporters added later (Phase 11E) must use the `_text` copies.
- **Notifications and logs:** unchanged. No notification contains these fields; I checked them all.
- **Merges and consolidation:**
  - **POI combination:** combining POI `known_offences` and `modus_operandi` now gives one block per record under a heading ("Surviving record …", "From merged record …"), sanitised and written together with the plain-text copies so an undo restores both.
  - **Merge snapshots:** a description from a pre-11B merge snapshot is converted on undo (`fromStored`). Merge review and undo pages compare and show the description as plain text.
  - **Consolidation:** a copied follow-up is a new row, so it is HTML. A pre-11B original is converted with its line breaks kept; the original row is untouched.
- **Other write paths that bypass the cast:**
  - `AssessFlashpoint`'s raw insert now sanitises and writes the format and copies.
  - The demo incident seeder writes HTML with its copy.
  - Community CSV imports convert plain-text notes.
  - The incident status history comment written with a resolution stays plain text: it gets the summary's plain copy.

### 11B.4 Tests

- `tests/Feature/RichText/SanitizerTest.php`: 20 XSS payloads stripped (script, `img onerror`, event handlers, `javascript:` including obfuscated forms, `vbscript:`, `data:` links, SVG, `iframe` with `srcdoc`, forms, `object`/`embed`, style tag and attribute, MathML, nested and malformed markup, comment tricks, `meta` refresh, `base`, `noscript`). Also: allowed formatting kept, Word paste, plain text rules, conversion, size limit, plain-text validation, combine, and stable re-sanitising.
- `RichTextFieldsTest.php`: every field above saved over HTTP with malicious HTML sent directly (17 scenarios covering all 30 fields), checking the stored HTML and the plain copy. Also checks new append-only rows are `html`, the resolution's history comment is plain, and limits and required count plain text.
- `RichTextMigrationTest.php`: the conversion keeps line breaks, the closed incident stays locked, the append-only row is byte-for-byte unchanged (same `row_hash`), every chain verifies before and after, old follow-ups display as plain text, and new ones are HTML.
- `RichTextOutputTest.php`: search uses plain text only (incidents, threats, flashpoints, global search); the incident PDF renders formatting and old follow-ups as escaped text; the incident list export has no markup; the strict CSP is unchanged on the editor page and stored HTML travels only as JSON props.
- `resources/js/components/rich-text/rich-text.test.tsx` (Vitest, jsdom): `RichTextView` outputs only allowed markup even from a tampered value, links are safe and marked, plain rows are unparsed; `plainText()` matches the server; the editor schema keeps bold and lists from Word paste and drops fonts, colours, images and unsafe links.
- Updated: `ConsolidationTest` (copied follow-ups are HTML), `MergeRulesTest` (labelled POI blocks), `MergeSafetyTest` (description edits are rich text).

## New packages

| Package | Version | Licence | Why |
|---|---|---|---|
| `symfony/html-sanitizer` | ^8.1 (8.1.8) | MIT | Server-side sanitising (named in CLAUDE.md). Its `league/uri` dependency was already installed. |
| `@tiptap/core`, `@tiptap/react`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extension-table`, `@tiptap/extensions` | 3.31.4 (exact) | MIT | The editor (named in CLAUDE.md). Pinned to a release two weeks old rather than yesterday's 3.32.0. In Tiptap 3, Link, Underline and UndoRedo come with StarterKit, and Placeholder and CharacterCount live in `@tiptap/extensions`. |
| Transitive (43) | | all MIT | Tiptap extension packages (blockquote, bold, lists, link, underline, table, …), `prosemirror-*`, `orderedmap`, `rope-sequence`, `w3c-keyname`, `linkifyjs`, `fast-equals`, `use-sync-external-store`. No Tiptap Pro, cloud or collaboration packages. |
| `jsdom` (dev only) | 30.1.1 (exact) | MIT | A DOM for the Vitest tests of `RichTextView` and `plainText()`; the existing Vitest setup is Node-only. **Its transitive dev dependencies include licences outside MIT/BSD/Apache/ISC, which need your approval:** `lru-cache` (BlueOak-1.0.0; already in the lockfile through `minimatch`), `mdn-data` (CC0-1.0, a data file), `@csstools/color-helpers` and `css-syntax-patches-for-csstree` (MIT-0). All are permissive and dev-only (never shipped). The rest are MIT, BSD-2/3, ISC or Apache-2.0. |

I did not use CharacterCount's own counter: it counts the editor's text differently from the server. The counter uses `plainText()` so both sides agree exactly.

## Deviations and decisions

1. **`body_format` on old rows is NULL, not `'text'`.** Writing `'text'` would change their canonical row and break every existing chain. NULL is stripped from the hash and means "plain text". The column's CHECK allows `text` and `html`; the application writes only `html`.
2. **Locked records are converted.** Closed and invalid incidents and reviewed coverage reports keep their content, now in HTML, so they display consistently. The alternative was a per-record format flag on every mutable table. The lock triggers are lifted only inside the migration's transaction.
3. **One plain-text column per field** (`<field>_text`) rather than one per model.
4. **The renaming pre-pass** (`b` → `strong`, `div` → `p`, …) is an addition, so pasted structure keeps its meaning instead of collapsing into one run of text.
5. **Links are stored with `rel` and `target`** (forced by the sanitiser) and enforced again by `RichTextView`.
6. **The resolution summary's status history comment stays plain text** (it is a status comment). It receives the summary's plain copy.
7. **Undo of merges made before 11B:** POI text combined before the migration was converted with the rest, so it no longer matches the manifest's "after" value. Undo then keeps the current text for that column (the existing "edited since the merge" rule) rather than writing stale plain text back. Person descriptions from such snapshots are converted.

## Known issues / notes for the next step

- I did not test the editor by hand in a browser: signing in to the dev stack needs an account with TOTP. Automated checks cover the schema (paste), the view, the CSP header and server sanitising. A manual check of the toolbar, keyboard use, dark mode and a real Word paste is worth doing in UAT.
- **Dev data detail:** the development database was converted before a refinement to the plain-text copy (no leading space after a line break). Its existing `_text` copies may have a stray space after line breaks. Fresh databases and production are unaffected.
- Exporters added in 11E must use the `_text` copies, and any new rich field needs `HasRichText`, `RichTextRule`, the editor and `RichTextView`, plus a scenario in `RichTextFieldsTest`.
- Arrests `notes` (11C): see above.

## Gates

- `make test`: **passed**, 1,779 Pest tests (14,759 assertions) and 21 Vitest tests.
- `make lint`: **passed** (Pint, Larastan level 7, ESLint, Prettier, `tsc`).
- `make ci`: **passed** ("All CI checks passed"; 1,779 tests in the clean copy). The first CI run exposed a pre-existing flaky test unrelated to rich text: `AuditLogTest` read two activity rows without `ORDER BY`. It now orders by id (the same fix as `ReferenceDataHttpTest` in 10B).
- This CI run includes the Phase 11A code, so 11A's outstanding `make ci` gate is now met as well (recorded in `phase-11a.md`).
