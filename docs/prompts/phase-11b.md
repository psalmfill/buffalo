# BUFFALO — Phase 11B: Rich Text Editing

Read `CLAUDE.md` before starting (stack table: Tiptap and `symfony/html-sanitizer`; the Phase 11 rich text rule). Read `docs/handover/phase-11a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

The client wants text formatting (bold, lists, headings) in the system's long text fields, instead of plain text. Rich text brings two risks this step must control:

1. **Script injection.** HTML from the browser can carry malicious code. Every rich text value is sanitised **on the server** on every save, against a strict allow-list, whatever the client sent.
2. **Breaking the audit trail.** Append-only, hash-chained tables must never be rewritten.

---

## Step 11B.1 — Editor and sanitiser

1. **Tiptap** (MIT): install only the MIT-licensed core packages needed (editor core, React binding, StarterKit or the individual extensions below, Link, Underline, Table extensions, Placeholder, CharacterCount). **No Tiptap Pro extensions, no Tiptap cloud services, no collaboration features.** Report every package and its licence.
2. A shared `RichTextEditor` React component:
   - toolbar: paragraph, heading 3, heading 4, bold, italic, underline, bullet list, numbered list, blockquote, link (add, edit, remove), simple table (insert, add or remove rows and columns), undo and redo, clear formatting;
   - **no** images, colours, fonts, font sizes, embeds or raw HTML editing;
   - paste from Word and web pages keeps only allowed formatting;
   - character counter on the **plain text** length, against the field's limit;
   - keyboard accessible, works in light and dark mode, read-only mode for display.
3. A `RichTextView` component for displaying stored HTML (already sanitised) safely, with links opening in a new tab with `rel="noopener noreferrer"`, and external links visibly marked.
4. **Server-side sanitising** with `symfony/html-sanitizer` (MIT) in a single `RichText` service:
   - allow-list: `p`, `h3`, `h4`, `strong`, `em`, `u`, `ul`, `ol`, `li`, `blockquote`, `a` (`href` only, schemes `http`, `https`, `mailto`, and relative URLs; add `rel` and `target` on output), `table`, `thead`, `tbody`, `tr`, `th`, `td`, `br`;
   - everything else is stripped (including all `style`, `class`, `on*` attributes, `script`, `iframe`, `img`, `svg`, `form`);
   - maximum HTML length (for example 10× the plain text limit) to stop oversized payloads;
   - returns the sanitised HTML **and** a plain text version (block elements become line breaks, list items get "- ").
5. A `RichTextCast` (or equivalent) for models: setting a rich text attribute always runs the sanitiser; the plain text copy is stored alongside in a `<field>_text` column (or one shared plain text column per model if more appropriate; choose and report).
6. Validation rules use the plain text length (`max`, `min`, required = non-empty plain text).

## Step 11B.2 — Which fields become rich text

**Rich text** (long narrative fields):

| Module | Fields |
|---|---|
| Incidents | `description`, `action_taken`, `resolution_summary` |
| Incident follow-ups | `body` (append-only: see 11B.3) |
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
| Flashpoint assessments | `assessment`, `recommended_action` (append-only: see 11B.3) |
| Threat reports | `description`, `recommended_action`, `target_description` |
| Arrests (11C) | `notes` |

**Stay plain text:** titles, names, addresses, references, codes, reasons and justifications (rejections, deletions, amendments, access requests, disseminations), short notes on link rows, status notes and comments, `source_notes` on threats, and every field in dynamic incident type details. If any other long text field exists that is not listed, report it and propose a choice; do not change it silently.

## Step 11B.3 — Data migration and the audit trail

1. **Mutable tables:** convert existing plain text to HTML (escape it, wrap paragraphs in `<p>`, single line breaks to `<br>`), fill the plain text copy, in batches, through the sanitiser. Record the conversion in the handover (rows per table), not as one activity event per row.
2. **Append-only and hash-chained tables** (incident follow-ups, flashpoint assessments, and any other in the list): **never rewrite existing rows.** Add a `body_format` column (`text` or `html`); existing rows are `text` and display exactly as before (with line breaks preserved); new rows are `html`.
   - Check how each table's hash is computed. If adding a column changes the canonical serialisation of existing rows, exclude `body_format` from the hash or include it only for rows where it is `html`, so that **every existing chain still verifies.** Run `buffalo:audit:verify` before and after the migration in the tests, and report the approach.
3. **Search vectors:** every generated `tsvector` and trigram column built from a now-rich field must use the plain text copy, so tags are never indexed. Confirm with tests that searching for a word finds the record and searching for `strong` or `<p>` does not.
4. **Exports and PDFs:** spreadsheets use the plain text copy (with formula-injection protection as before); PDFs and print views render the sanitised HTML.
5. **Notifications and logs:** unchanged; they never contain these fields.
6. **Merging (10A) and consolidation (10B):** combined fields (for example POI `known_offences`) are combined as HTML blocks with a labelled heading per source, then sanitised.

## Step 11B.4 — Tests

- Sanitiser: every disallowed element and attribute stripped, including common XSS payloads (script tags, `javascript:` links, event handlers, `data:` URLs in links, SVG, nested and malformed tags); allowed formatting kept; length limit.
- Saving through every rich text field via HTTP sanitises even when the client sends malicious HTML directly (bypassing the editor).
- Plain text copies correct; validation on plain text length.
- Migration: mutable rows converted with line breaks preserved; append-only rows untouched; `buffalo:audit:verify` passes after migration; old follow-ups display as before.
- Search uses plain text only.
- Exports contain plain text; PDFs render formatting.
- CSP still enforced with no violations on pages with the editor.
- `RichTextView` output contains only allowed markup.

## Acceptance checklist

- [ ] An LGA chief reports an incident with a bulleted list of suspects' descriptions and bold key facts; the incident page, print view and PDF show the formatting; the incident list export shows clean plain text.
- [ ] Pasting a formatted Word paragraph keeps bold and lists but drops fonts and colours.
- [ ] Submitting `<img src=x onerror=alert(1)>` directly to the API saves no image and no handler.
- [ ] Existing follow-ups look exactly as before; new ones support formatting; `buffalo:audit:verify` passes.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-11b.md` per CLAUDE.md section 9 and print it.
