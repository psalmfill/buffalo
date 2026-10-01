# Handover — Phase 5B: Evidence, documents and images

Date: 2026-10-01

## Summary

Incidents can now carry **evidence**: images, documents, video and audio.

- **Originals** are stored exactly as uploaded, with a SHA-256 hash recorded at upload and checked before every download.
- **Previews** are separate, EXIF-stripped files.
- **Access:** every view and download is logged; each item shows its full chain of custody.
- **Classification:** each item has its own, never below its incident's.
- **Integrity failures** refuse the download and alert the DG and super admins.

Final checks:

- `make test` passes: 1144 Pest tests (6980 assertions) and the Vitest suites.
- `make lint` passes.
- `make ci` passes (1144 Pest tests in a clean copy).
- The browser acceptance checklist passes (below).

## Acceptance checklist

All on dev, on the draft kidnapping from 5A (`01m3szqbfm628hm4b7p7j7zktt`, Demo LGA LA-1-1).

- [x] **Upload a phone photo with GPS, a PDF and a short MP4.**
  - The photo is a JPEG with EXIF: camera, `DateTimeOriginal` 21:05, GPS near Apapa.
  - The capture time (30 Sept 2026, 21:05) and location (6.44740, 3.36400) were pre-filled. The location shows on a small map with the pin at Apapa.
  - The uploads were made as `dsd.lagos`, because the browser session was still the Deputy's. A Deputy of the state may add evidence to the draft.
- [x] **Download the original photo.**
  - In the browser, its SHA-256 matched both the recorded value shown on the page and the hash of the generated file.
  - The download still has its EXIF (camera marker and GPS).
  - The on-screen preview has no EXIF segment.
  - The MP4 streamed with `Range: bytes=0-1023`: 206, `Content-Range: bytes 0-1023/300000`, `video/mp4`. A PDF's stream route is 404.
- [x] **Mark one image `secret`; a Deputy State Director does not see it.**
  - `sd.lagos` uploaded a photo as `secret`. For `dsd.lagos` it is absent from the page: neither its ULID nor its title appears in the HTML. Every route (show, thumb, preview, download, custody) is 404.
  - **Note:** `sd.lagos` also raised one of the *Deputy's own* uploads to `secret`, and the Deputy still sees that one. This is the CLAUDE.md §4a creator rule ("and the record's creator"), not a defect.
- [x] **Alter a stored file on disk.**
  - I appended bytes to the photo's original on the evidence volume, then downloaded it as `dsd.lagos`.
  - The download was refused (redirect back to the incident, with an error).
  - `integrity_failure` was logged with context `download`.
  - Notifications went to `dg@buffalo.local` (with a link) and both super admin accounts (no link). No download was logged.
- [x] **The custody section lists every view and download.** For the photo: 17 entries (1 upload, 13 views, 2 downloads, 1 integrity failure), matching `record_access_logs` and the activity log exactly. Each entry has who, when and IP address.
- [x] **`make test` and `make lint` pass.**

## What was built

### 5B.1 — `incident_evidence`

**Migration `2026_10_07_000001_create_incident_evidence_table`**

- The prompt's columns, plus `processed_at` (see deviations).
- `state_id`/`lga_id` use a composite FK to the incident, with ON UPDATE CASCADE (the 5A pattern).
- CHECKs on the type, the classification, the SHA-256 (64 lowercase hex characters), the size (> 0) and `exif_summary` (an object).
- `buffalo_classification_rank(text)` is a new SQL helper.
- Two triggers:
  - `incident_evidence_not_below_incident`: an item is never saved below its incident;
  - `incidents_not_above_evidence`: an incident cannot be raised while items are below the new level.

**`IncidentEvidence` model**

- Extends `IncidentChild`: its own jurisdiction, and visible only while its incident is.
- Adds `HasClassification`, `LogsReads` and `InteractsWithMedia`.
- One media item in the `original` collection on the `evidence` disk (single file).
- `thumb` (300 px) and `preview` (1600 px) JPEG conversions, for images only, on the queue.

