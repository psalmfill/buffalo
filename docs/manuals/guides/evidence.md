---
title: Evidence and chain of custody
summary: Uploading evidence, integrity checks, previews, downloads and the custody record.
audience: intelligence
order: 20
---

Evidence (photos, video, audio, documents) is attached to an incident and
kept exactly as uploaded, so that it can be relied on later.

## Uploading evidence

1. Open the incident, tab **Evidence**, and choose **Upload evidence**.
2. Choose the file, give it a title and a short description, and choose its
   classification (never below the incident's).
3. Upload. The file is first scanned for malware. An infected file is
   refused and administrators are alerted; if the scanner is unavailable,
   the file waits in quarantine and is accepted once scanned.

Accepted formats: photos (JPEG, PNG, WebP; not HEIC: convert iPhone photos
to JPEG first), video (MP4, M4V, WebM), audio (MP3, M4A, OGG, Opus, WAV) and
documents (PDF, Word .docx, Excel .xlsx, plain text).

You can upload while the incident is being drafted or edited, and after
submission until the incident is closed or marked invalid.

## Integrity

- The **original file is never changed**. Its SHA-256 fingerprint is
  recorded at upload and checked again before every download.
- If a file no longer matches its fingerprint, the download is refused and
  the Director General and administrators are alerted at once.
- The State Director, DG or DDG can mark an item **verified** after checking
  it.

## Previews and downloads

- Previews and thumbnails are separate copies with location and camera
  information (EXIF) removed. Use them for normal viewing.
- **Download original** gives the unaltered file. Every download is recorded.
  Originals above restricted ask you to confirm with your authenticator code
  (step-up).
- PDF documents have no thumbnail.

## Chain of custody

Open an item and expand **Custody** to see its full history, oldest first:
who uploaded it, every view and every download, with the time and IP address,
and any integrity check. This record cannot be changed by anyone.

## Removing evidence

Only the State Director, DG or DDG can remove an item, with a reason. The
file itself is kept and the removal is recorded.
