# Phase 11D — Organization Logos and Site Photos

Prompt: `docs/prompts/phase-11d.md`.

## What was built

### 11D.1 Logos

- **Storage:** media collection `logo` on `Organization` (now `HasMedia`), on the private `evidence` disk, under `logo/<media ulid>/logo.<ext>`.
- **Accepted files:** PNG, JPEG or WebP up to 2 MB (`BUFFALO_LOGO_MAX_KB`), checked by content. `OrganizationLogoRequest` runs `file`, `max`, `MalwareFree(UploadKind::OrganizationLogo)`, `mimetypes` and the new `ImageFile` rule (getimagesize; SVG is never an image type there, whatever its name).
- **Re-encoding:** `AddOrganizationLogo` re-encodes through `PhotoSanitiser` (GD), which strips EXIF, XMP, comments and appended data, and scales to fit 512 × 512 px (`BUFFALO_LOGO_MAX_DIMENSION`). A 96 px `thumb` conversion keeps the original format, so PNG transparency survives. It is generated at upload (non-queued), so a new logo shows at once.
- **One live logo:** the previous logo is soft-deleted in the same transaction. Its files stay on disk, it never shows, and its URL is 404. `Organization::logo()` is the live one.
- **Serving:** `GET organizations/{organization}/logo/{media}/{conversion?}` (`organizations.logo.show`) authorises `view` on the organization (`organizations.view`). The media must be this organization's logo (else 404); `conversion` may only be `thumb`. Headers: `Cache-Control: private, max-age=31536000, immutable`, `nosniff`, a sandbox CSP. Super admins get 403. The media ULID is in the URL, so a replacement has a new URL.
- **Upload and remove:** `POST`/`DELETE organizations/{organization}/logo`, which need `update` on the organization (`organizations.manage`). Named events: `logo_added` (properties `logo`, `replaced`; description "Logo added" or "Logo replaced") and `logo_removed`.
- **Shown in:** the organization list, the organization page header (full size, with **Add/Replace/Remove logo** for managers), site pages (next to the organization name), organization pickers (`EntityPicker` results gained an optional `image`), global search hits (`SearchHit` gained `image`), and the organization column of site tables (LGA Organizations tab). `OrganizationLogo` shows a neutral box with up to two initials when there is no logo.

### 11D.2 Site photos

- **Table:** `organization_site_photos` (migration `2026_11_19_000001_add_organization_media`). It has the prompt's columns plus `updated_by`.
  - Its place is bound to the site by `(organization_site_id, lga_id, state_id) → organization_sites` with `ON UPDATE CASCADE`, and `(lga_id, state_id) → lgas`, as for contacts.
  - Partial unique index `organization_site_photos_one_cover`: one live cover per site.
  - CHECK: a caption is null or not blank.