**Classification raise**

- `RaiseEvidenceClassification` raises the items, logged as `classification_changed` for each.
- `SaveIncident::update` calls it **before** saving a raised incident, in the same transaction; the trigger refuses the incident otherwise.

**Deletion**

- `DeleteEvidence` is a soft delete with a reason (`evidence_deleted`). medialibrary removes files only on force delete, which the application never does, so the file stays.

### 5B.2 — Accepted files

- **`EvidenceFileInspector`** detects the type with libmagic (`finfo`) and requires the extension to agree.
- **Accepted:** JPEG, PNG, WebP, PDF, DOCX, XLSX, plain text, MP4, WebM, MP3, M4A, OGG, WAV.
- **Refused:** everything else, including renamed executables and scripts, SVG, HTML, archives and empty files. Tested with real magic bytes.
- **Limits:** `config('buffalo.evidence.max_mb')`: image and document 25, video 100, audio 50 (`BUFFALO_EVIDENCE_*_MB`). These sit within nginx `client_max_body_size 100M` and PHP `upload_max_filesize 100M`.
- **medialibrary config:**
  - `allowed_extensions` now includes the evidence extensions; it was photos only;
  - `max_file_size` was raised from 10 MB to 100 MB. Person photos keep their own 10 MB rule.
- **HEIC is excluded:** the PHP image stack has GD only (no Imagick, no libheif), so no preview could be made.

### 5B.3 — Previews

- **Images:** GD re-encodes the conversions, which drops all EXIF. Tested: no Make, Model, GPS or time in either conversion; the original keeps them.
- **PDFs:** a **generic document icon**. A first-page thumbnail would need Imagick plus Ghostscript (or poppler), which are not in the stack.
- **Video and audio:** generic icons; played from the original through the stream route.
- **DOCX/XLSX:** download only.
- **No fallback:** until a conversion exists, its route is 404 and the page says "Preparing preview…". The original, which keeps its EXIF, is never shown in its place.

### 5B.4 — Serving files (`EvidenceFileController`)

- Each route authorises through `IncidentEvidencePolicy`. `{evidence}` is bound through its scopes, so anything out of reach is 404.
- Every response is `private, no-store`, `nosniff`, with a CSP of `default-src 'none'; sandbox`.

| Route | Behaviour |
|---|---|
| `thumb` | Inline JPEG. Not logged individually; listing the item on the incident page is logged as `view`/`incident_page`. |
| `preview` | Inline JPEG, logged `view`/`preview`. |
| `stream` | Video and audio only. `BinaryFileResponse` handles ranges. Logged `view`/`stream` once per item per session (session key). |
| `download` | Needs `evidence.download_original`. SHA-256 recomputed first. Served as `attachment; filename=evidence-<ulid>.<ext>` and `application/octet-stream`; never the uploader's file name. Logged `download`. |

On a hash mismatch, or a missing file, the download is refused, `integrity_failure` is logged, and `EvidenceIntegrityFailure` goes to the DG and super admins:

- critical severity;
- the payload is the evidence ULID only;
- super admins get no link.

### 5B.5 — Chain of custody

`GET /evidence/{ulid}/custody` (JSON, lazy-loaded in the item dialog) merges the activity log and `record_access_logs`, oldest first:

- upload (uploader, hash, size, type);
- detail edits (field names);
- capture location changes;
- classification changes;
- integrity verified or failed;
- removal (reason);
- every view (with context) and download, with IP addresses.

Names come from `OfficerNames`, the sanctioned names-only lookup.

**Verify integrity** (`evidence.verify`) recomputes the hash without downloading and logs `integrity_verified` or `integrity_failure`; a failure notifies as above. Evidence activity also appears in the incident's History tab.

### 5B.6 — Permissions

| Permission | DG | DDG | SD | DSD | LGA |
|---|:-:|:-:|:-:|:-:|:-:|
| `evidence.upload`, `evidence.download_original` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `evidence.verify`, `evidence.delete` | ✓ | ✓ | ✓ | | |

