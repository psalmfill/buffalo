# BUFFALO data retention

For the client: what BUFFALO keeps, for how long, and the decisions that are still yours to make.

## The current rule

**Nothing is deleted until the client sets a retention policy.** Operational records are soft-deleted only: hidden from users, kept in the database, recoverable. Audit records can never be changed or removed through the application, and are hash-chained so tampering is detectable.

Retention settings already exist in `config/buffalo.php` under `retention`, one per group of records. Each defaults to `null`, meaning "keep forever", and can be set with an environment variable. **No deletion command exists yet.** When you decide on periods, a later phase will build one that applies them, logs what it removes, and keeps the audit chains verifiable.

To see how much each group holds, by year:

```
php artisan buffalo:retention:report
```

It is read-only. Sizes per year are estimates: the table's size shared out by row count.

## What is deleted automatically today

These happen now, by design, and are small or technical:

| What | When | Why |
|---|---|---|
| **Report files** (PDF, XLSX, CSV) | 30 days after generation (`reports.expiry_days`), hourly by `buffalo:expire-reports` | Exports are copies of data and should not pile up. The run record (who, what, when, classification, SHA-256) is kept. |
| Password history beyond the last 5 | When a user changes their password | Only the last 5 hashes are needed to stop reuse. |
| Open blackspot suggestions | Replaced nightly | They are recomputed from incidents each night. Accepted or dismissed suggestions are kept. |
| Display copies of boundaries | On each boundary import | Rebuilt from the imported boundaries. |
| Queue job details in Valkey (Horizon) | Completed jobs after 1 hour, failed jobs after 7 days | Operational monitoring only. No intelligence content. |
| Sessions, sign-in setup and password reset links | When they expire (30 minutes idle; links after their lifetime) | Security. |

## Data categories and decisions

"Current" is what BUFFALO does now. "To decide" lists the questions for the client.

### Audit and security records

| Category | Tables | Current | To decide |
|---|---|---|---|
| Activity log: every create, update, delete, status change | `activity_log` | Indefinite; append-only; hash-chained | Minimum period (often the longest of the records it describes, or a fixed number of years). |
| Record access log: views, prints, exports, downloads of sensitive records | `record_access_logs` | Indefinite; append-only; hash-chained | As above. Usually kept as long as the records themselves. |
| Sign-in history | `login_histories` | Indefinite; append-only; hash-chained | Period (for example 2 years). |
| Cross-jurisdiction match events | `record_match_events` | Indefinite; append-only; hash-chained | Period. |
| Search logs (a hash of each search, never the text) | `search_logs` | Indefinite; append-only; hash-chained | Period (for example 1 year). |
| Analytics export log | `analytics_exports` | Indefinite; append-only; hash-chained | Period. |
| Audit chain anchors | `audit_anchors` | Indefinite | Keep as long as the audit records they cover. |
| Application log files | `storage/logs` | One growing file by default (`LOG_STACK=single`); with `LOG_STACK=daily`, 14 days (`LOG_DAILY_DAYS`) | Rotation period (no sensitive personal data is written to them). Daily files are recommended in production. |

Deleting old audit rows needs care: the chain must be re-anchored, and the anchors for the removed period recorded. The future deletion command will do this.

### Intelligence records

| Category | Current | To decide |
|---|---|---|
| Incidents, with casualties, people involved, agencies, property, status history and follow-ups | Indefinite; soft-deleted only | Period after closure, if any (incidents are often kept permanently for statistics and history). |
| Evidence files (originals, previews, thumbnails) | Indefinite, on the private evidence volume | Period after the incident is closed; legal holds. |
| Persons and their aliases, associations and roles | Indefinite; soft-deleted only | Period after last involvement; handling of deceased persons. |
| **Persons of interest who are cleared** (or deceased) | Indefinite | Whether and when a cleared person's record is removed or anonymised. This is the most sensitive decision. |
| Political office holders | Indefinite (past holders stay as history) | Whether to keep past holders. |
| Criminal groups and members | Indefinite | Period after a group is inactive. |
| Threat reports, flashpoints and assessments | Indefinite | Period after expiry or resolution. |
| Disseminations and requests | Indefinite | Period after they end. |
| Communities, religious institutions, vulnerable population figures, security notes, LGA profiles | Indefinite | Usually permanent reference information. |
| Agency formations and coverage reports | Indefinite | Period for coverage reports (for example 5 years). |
| Organizations, sites and contacts | Indefinite | Period after a contact leaves. |

### Accounts and system records

| Category | Current | To decide |
|---|---|---|
| User accounts | Deactivated, never deleted (audit records refer to them) | Whether deactivated accounts are anonymised after a period. |
| Notifications | Indefinite | Period (for example 1 year). |
| Report run records (not the files) | Indefinite | Period. |
| Report schedules | Until the user removes them | None needed. |
| Key fingerprints and rotations | Indefinite | Keep (they hold no secrets). |
| Backups (Phase 8C) | — | How long backups are kept, which sets how long retired keys must be kept in escrow (`key-management.md`). |

## How periods will be applied

When the client decides:

1. Set the period in days for each group (for example `BUFFALO_RETENTION_SEARCH_LOG_DAYS=365`).
2. A retention command (later phase) will remove or anonymise records older than the period, in batches, log every run, and re-anchor the audit chains.
3. Backups keep older copies until they expire themselves. The backup retention must be no longer than the data's own.

Until then, `buffalo:retention:report` shows the growth.

## Expected growth

Estimated for 50,000 incidents a year and about 300 active officers. These are assumptions for capacity planning, to be checked with `buffalo:retention:report` after go-live.

| Table | Assumption | Rows per year | Size per year (with indexes) |
|---|---|---|---|
| `activity_log` | about 15 entries per incident, plus other modules | 1,000,000 | about 1.3 GB |
| `record_access_logs` | about 25 views/prints/exports per incident, plus persons and reports | 2,000,000 | about 1.6 GB |
| `search_logs` | 10 searches per officer per working day | 750,000 | about 0.3 GB |
| `login_histories` | 2 sign-ins per officer per working day | 150,000 | about 0.1 GB |
| `record_match_events` | person searches with cross-jurisdiction checks | 50,000 | about 0.02 GB |
| `incidents` and their children | 50,000 incidents | 50,000 incidents | about 0.3 GB (as measured in development) |
| `notifications` | about 20 per incident across recipients | 1,000,000 | about 0.5 GB |

Total: roughly **4 GB a year** in the database, excluding evidence files (which depend on uploads). Audit tables grow fastest. They are append-only and time-ordered, so BRIN indexes on their time columns keep range queries cheap.
