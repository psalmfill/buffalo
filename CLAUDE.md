# BUFFALO — Project Instructions for Claude Code

Read this file fully at the start of every session. These rules apply to every phase and every step. If a phase prompt ever conflicts with this file, stop and ask rather than guessing.

## 1. What BUFFALO is

BUFFALO is a Security Management and Intelligence Reporting system for Nigeria. It collects, manages, monitors, analyses and reports security information across Local Government Areas (LGAs), States and the national level. It holds highly sensitive data (persons of interest, incidents, political leaders, religious information). Security and correct access control matter more than speed of delivery.

## 2. Technology stack (fixed — do not substitute)

| Area | Choice |
|---|---|
| Backend | Laravel (latest stable), PHP 8.4 |
| Frontend | Inertia.js + React + TypeScript, Tailwind CSS, shadcn/ui components (copied locally) |
| Database | PostgreSQL + PostGIS; extensions `postgis`, `pg_trgm`, `unaccent` |
| Search | PostgreSQL full-text search (GIN indexes) + `pg_trgm` fuzzy matching. No external search engine. |
| Cache / sessions / queues | Valkey (Redis-compatible), accessed via Laravel's `redis` driver with `phpredis` |
| Queue workers | Laravel Horizon |
| Real-time | Laravel Reverb |
| File storage | Laravel local disks on private Docker volumes. No S3, no MinIO. |
| Maps | Leaflet (used directly through our own small React wrapper) + self-hosted PMTiles via protomaps-leaflet |
| Charts | ApexCharts |
| Tests | Pest, run against PostgreSQL (never SQLite — PostGIS is required) |
| Code quality | Laravel Pint, Larastan, ESLint, Prettier, `tsc --noEmit` |
| Containers | Docker + Docker Compose |

### Forbidden

- Any paid service, SaaS, or external API.
- Any runtime request to the internet: no CDNs, no Google Fonts, no Bunny Fonts, no external map tiles, no telemetry, no analytics. The system must work fully on an air-gapped server.
- Meilisearch, Algolia, Elasticsearch, MinIO, Redis (use Valkey), `react-leaflet` (its licence carries usage conditions; wrap Leaflet ourselves).
- Adding any new Composer or npm package not named in a phase prompt without stating the package, its licence, and why, in the handover. Only permissive licences (MIT, BSD, Apache 2.0, ISC) unless approved.

## 3. Deployment

The deployment target is not yet decided (government air-gapped, government internet-connected, or cloud). Everything must therefore be deployment-agnostic and work with zero outbound internet access at runtime. Pin every Docker image to a specific version tag, never `latest`.

## 4. Roles and jurisdiction

Six roles. Each user has one role and one jurisdiction.

| Role | Jurisdiction level | Sees |
|---|---|---|
| `super_admin` | system | System administration only: users, roles, settings, backups, audit logs. **No access to intelligence content by default.** |
| `director_general` | national | Everything |
| `deputy_director_general` | national, or assigned zones | National, or only assigned geopolitical zones |
| `state_director` | state | Everything in their state |
| `deputy_state_director` | state | Everything in their state; fewer approval rights |
| `lga_security_chief` | lga | Only their own LGA |

### Access rules (mandatory)

1. **Two layers, always both.** Roles and permissions (spatie/laravel-permission) control *what actions* a user may perform. Jurisdiction scoping controls *which records* they may see. Never rely on one alone.
2. **Every model with a location** (anything carrying `state_id` / `lga_id`) must use the `HasJurisdiction` trait, which applies a global scope. Never bypass this scope except in the explicitly designed national match-check service (Phase 3), and document every bypass with a comment.
3. **Every controller action** is authorised through a Policy. No authorisation logic in controllers or React components. Hiding a button is never a security control.
4. **Records store `state_id` and `lga_id` directly**, even when derivable, for fast and simple scoping.
5. **Classification:** sensitive records have a `classification` column (`restricted`, `confidential`, `secret`), checked in policies alongside role and jurisdiction.
6. **Cross-jurisdiction access (Phase 3):** users outside a record's jurisdiction can see only a minimal "hit" notice and must request access. Access requests are approved by the owning **State Director**, or by the **Deputy State Director only if the State Director has delegated that authority** (delegation has a start date, optional end date, and can be revoked). Grants are read-only, time-limited and revocable. The jurisdiction scope must allow a record when the user holds an active grant for it.