Updated together: the seeder matrix, `RolesAndPermissionsTest`, `types/auth.ts` and the navigation test. Re-seeded on dev (57 permissions).

`IncidentEvidencePolicy`:

- **view:** `incidents.view`, the item's own jurisdiction and classification, and its incident visible. It fails closed when the scoped incident relation is null.
- **upload:** on a draft, anyone who can update it; once submitted (5C), anyone who can view it; never once closed.
- **update:** the uploader, or the SD/DSD of the state, until the incident is closed.

### 5B.7 — UI

`components/evidence/evidence-section.tsx`, on the incident page, and on the form once the draft is saved (outside the incident `<form>`):

- drag-and-drop or "Choose files", several at once;
- per file: title, description, and classification. `ClassificationSelect` gained a `minimum` prop, so only levels from the incident's up to the user's clearance are offered;
- uploads use `XMLHttpRequest`, one per file, with progress bars and per-field errors;
- an image gallery (thumbnails), and a list for documents, video and audio, with "processing" shown for queued items.

`components/evidence/evidence-details.tsx`, the item dialog:

- the preview image or an inline player (stream route);
- metadata, EXIF camera and capture time, and the capture location on a small map;
- the recorded SHA-256;
- Download original, Verify integrity, Edit details (title, description, capture time and place, classification), and Remove (reason);
- the custody list.

## Deviations and decisions

1. **The SHA-256 is always computed in the request**, from the uploaded bytes. The prompt makes `sha256` required, but also queues "hashing" above 10 MB, which would leave it empty. For files over `queue_above_mb` (10), `ProcessEvidenceJob`, run as the uploader:
   - verifies that the stored copy matches the recorded hash;
   - sets `processed_at`.

   Until then the item shows "processing" and cannot be downloaded from the page. Image conversions are always queued (medialibrary).
2. **`processed_at` was added** to represent "processing"; the prompt's table had no column for it.
3. **Capture location is not limited to the incident's LGA:** a photo can be taken elsewhere. Latitude and longitude are range-checked only. A location change is logged with coordinates; the raw geography column is excluded from the activity diff.
4. **Each evidence item listed on the incident page is access-logged** (`incident_page`), to satisfy "all evidence views". This adds one log row per item per page load, and is why thumbnails are not logged separately.
5. **The incident History tab includes evidence activity,** through the scoped query, so only visible items appear.
6. **Real-footage playback was not exercised in the browser:** the environment has no video encoder (no ffmpeg), and I did not record the screen. The acceptance MP4 is structurally valid (real `ftyp`/`mdat` boxes, random payload). Upload, detection, range streaming and logging were verified with it; a playable clip should be checked during UAT.

## New packages

None. `finfo`, `exif` and GD were already in the PHP image.

## Bug fixed during acceptance

The file picker read the live `FileList` inside a deferred state update after clearing the input, so a second selection could arrive empty. It now copies the files first.

## Known issues and notes for 5C

- **Custody ordering:** entries are sorted by timestamp. `record_access_logs.created_at` is a plain `timestamp` written in app time, and activity times are `timestamptz`; both display correctly. Entries in the same second may interleave.
- **Uploads once submitted:** any viewer of a submitted incident with `evidence.upload` may add evidence, per the prompt. 5C should confirm this matches the workflow.
- **Malware scanning** is out of scope (Phase 8, ClamAV with offline signatures).
- **Dev data:**
  - four items on the draft kidnapping: two photos (one raised to secret by the SD), the PDF and the MP4;
  - a fifth item, a photo uploaded as secret by `sd.lagos`;
  - the first photo's stored file was **deliberately altered** for the integrity check, so its downloads will keep failing;
  - one failed 2FA attempt on `sd.lagos` (a typo during login).
- **Carried over from 5A:** the evidence migration's `CREATE OR REPLACE FUNCTION` follows the existing convention, because `migrate:fresh` keeps functions.