- **Model:** `OrganizationSitePhoto`, with `HasJurisdiction` on its own columns, plus a global scope requiring the site to be visible (which adds the site's classification; no level of its own) and `SoftDeletes`. The file is media `photo` (single file) on the row.
- **Upload (`AddSitePhoto`):**
  - JPEG, PNG or WebP up to 15 MB, by content, malware-scanned (`UploadKind::SitePhoto`).
  - Re-encoded without metadata (EXIF and GPS included) and scaled to at most 2,400 px on the long side. `thumb` (300 px) and `preview` (1,200 px) conversions are queued.
  - The site row is locked (`SELECT … FOR UPDATE`), so the 30-photo limit (`buffalo.organizations.site_photo_limit`, `BUFFALO_SITE_PHOTO_LIMIT`) and the sort order hold under concurrent uploads. The request validates; the action is the backstop.
- **Managing**, by users with `update` on the site (the existing `OrganizationSitePolicy`: `organizations.manage`, own jurisdiction, classification). Each action is a named event on the site, so it shows in the site's History tab:
  - caption and date (`site_photo_updated`, with old and new values);
  - set cover (`site_photo_cover_set`, the old cover unset in the same transaction);
  - reorder (`site_photos_reordered`, with the full list of ULIDs, which must name every live photo exactly once);
  - delete with a reason (`site_photo_deleted`, soft delete; reason required via `DeleteRecordRequest`).
- **Serving:** `organization-site-photos.thumb`, `.preview` and `.original` (`GET organization-sites/{site}/photos/{photo}/<variant>`) authorise `view` on the site. `{site}` and `{photo}` bind through their scopes, and the photo must belong to the site, so out of reach is always 404.
  - Headers: `private, no-store` for confidential and secret sites, `private, max-age=3600` for restricted ones. `original` is an attachment (`site-photo.<ext>`). Until a queued conversion exists, the sanitised original is served.
  - **Access log:** for confidential and secret sites, every photo fetch is logged in `record_access_logs` on the site: action `view` (thumb, preview) or `download` (original), context `photo:<variant>:<photo ulid>`.

### 11D.3 Pages

- **Site page:** `SitePhotoGallery` sits above the overview. Cover first, then the chosen order; thumbnails with captions and dates.
  - Lightbox: preview, caption, date, uploader, previous/next, and "Download original".
  - Managers get a drag-and-drop zone (or file chooser): one request per file, with a progress bar and an error each. They also get move earlier/later, set as cover, edit caption and date (dialog) and delete (reason dialog).
  - The page says when photo views are logged.
- **Site rows** (`SitesTable` on the organization page and the LGA Organizations tab): a cover thumbnail column (an empty frame when none). **Search results** for sites carry the cover thumbnail.
- **Maps:**
  - Site markers (`SitesMap`) and the operational map's sites layer (`PointLayers::sites` → `image`) show the cover in their popups.
  - `BuffaloMap` popups gained an optional image, set as a DOM property, never HTML.
- **Organization page:** logo in the header; each visible site with its cover (the sites table).

### 11D.4 Tests

- `tests/Feature/Organizations/OrganizationLogoTest.php` (15 tests):
  - re-encoding, size and metadata (stored file and thumbnail);
  - content detection, SVG (including SVG named `.png`), PHP named `.jpg`, GIF, over 2 MB;
  - EICAR rejected and reported;
  - every intelligence role in any state sees it; super admin 403;
  - replacing changes the URL (old URL 404, old media soft-deleted, file kept);
  - removing; managers only;
  - logo in the list, page, site page, LGA tab, pickers and search.
- `tests/Feature/Organizations/SitePhotosTest.php` (24 tests):
  - EXIF and GPS stripped from the stored file and both conversions; scaling to 2,400/300/1,200 px;
  - type and size validation including SVG; caption and date validation; EICAR;
  - visibility equals the site's for 11 users × 3 classifications × 3 variants;
  - another state 404; photo under another site 404;
  - place follows the site's LGA change (and cannot diverge);
  - cover uniqueness (application and database); the limit; reorder; edit;
  - delete with reason and cover hand-over;
  - management needs `update`;
  - access logging and cache headers;
  - cover on the site page, LGA tab, map layer and search.
- `resources/js/components/organizations/organization-logo.test.tsx` (Vitest): initials and placeholder.
- **Jurisdiction matrix:** a site photo is added to `MatrixRecords`; the three GET routes are in `ROUTE_PERMISSIONS` (`organizations.view`); the two logo routes are in `EXCLUDED` (national directory, covered by `OrganizationLogoTest`). The route inventory was regenerated (373 routes).
- The existing architecture test (no `getUrl`, `getFullUrl` or `getTemporaryUrl` in `app/`) passes; URLs are built with `route(…, false)`.

## Files

- **New:**
  - Migration: `database/migrations/2026_11_19_000001_add_organization_media.php`
  - Model: `app/Models/OrganizationSitePhoto.php`
  - Actions: `app/Actions/Organizations/{AddOrganizationLogo,RemoveOrganizationLogo}.php`, `app/Actions/Organizations/SitePhotos/{AddSitePhoto,UpdateSitePhoto,SetSiteCoverPhoto,ReorderSitePhotos,DeleteSitePhoto}.php`
  - Controllers: `app/Http/Controllers/Organizations/{OrganizationLogoController,SitePhotoController}.php`
  - Requests and rules: `app/Http/Requests/Organizations/{OrganizationLogoRequest,SitePhotoRequest}.php`, `app/Rules/ImageFile.php`
  - Support: `app/Support/Organizations/{SitePhotos,OrganizationLogos}.php`
  - Frontend: `resources/js/components/organizations/{organization-logo,logo-manager,site-photo-gallery}.tsx`, `organization-logo.test.tsx`
  - Tests: `tests/Feature/Organizations/{OrganizationLogoTest,SitePhotosTest}.php`
- **Changed:**
  - `app/Models/{Media,Organization,OrganizationSite}.php`
  - `app/Support/Persons/PhotoSanitiser.php` (optional fit box, noun in messages)
  - `app/Enums/UploadKind.php`, `config/buffalo.php` (`organizations` block)
  - `app/Actions/Evidence/ScanQuarantinedEvidence.php` (force delete, below)
  - `OrganizationPresenter`, `OrganizationController`, `OrganizationSiteController`, `LgaController`, `PickerController`, `PointLayers`, `OrganizationProvider`, `SearchHit`
  - `routes/organizations.php`
  - Frontend: `types/organizations.ts`, `types/search.ts`, `site-parts.tsx`, `entity-picker.tsx`, `search-hit-row.tsx`, `BuffaloMap.tsx`, `operational-layers.ts`, `pages/organizations/{index,show}.tsx`, `pages/organization-sites/show.tsx`
  - Tests and docs: `MatrixRecords`, `JurisdictionMatrixTest`, `docs/security/route-inventory.md`, README

## Deviations and decisions

1. **Disk:** the existing private `evidence` disk, not a new `media` disk. It is already private, backed up by restic (8C) and laid out by media ULID (`EvidencePathGenerator`). A second disk would need its own volume, backup and restore wiring for no security gain.
2. **Media is now soft-deletable** (`media.deleted_at`, `SoftDeletes` on `App\Models\Media`), as the prompt's "soft-deleted media" requires. The media library keeps files of soft-deleted media.
   - **Conflict found and fixed:** `ScanQuarantinedEvidence` deleted an infected original with `$media->delete()`, expecting the file to go. With soft deletes it would have stayed on the evidence disk (the existing quarantine test caught it). It now calls `forceDelete()`; the infected copy stays on the quarantine disk as before.
   - No other code deletes media; person photos and evidence are never deleted.
3. **"Single file" logo without `singleFile()`:** the media library's `singleFile()` hard-deletes the previous file. The previous logo is soft-deleted instead, in the same transaction, so at most one is live.
4. **Logo thumbnail generated at upload**, not on the queue, so the logo appears at once (one 96 px resize). Site photo conversions are queued as asked. All conversions keep the original's format (transparent PNG logos stay transparent).
5. **Cover rules the prompt left open:** a site's first photo becomes its cover, and deleting the cover passes it to the first remaining photo. Otherwise cards and popups would show nothing until someone chose a cover.
6. **Photo changes are named events on the site** (subject = site, with the photo's ULID), so they appear in the site's History tab. The photo row has no activity subject of its own.
7. **Photo access logs are recorded on the site** (subject = site, context `photo:<variant>:<ulid>`), so "who saw this site" includes its photos.
8. **Restricted sites' photos** may be cached privately for an hour (`private, max-age=3600`); the prompt specified only classified sites (`no-store`).
9. **"Site cards"** are table rows in BUFFALO (`SitesTable` on the organization page and the LGA tab), so the cover is a thumbnail column there. The dashboard's critical-site lists (`OrganizationFigures`) are unchanged; the prompt did not list them.
10. **Reordering** uses "move earlier/later" buttons (keyboard-accessible); drag-and-drop is used for uploading, as the prompt asks.
11. **Managing photos uses `update` on the site** (the existing, tested site policy) rather than a new photo policy.
12. **Site PDF (11D.3):** not built, because the site PDF arrives in 11E. **11E must include the cover and up to 6 others**: `OrganizationSitePhoto::query()->galleryOrder()->limit(7)` through the requesting user's scopes, reading files from media (never URLs).
13. **No new packages.** One existing package was updated for CI's audit: vite `^8.3.3` (see Gates).

## Known issues / notes for the next step

- **Very large phone photos:** `PhotoSanitiser` refuses images over 6,000 px on a side (GD decodes the whole image in memory; the person photo limit since 3A). Phones saving 12 MP (the default on most) are fine, but a 48–50 MP full-resolution photo (about 8,000 × 6,000) is refused with a clear message. Raising the limit needs more PHP memory per upload.
- **Browser check:** I did not try the gallery or logo upload by hand (signing in needs a TOTP account). HTTP tests cover the server; the frontend passes `tsc`, ESLint and Vitest.
- **Deploying:** run the migration and `make wayfinder`. Logos and photos need the queue (Horizon) for photo conversions; until a conversion exists, the sanitised original is served.
- **Phase 11E:** this phase adds no list view, so it needs no new exporter. Remember the site PDF photos (decision 12).

## Gates

- `make test`: 1,842 Pest tests passed (15,677 assertions) and 24 Vitest tests (5 files).
- `make lint`: passed (Pint, Larastan level 7, ESLint, Prettier, `tsc --noEmit`).
- `make ci`: "All CI checks passed." The first attempt failed on `npm audit`: a high-severity advisory published since 11C against **vite 8.3.0–8.3.2** (via `launch-editor`; GHSA-698v-4r3x-3vcv, GHSA-r825-w8mm-5pxw, GHSA-587h-7wmj-h5pm). Not caused by this phase.
  - **Dependency change:** vite `^8.0.0` → `^8.3.3`, locked at 8.3.3. That is the first fixed release (MIT, released 2026-10-06, two weeks old; 8.3.4 is newer than two weeks, so not used). Still in `dependencies`, so the production audit keeps covering it. `npm audit` now reports 0 vulnerabilities, and the rerun passed.