## 5. Data conventions

- Primary key: `bigint` `id` internally, plus a `ulid` column used in all URLs and API responses. Never expose numeric IDs to the frontend.
- Operational tables include `created_by`, `updated_by` (nullable FKs to users), timestamps and soft deletes. Never hard-delete operational records.
- Use PHP backed enums (in `app/Enums`) for fixed value sets such as status, severity and classification. Use lookup tables for anything an administrator may extend (incident types, agencies, threat types, religions).
- Sensitive personal fields (NIN, phone numbers of persons) are encrypted with `spatie/laravel-ciphersweet` and searchable only via blind indexes.
- Vulnerable persons are recorded **only as aggregate counts** per community, never as named individuals.
- Incident type-specific fields live in a validated JSON `details` column, validated against `incident_types.details_schema`.
- Geographic points use PostGIS `geography(Point, 4326)`; areas use `geography(Polygon, 4326)` or `MultiPolygon`.
- **Never invent reference data.** State, LGA, senatorial district and ward names come from CSV files in `database/data/` supplied by the project owner. If a CSV is missing, create the seeder so it reads the CSV and fails with a clear message.
- Never write sensitive values (NIN, phone, names of persons of interest) to application logs.

## 6. Audit

- All creates, updates, deletes and status changes are logged with `spatie/laravel-activitylog`.
- Reads of persons, persons of interest and classified records are logged in `record_access_logs`.
- Audit records are never editable or deletable through the application.

## 7. Code organisation

- Standard Laravel structure at the repository root.
- Business logic in single-purpose Action classes (`app/Actions/<Module>/...`), not in controllers or models. Controllers stay thin: authorise, validate (Form Requests), call an Action, return an Inertia response.
- Form Request classes for all validation.
- React pages in `resources/js/pages/<module>/`, shared components in `resources/js/components/`, types in `resources/js/types/`.
- TypeScript strict mode. No `any` without a comment explaining why.
- All UI text in English.

## 8. Testing rules

- Every Policy has tests covering each role, including "user from another state/LGA is denied".
- Every jurisdiction-scoped model has a test proving out-of-jurisdiction records are invisible.
- Tests run inside the app container against the `buffalo_test` PostgreSQL database.
- A step is not finished until `make test` and `make lint` both pass.

## 9. How to work on each step

1. Read this file and the phase prompt fully before writing code.
2. Implement **only** what the current step asks. Do not build features from later phases, even if convenient. If something from a later phase is needed, stop and say so.
3. If a requirement is ambiguous or seems wrong, ask before proceeding.
4. Verify current package APIs and Docker image tags rather than relying on memory; if something named in a prompt no longer exists or has changed, report it instead of silently substituting.
5. Run tests and linters before declaring a step done.
6. Commit at the end of each step using Conventional Commits (e.g. `feat(incidents): add status workflow`).
7. Write a handover file at `docs/handover/<step-id>.md` containing: what was built, files created or changed, any deviations from the prompt and why, new packages with licences, known issues, and anything the next step must know. Print its contents at the end of the session.

## 10. Agreed decisions log

- Schema approved (hierarchy, users, persons, community info, incidents, flashpoints, threats, coverage, organizations, system tables).
- Vulnerable persons: aggregate counts only.
- Incident type-specific fields: JSON `details` column.
- Fully free, self-hosted stack; no paid services.
- Search: PostgreSQL full-text + pg_trgm (Meilisearch rejected).
- File storage: local private volumes (MinIO rejected — abandoned upstream).
- Cache/queues: Valkey (instead of Redis).
- Maps: Leaflet + self-hosted PMTiles tiles.
- Frontend: React + TypeScript.
- Cross-jurisdiction hit/no-hit with approval-based, time-limited access; approver is the State Director, or the Deputy when delegated.
- Deployment target: undecided; build air-gap compatible.

## 11. Phase overview

0. Project setup and infrastructure
1. Foundation: hierarchy, auth + 2FA, users, roles, jurisdiction scoping, audit, notifications, app shell
2. Reference data and LGA information
3. Persons, political information, cross-jurisdiction records
4. Security agencies, coverage reports, organizations
5. Incident reporting and tracking
6. Flashpoints, blackspots, threats, map layers
7. Dashboards, search, analytics, reports
8. Security hardening, backup and recovery
9. Deployment, UAT, go-live
10. Optional extensions (offline PWA, mobile app, integrations)