# BUFFALO — Phase 5B: Evidence, Documents and Images

Read `CLAUDE.md` before starting (see the decisions log entries on evidence). Read `docs/handover/phase-5a.md`.

If anything here conflicts with what earlier steps actually built, follow the existing code and report the conflict in the handover.

## Context

Phase 5A built the incident record and its related data. This step adds supporting evidence: images, documents, video and audio. Evidence may later matter in investigations or prosecutions, so **integrity and chain of custody** matter as much as access control.

Principles:

- **Originals are stored exactly as uploaded,** never modified, with a SHA-256 hash recorded at upload and verified on every download.
- **Previews are separate files,** EXIF-stripped, used for on-screen viewing.
- **Every access to an original is logged.**
- **Evidence has its own classification,** which may be higher than the incident's, never lower.

---

## Step 5B.1 — Evidence table

`incident_evidence` table:

| Column | Notes |
|---|---|
| `id`, `ulid` | |
| `incident_id` | FK |
| `state_id`, `lga_id` | copied from the incident and kept in sync (access rule 9); composite FK |
| `title` | required, max 200 |
| `description` | nullable text |
| `evidence_type` | enum `image`, `document`, `video`, `audio`; derived from the detected content type, not chosen |
| `original_filename` | stored for reference; never used as a storage path |
| `mime_type` | detected from content |
| `size_bytes` | |
| `sha256` | hex, required, computed server-side while storing |
| `captured_at` | nullable `timestampTz`; pre-filled from EXIF `DateTimeOriginal` when present, editable |
| `captured_location` | nullable point; pre-filled from EXIF GPS when present, editable |
| `exif_summary` | nullable jsonb: camera make/model, original timestamp, GPS; extracted once at upload |
| `classification` | default: the incident's classification; must be ≥ the incident's and within the uploader's clearance |
| `uploaded_by` | FK users |
| `created_by`, `updated_by`, `timestampsTz`, soft deletes | |

- `HasJurisdiction`, `HasClassification`, `LogsBuffaloActivity`, `LogsReads` (**all** evidence views, any classification).
- If the incident's classification is raised above an evidence item's level, raise the evidence to match (same transaction, logged).
- Media attached via medialibrary to `IncidentEvidence` (collection `original`, single file), on the private `evidence` disk. **Never** call `getUrl()` (existing architecture test).
- Deletion: soft delete only, SD and above, reason required. The stored file is **kept** (evidence is never physically destroyed through the application).

## Step 5B.2 — Accepted files

| Type | Accepted content types | Max size |
|---|---|---|
| image | JPEG, PNG, WebP, HEIC (if the PHP image stack can generate previews; otherwise report and exclude) | 25 MB |
| document | PDF, DOCX, XLSX, plain text | 25 MB |
| video | MP4, WebM | 100 MB |
| audio | MP3, M4A, OGG, WAV | 50 MB |

- Detect the type from file content (magic bytes), reject mismatches between extension and content, and reject everything else.
- Limits in `config/buffalo.php` (`evidence.*`), within nginx's existing 100 MB body limit.
- Uploads go through a queued job for hashing and preview generation when the file exceeds 10 MB; the evidence record shows "processing" until done.

## Step 5B.3 — Previews

- **Images:** `thumb` (300px) and `preview` (1600px) conversions, EXIF stripped, generated on the queue.
- **PDF:** first-page thumbnail only if achievable with the existing PHP stack without new system packages; otherwise a generic document icon. Report which.
- **Video and audio:** no transcoding. Played in the browser from the original through the streaming route (5B.4), with range support. Show a generic icon as thumbnail.
- **DOCX/XLSX:** no preview; download only.

## Step 5B.4 — Serving files

Routes, each authorised by the evidence policy (jurisdiction + classification + `incidents.view`), returning **404** when out of reach:

