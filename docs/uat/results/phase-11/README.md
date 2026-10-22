# Phase 11 browser check — results

**Date:** 2026-10-21
**Run with:** `make browser-check` (Playwright 1.64.0, Chromium headless shell), against the local development stack (`http://localhost:8000`, CSP enforced) with the UAT test accounts.
**Result:** 23 of 23 checks passed, with no console errors, failed requests or CSP violations. On later reruns the same day the sensitive-export check skips, because the State Director's daily quota of three is used up (by design).

The first runs were against the UAT environment (`make uat-up`) and found the problems below. The final passing runs were on the local stack. After the fixes, `make browser-check target=uat` needs a UAT rebuild (`make uat-up`), which was not repeated.

Every check fails on:

- a browser console error;
- an uncaught page error;
- a failed request;
- an HTTP error the check did not expect;
- a Content-Security-Policy violation.

The expected responses are a 428 when step-up is due and a 422 for a TOTP code reused within its window.

## What was checked

| # | Spec | Role | Screens | Screenshots |
|---|---|---|---|---|
| 1 | `01-national-dashboard-and-lens` | DG | National state grid; a state dashboard; the state lens on incidents, the incident map and analytics | 01–05 |
| 2 | `02-theme` | DG | Save a palette colour (applied without reload); a low-contrast colour rejected; reset to defaults | 06–08 |
| 3 | `03-rich-text` | LGA chief | Incident description with toolbar bold and a Word-style paste (bold and lists kept; styles, scripts and images dropped); submit; a follow-up in rich text | 09–11 |
| 4 | `04-arrests` | State Director | "Arrest made" from an incident (pre-filled); a standalone arrest | 12–14 |
| 5 | `05-logos-and-site-photos` | State Director | Logo upload (header and list); site photos: upload three, set a cover, reorder, lightbox; cover on the organization page | 15–20 |
| 6 | `06-exports` | DSD, DG, State Director | Communities CSV and arrests XLSX (markings and "generated for"); DSD not offered persons; flashpoints PDF table; persons of interest as a sensitive list, with a reason and step-up, and no NIN, phone or DOB in the file | 21–26 |
| 7 | `07-pdfs` | State Director | Flashpoint, organization site, arrest and person (step-up) PDFs | 27–30 |
| 8 | `08-community-exports` | DSD | Export buttons on the community page's institutions, figures and notes tabs; an institutions export | 31 |

## Problems found and fixed

| # | Problem | Fix |
|---|---|---|
| 1 | **No redirect toast ever showed.** `redirect()->with('toast', …)` (142 uses) never reached the page under Inertia 3. | `HandleInertiaRequests::handle` forwards the session toast with `Inertia::flash`. Test: `tests/Feature/Shell/FlashToastTest.php`. |
| 2 | **CSP violation when pasting** Word or web content containing inline handlers (`onerror`, `onclick`). Chrome reports the violation as soon as any document parses the attribute, even an inert one. | `cleanPastedHtml` (editor `transformPastedHTML`) strips handler attributes from the tags before parsing, then removes scripts, images, styles and unsafe links. Vitest cases added. |
| 3 | **Theme colours did not apply** after save or reset until a full reload. | The theme page receives the generated CSS and updates the theme `<style>` element. |
| 4 | **"Arrest made" could not be saved** when the incident's point lay outside the LGA boundary: the pre-filled point was refused. | Pre-fill the point only when it lies inside the LGA. Test added to `ArrestsTest`. |
| 5 | **A sensitive export reused the 15-minute step-up window,** although the rule is "step-up every time". | `StepUp::consume` after each sensitive export. Test added to `SensitiveExportsTest`. |
| 6 | **Live updates refused in UAT** (Reverb 4009 "Origin not allowed"): `scripts/uat/up.sh` left the example origin, so report status never updated. | `up.sh` sets `REVERB_ALLOWED_ORIGINS=localhost` (and adds it to existing UAT settings). New go-live item `realtime_origin` checks that the site's host is an allowed origin. |
| 7 | **Map page errors** ("Cannot read properties of undefined (reading '_leaflet_pos')") when a map page was left quickly. | The map's deferred first view report is ignored once the map is removed; programmatic fits are not animated; the map is stopped before removal. |
| 8 | **UAT had no map tiles** (`/tiles/nigeria.pmtiles` 404 on every map page). | Environment gap, not code: the extract was copied into the UAT volume. `browser-check.sh` now refuses to run without it; documented in `docs/uat/README.md`. |

Added as requested: **Export buttons on the community page's tabs** (institutions, vulnerable figures, security notes), filtered to the community.

The local stack also needed `docker compose restart horizon gotenberg`: Horizon was running 13-day-old code and Gotenberg was unhealthy. This was not an application defect.

## Running it

See "Browser check" in `docs/uat/README.md`.

- `make browser-check` (local) or `make browser-check target=uat`.
- The local run creates or refreshes the test accounts and writes their credentials to `.local-check/credentials.txt` (owner-only, git-ignored).

## Package

- `@playwright/test` **1.64.0**, Apache-2.0, released 2026-10-07.
- Dev only, isolated in `tests/browser/package.json`, with its own lockfile.
- It brings `playwright` and `playwright-core` (Apache-2.0) and downloads Chromium (BSD-3-Clause) on first run.
- Nothing is added to the application bundle or the production images.
