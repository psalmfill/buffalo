# BUFFALO — Phase 11D: Organization Logos and Site Photos

Read `CLAUDE.md` before starting (organizations decisions; malware scanning; no public media URLs). Read `docs/handover/phase-11c.md` and, for organizations, `docs/handover/phase-4b.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

The client wants organizations to show their **logo**, and each **site** (a head office, a regional office, a terminal) to have **photos**, so officers can recognise a facility.

The Phase 4B split still applies:

- **Logos** belong to the organization's national directory entry, visible to every intelligence role, and must be non-sensitive.
- **Site photos** belong to the site, are jurisdiction-scoped and classified with it, and may show security-relevant details (gates, fences, guard posts).

---

## Step 11D.1 — Logos

1. Media collection `logo` on `Organization` (single file), on the private `evidence` disk (or a dedicated private `media` disk if cleaner; report the choice). Never public URLs (existing architecture test).
2. Accepted: PNG, JPEG, WebP, up to 2 MB, detected by content. **SVG is not accepted** (SVG files can carry scripts).
3. Malware-scanned before acceptance (8B). Re-encoded on upload to strip all metadata; stored at most 512 × 512 px, with a 96 px thumbnail.
4. Served through an authorised route (`organizations.view`), with long private caching and the image's ULID in the URL so replacing the logo changes the URL.
5. Upload and remove by users with `organizations.manage`; both logged as named activity events. Previous logos are kept in history (soft-deleted media), not shown.
6. Shown: organization lists and search results (thumbnail), organization page header, site pages (small, next to the organization name), organization pickers, and the LGA page Organizations tab. A neutral placeholder with the organization's initials when there is none.

## Step 11D.2 — Site photos

1. `organization_site_photos` table: `ulid`, `organization_site_id`, `state_id`, `lga_id` (copied from the site and kept in sync; composite FK with cascade, per access rule 9), `caption` (plain text, max 200), `taken_on` (nullable date), `is_cover` (at most one per site), `sort_order`, `uploaded_by`, `timestampsTz`, soft deletes. Media attached to each row.
2. **Visibility:** a photo is visible exactly when its site is. It inherits the site's classification at all times (no separate level). Users who cannot see the site cannot see or fetch its photos (404).
3. Accepted: JPEG, PNG, WebP, up to 15 MB, detected by content; malware-scanned; **EXIF stripped on storage** (site photos are not evidence; the site already has its location); stored at most 2,400 px on the long side, with `thumb` (300 px) and `preview` (1,200 px) conversions on the queue.
4. Limit: 30 photos per site (`config/buffalo.php`).
5. Upload, caption edit, reorder, set cover and delete (soft) by users who can manage the site; delete requires a reason. All are named activity events. Every photo view is logged when the site's classification is `confidential` or `secret` (like other site views).
6. Served through authorised routes (`thumb`, `preview`, `original` downloads with `organizations.view` + site visibility); `Cache-Control: private, no-store` for classified sites.

## Step 11D.3 — Pages

- **Site page:** a photo gallery (cover first, then by sort order), lightbox using previews, captions and dates, drag-and-drop upload with progress, and management actions for permitted users.
- **Site cards** (organization page, LGA Organizations tab, search results for sites): the cover photo thumbnail.
- **Site popups** on maps: the cover thumbnail when present.
- **Site PDF** (from 11E) includes the cover photo and up to 6 others.
- **Organization page:** logo in the header, and each visible site with its cover photo.

Run `make wayfinder` after route changes.

## Step 11D.4 — Tests

- Content detection, size limits, SVG rejection, malware scanning (EICAR), metadata stripped from logos and site photos (both conversions and stored files).
- Logo visible to all intelligence roles; super admin denied; replacing it changes the URL.
- Site photos: visibility follows the site in every role and classification case; 404 when out of reach; place columns follow a site's LGA change.
- Cover uniqueness, 30-photo limit, reorder, soft delete with reason.
- Access logging for classified sites' photos.
- No `getUrl()` anywhere (existing architecture test).

## Acceptance checklist

- [ ] Upload a company logo; it appears in the organization list, header and pickers for users in any state.
- [ ] Upload 5 photos of a terminal site, set one as cover, reorder them; the cover appears on the site card and the map popup.
- [ ] Users from another state see the logo but get 404 for the terminal's photos.
- [ ] A photo taken with a phone has no GPS data in the stored file.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-11d.md` per CLAUDE.md section 9 and print it.