| Route | Serves | Headers | Logged as |
|---|---|---|---|
| `/evidence/{ulid}/thumb` | image thumbnail | `inline`, `private, no-store` | not logged individually (the evidence list view is logged) |
| `/evidence/{ulid}/preview` | image preview (EXIF-stripped) | `inline`, `private, no-store` | `view` |
| `/evidence/{ulid}/stream` | video/audio original, with HTTP range support (use a response type that supports ranges) | `inline`, `private, no-store` | `view` (once per session per item, not per range request) |
| `/evidence/{ulid}/download` | the original, unaltered | `attachment` with a safe generated filename, `X-Content-Type-Options: nosniff` | `download` |

- On download, recompute the SHA-256 and compare. On mismatch: refuse the download, log a `integrity_failure` activity event, and notify the DG and super admins (`critical`; payload: evidence ULID only). This is the only evidence event a super admin is told about.
- PDFs and office documents are always served as `attachment`, never rendered inline.
- Permission `evidence.download_original` is required for `/download` (5B.6).

## Step 5B.5 — Chain of custody view

On each evidence item, a **Custody** section listing, from the activity log and `record_access_logs`: upload (who, when, hash), metadata edits, classification changes, every view and download (who, when, IP), and any integrity check failure. Read-only. Include a "Verify integrity" button (SD and above) that recomputes the hash without downloading, and logs the result.

## Step 5B.6 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `evidence.upload` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `evidence.download_original` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `evidence.verify` | ✓ | ✓ | ✓ | | |
| `evidence.delete` | ✓ | ✓ | ✓ | | |

Uploading is allowed on any incident the user can update, and also after submission (5C) until the incident is `closed`. Super admin: none. Update the matrix, drift tests and `auth.ts`.

## Step 5B.7 — UI

On the incident form and the incident detail page, an **Evidence** section:

- Drag-and-drop multi-file upload with per-file title, description and classification (`ClassificationSelect`, limited to ≥ incident level and ≤ user clearance), progress bars, and processing state.
- Gallery grid for images (thumbnails, lightbox using previews), list for documents, audio and video (inline players via the stream route).
- Each item: metadata, EXIF-derived capture time and location (shown on a small map when present), classification badge, download original, custody section.
- Items above the viewer's clearance are simply absent.

Run `make wayfinder` after route changes.

## Step 5B.8 — Tests

- Content-type detection: accepted types pass; renamed executables, mismatched extensions and oversize files are rejected.
- The stored original is byte-identical to the upload; SHA-256 recorded; tampering with the stored file makes download fail, logs `integrity_failure` and notifies the DG and super admins.
- Previews contain no EXIF; originals keep theirs; EXIF capture time and GPS extracted.
- Classification: cannot be below the incident's or above the uploader's clearance; raising the incident raises evidence; items above clearance are invisible and their routes return 404.
- Scope matrix for evidence; evidence carries its own jurisdiction.
- Every route: 404 out of reach; correct headers; PDFs as attachment; range requests work for video.
- Logging: preview and stream views logged (stream once per session); every download logged.
- Deletion: SD+ only, reason required, file kept on disk.
- No `getUrl()` anywhere (existing architecture test still passes).

## Acceptance checklist

- [ ] Upload a phone photo with GPS, a PDF, and a short MP4 to the draft kidnapping from 5A; the photo's capture time and location are pre-filled and shown on a map.
- [ ] Download the original photo and confirm its hash matches the recorded value and it still has EXIF; the on-screen preview has none.
- [ ] Mark one image `secret`; a Deputy State Director does not see it at all.
- [ ] Alter a stored file on disk; downloading it fails and the DG is notified.
- [ ] The custody section lists every view and download.
- [ ] `make test` and `make lint` pass.

## Out of scope

Workflow and notifications (5C). Malware scanning (Phase 8 will consider ClamAV with offline signature updates).

## Handover

Write `docs/handover/phase-5b.md` per CLAUDE.md section 9 and print it.
