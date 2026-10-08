# BUFFALO — Project Instructions for Claude Code

Read this file fully at the start of every session. These rules apply to every phase and every step. If a phase prompt ever conflicts with this file, stop and ask rather than guessing.

## 1. What BUFFALO is

BUFFALO is a Security Management and Intelligence Reporting system for Nigeria. It collects, manages, monitors, analyses and reports security information across Local Government Areas (LGAs), States and the national level. It holds highly sensitive data (persons of interest, incidents, political leaders, religious information). Security and correct access control matter more than speed of delivery.

## 2. Technology stack (fixed — do not substitute)

| Area | Choice |
|---|---|
| Backend | Laravel 13, PHP 8.4 (Composer platform pinned to 8.4), Fortify for authentication |
| Frontend | Inertia 3 + React 19 + TypeScript (strict), Tailwind 4, shadcn/ui components (copied locally), Wayfinder route helpers |
| Database | PostgreSQL + PostGIS; extensions `postgis`, `pg_trgm`, `unaccent` |
| Search | PostgreSQL full-text search (GIN indexes) + `pg_trgm` fuzzy matching. No external search engine. |
| Cache / sessions / queues | Valkey (Redis-compatible), accessed via Laravel's `redis` driver with `phpredis` |
| Queue workers | Laravel Horizon |
| Real-time | Laravel Reverb |
| File storage | Laravel local disks on private Docker volumes. No S3, no MinIO. |
| Maps | Leaflet (used directly through our own small React wrapper) + self-hosted PMTiles via protomaps-leaflet |
| Charts | Chart.js (MIT) through BUFFALO's own small React wrappers (no `react-chartjs-2`) |
| PDF rendering | Gotenberg (MIT) container on the internal Docker network, with all outbound URLs denied (Phase 7D) |
| Spreadsheet export | OpenSpout (MIT), streaming XLSX and CSV (Phase 7D) |
| Database backups | pgBackRest (MIT): encrypted full/differential backups with WAL archiving for point-in-time recovery (Phase 8C) |
| File backups | restic (BSD-2-Clause): encrypted, deduplicated backups of the evidence volume (Phase 8C) |
| Malware scanning | ClamAV (GPL-2.0) as a separate service container, like PostGIS; never linked into application code; signatures updated offline-capable (Phase 8B) |
| Security scanning (CI only) | Trivy (Apache-2.0), OWASP ZAP (Apache-2.0), `composer audit`, `npm audit` |
| Tests | Pest, run against PostgreSQL (never SQLite — PostGIS is required) |
| Code quality | Laravel Pint, Larastan **level 7**, ESLint 10, Prettier, `tsc --noEmit` |
| Containers | Docker + Docker Compose |

### Forbidden

- Any paid service, SaaS, or external API.
- Any runtime request to the internet: no CDNs, no Google Fonts, no Bunny Fonts, no external map tiles, no telemetry, no analytics. The system must work fully on an air-gapped server.
- Meilisearch, Algolia, Elasticsearch, MinIO, Redis (use Valkey), `react-leaflet` (its licence carries usage conditions; wrap Leaflet ourselves), ApexCharts (dual-licensed with paid tiers from 5.x).
- `Password::uncompromised()` or any other breached-password API lookup (it calls an external service).
- Passkeys / WebAuthn login (removed in Phase 1; may return later as a deliberate decision).
- Public self-registration. Accounts are created only by administrators.
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
6. **Fail closed.** The jurisdiction scope reads the current `JurisdictionContext`. In an HTTP request with no authenticated user it returns no rows. Console commands and queued jobs run in an explicit `system` context; a job acting on behalf of a user must set that user's context. Never let a missing user mean "see everything".
7. **No numeric IDs in the browser**, including broadcast channel names: private channels use the ULID (e.g. `users.{ulid}`).
8. **Cross-jurisdiction access (Phase 3):** users outside a record's jurisdiction can see only a minimal "hit" notice and must request access. Access requests are approved by the owning **State Director**, or by the **Deputy State Director only if the State Director has delegated that authority** (delegation has a start date, optional end date, and can be revoked). Grants are read-only, time-limited and revocable. The jurisdiction scope must allow a record when the user holds an active grant for it.
   - **A grant never overrides classification.** Access requires both clearance and an approved grant. Grants are always read-only.
   - Records above the searcher's clearance never produce a hit. For `confidential` records the owning State Director is notified; for `secret` records the DG and DDGs are notified.
   - Hit notices never expose a record's ULID or any identifying detail. They carry an opaque, signed, short-lived hit token that is exchanged server-side when requesting access.
   - All cross-jurisdiction matching happens in one `PersonMatchService`, the only place outside `app/Support/Jurisdiction` allowed to query persons across jurisdictions (via `runAsSystem`).
   - **Only persons are grantable** through requests. A grant makes the person visible and nothing else.
   - **Threat reports and flashpoints can be disseminated** (Phase 7A): the owning state pushes read-only access to named neighbouring states or LGAs. Classification remains a hard ceiling. This is the only other way records cross jurisdictions.
9. **Models linked to a person** must carry their own `state_id`/`lga_id` and be scoped on them, never only through `whereHas('person')` (which would make them visible under a person grant). Any write that takes a person must authorise `link` or `update` on that person, never `view`.

## 4a. Classification rules

Classification narrows access **within** a user's jurisdiction; it never widens it. It is enforced by a single `ClassificationGate` (built in Phase 2C) used by both query scopes and policies.

| Level | Who may see it (always also subject to jurisdiction) |
|---|---|
| `restricted` (default) | All intelligence roles within jurisdiction |
| `confidential` | DG, DDG, State Director, Deputy State Director, and the record's creator |
| `secret` | DG, DDG, State Director, and the record's creator |

`super_admin` never sees classified intelligence content. A user may not create a record at a level they could not see.

## 5. Data conventions

- Primary key: `bigint` `id` internally, plus a `ulid` column used in all URLs and API responses. Never expose numeric IDs to the frontend.
- Operational tables include `created_by`, `updated_by` (nullable FKs to users), timestamps and soft deletes. Never hard-delete operational records.
- Use PHP backed enums (in `app/Enums`) for fixed value sets such as status, severity and classification. Use lookup tables for anything an administrator may extend (incident types, agencies, threat types, religions).
- Sensitive personal fields (NIN, phone numbers of persons, and contact phone/email of organization contacts) are encrypted with `spatie/laravel-ciphersweet` and searchable only via blind indexes.
- Vulnerable persons are recorded **only as aggregate counts** per community, never as named individuals.
- Incident type-specific fields live in a validated JSON `details` column, validated against `incident_types.details_schema`.
- Geographic points use PostGIS `geography(Point, 4326)`; areas use `geography(Polygon, 4326)` or `MultiPolygon`.
- **Never invent reference data.** State, LGA, senatorial district and ward names come from CSV files in `database/data/` supplied by the project owner. If a CSV is missing, create the seeder so it reads the CSV and fails with a clear message.
- Never write sensitive values (NIN, phone, names of persons of interest) to application logs.
- Every new use of a reference table is registered with the `ReferenceUsage` registry (Phase 3A) so in-use values are protected.
- Tunable numbers (thresholds, limits, cadences) live in `config/buffalo.php` with an env override.
- **Date-times:** new columns use `timestampTz`. The `pgsql` connection time zone is set to the app time zone (Africa/Lagos) since Phase 5A.0; never write wall-clock strings to `timestamptz` columns through a UTC session. Date-only values use `date`.

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
7. Write a handover file at `docs/handover/<step-id>.md` (for example `phase-1a.md`) containing: what was built, files created or changed, any deviations from the prompt and why, new packages with licences, known issues, and anything the next step must know. Print its contents at the end of the session.

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
- Secret records: as built in Phase 3, hits follow clearance only. A State Director (secret clearance) can receive a hit on another state's `secret` record; LGA chiefs and deputies cannot.
- Person search box accepts an optional date of birth so name + DOB can produce `strong` matches; name alone never produces a cross-jurisdiction hit (Phase 4A.0).
- Deployment target: undecided; build air-gap compatible.
- Classification rules as in section 4a.
- Persons: one record per individual, owned by the recording jurisdiction. NIN, phone, address and date of birth are encrypted (NIN, phone, DOB with blind indexes). Names stay plaintext for fuzzy matching.
- Persons of interest default to `restricted`; officers may raise the level.
- Deputy State Directors hold no `*.delete` permissions.
- LGA-wide vulnerable population rows are the authoritative LGA figure; community rows are breakdowns and are never summed.
- Location-bearing tables use a composite FK `(lga_id, state_id)` → `lgas(id, state_id)` (Phase 2 pattern); follow it for every new table.
- Nationwide fuzzy searches must use the trigram index (`%` operator with `pg_trgm.similarity_threshold` set per query), not bare `similarity()`/`word_similarity()` filters.
- Dynamic fields (incident type details, later others) use BUFFALO's own field-definition format and `DynamicFields` React component (Phase 2A), not JSON Schema.
- Larastan raised to level 7 (the starter kit's level).
- Passkeys removed; mandatory TOTP 2FA is the only second factor.
- Only `super_admin` creates and manages user accounts in Phase 1. Others may view users within their jurisdiction.
- First super admin is created with an Artisan command, never seeded with default credentials.
- States and zones are defined in the Phase 1A prompt; senatorial districts, LGAs and wards come from owner-supplied CSVs.
- Agency formations (stations, bases, checkpoints) are jurisdiction-scoped and classified (military locations can be sensitive).
- Coverage reports are per LGA per period, submitted by the LGA and reviewed by the State Director or Deputy; reviewed reports are locked.
- Organizations: the organization itself is a national directory entry (name, sector, registration); its sites and contacts are jurisdiction-scoped and classified.
- Incidents: one primary LGA per incident; reference numbers `BUF/<STATE>/<LGA>/<YEAR>/<6-digit seq>` assigned at submission, sequential per state per year; drafts have none.
- Incident escalation is a flag (`escalated to national`), independent of status.
- Incident follow-ups, status history and evidence custody logs are append-only (triggers).
- Evidence originals are stored unaltered with a SHA-256 hash (integrity); previews and thumbnails are EXIF-stripped. Every original download is logged.
- Real-time incident events carry only the incident ULID and event name; clients refetch through normal scoped routes.
- `leaflet.markercluster` (MIT) is approved for map clustering (Phase 5D).
- Charts: Chart.js replaces ApexCharts (ApexCharts 5.x+ requires paid licences above a revenue threshold; 4.x is unmaintained).
- Incident person links use `PersonPolicy::linkToIncident` (own jurisdiction or an active grant); `link` stays own-jurisdiction only.
- Evidence: HEIC not accepted; PDFs get no thumbnail (no Imagick/Ghostscript in the stack).
- **Statistics rule:** all counts, figures and analytics exclude `draft` and `invalid` incidents unless a view is explicitly about them, and are always computed through the viewer's own scopes.
- Maps: aggregated layers (density, choropleths) are computed on the server with PostGIS and cached per scope signature; never ship thousands of raw points to draw a heatmap.
- LGA and state boundaries come only from an owner-supplied GeoJSON file via `buffalo:import-boundaries` (Phase 6A). Development may use clearly labelled synthetic boundaries for demo LGAs only.
- Flashpoint–incident links belong to the flashpoint (scoped on the flashpoint's place) and may reference closed incidents, because recording past incidents is their purpose.
- Flashpoint area matching uses a constant 50 km `MAX_RADIUS` prefilter so `ST_DWithin` uses the GIST index, then each row's own radius.
- Threat reports use parallel real-time channels (`states.{ulid}.threats`, `national.threats`); incident matches are recorded at submission.
- Map aggregation: hexagon cells computed arithmetically on PostGIS's grid (the same arithmetic drives the incident list's `hex` filter); JIT off for aggregation queries; the cache scope signature includes the user ID below `secret` clearance (creator rule). Reuse `LayerCache` for heavy aggregates; never share cached results across scope signatures.
- Blackspot suggestions: DBSCAN plus a Poisson significance test (Bonferroni), counts recomputed per viewer, hidden below `min_points` visible incidents.
- Reuse `IncidentFilters` / `MapFilters` for every incident-based list, analytic and export.
- **Exports and reports:** every exported or printed file carries classification markings and a "generated for [name] at [time]" line, is logged in `record_access_logs` (action `export`), and is built only through the requesting user's own scopes. Persons and persons of interest are never exported in bulk; the only names allowed in bulk documents are current public office holders (political offices) in LGA profiles. Cells in XLSX/CSV exports are neutralised against formula injection. Report files are never attached to emails. Report files are stored on a private disk and expire.
- Any file that contains coordinates of records (such as the boundary post-import report) is treated as classified and kept on a private disk.
- Dissemination: `CombinedAccessResolver` composes person grants and disseminations. Recipients are SD/DSD of a target state and chiefs of target LGAs; they never see History. Counts, pickers and analytics use `ownJurisdiction($user)`, so shared records are never counted as the recipient's own.
- Global search: one provider per module on its own scoped query, a 300 ms budget per provider, incident ranking over the 1,000 most recent visible matches, `search_logs` store a query hash only. Global search never calls `PersonMatchService`.
- Analytics: no rollup tables (direct queries are fast enough); rates per 100,000 people a year; threat and flashpoint analytics count own records only.
- Reports: Gotenberg 8 (Chromium only) renders local files only; dedicated `redis-reports` queue and Horizon supervisor; incident list exports carry no free-text descriptions; LGA profiles name only current LGA office holders; queued jobs log record access as the user whose context they run in.
- **Step-up authentication** (Phase 8B): sensitive actions (large exports, `secret` downloads, evidence originals above `restricted`, key and backup operations) require a fresh TOTP code within the last 15 minutes.
- **Database roles** (Phase 8A): the application connects as a non-superuser role that owns no tables; migrations run as a separate owner role. The application role cannot disable triggers or alter schema.
- **Audit integrity** (Phase 8A): append-only audit tables are hash-chained; `buffalo:audit:verify` detects any alteration.
- **Retention:** audit-type tables are kept indefinitely until the client sets a retention policy; nothing is deleted through the application.
- Phase 8 as built: Valkey ACL users (default user off); secrets via `NAME_FILE`; production refuses to start on failed `buffalo:secrets:check` or key fingerprint mismatch; audit chains with nightly `buffalo:audit:verify` and daily anchors; all routes inventoried in `docs/security/route-inventory.md` and every new route must pass the route authorisation test; every container non-root, all capabilities dropped, `no-new-privileges`, read-only root, digest-pinned, resource-limited, health-checked.
- **Deployment profiles** (Phase 9A): `airgapped`, `connected` (government network with controlled internet egress) and `cloud`. The profile decides egress (mail relay, ClamAV online updates, ACME certificates) and how releases arrive (offline signed bundle or registry pull). Application code never branches on the profile; only compose files and configuration do.
- **Releases** are signed with `ssh-keygen -Y sign` (OpenSSH, no extra tooling) over a SHA-256 manifest; installers verify the signature before loading anything.
- **Third-party images** with fixable vulnerabilities are rebuilt as BUFFALO-patched derivatives (`docker/patched/`) weekly and scanned with Trivy; unfixable findings live in a dated exceptions file that fails CI after its review date.
- **Pilot rollout:** states have an `is_live` flag; until a state is live, its state- and LGA-level users cannot sign in. National users and super admins are unaffected.
- Phase 9 as built: production boot check refuses unsafe settings; `airgapped` profile has no egress from any container; `connected`/`cloud` give nginx an egress network plus opt-in overlays (mail via Horizon, online ClamAV, ACME); UAT runs with `APP_ENV=uat`; go-live client decisions are append-only and hash-chained. The committed release signing key is a TEST key until the owner replaces it.
- **Merging (Phase 10):**
  - Person merges happen only **within one state**, executed by SD, DG or DDG (LGA chiefs and DSDs may request), with step-up. Cross-state duplicates are never merged; they stay separate records owned by their states.
  - Two different non-null NINs are a hard stop: those are different people.
  - The surviving record takes the **higher** classification of the two; never lower.
  - The merged record is soft-deleted with `merged_into_person_id`; its URL redirects to the survivor only for viewers who can see the survivor, otherwise 404. Audit history stays attached to the original IDs.
  - Grants and open access requests on the merged record are revoked or cancelled (reason "record merged"); they never transfer to the survivor.
  - Every merge stores a manifest of exactly what moved, so it can be undone within 30 days.
  - Duplicate incidents are never merged; their content is consolidated into the original and the duplicate is invalidated as `duplicate` in the same transaction. Casualty figures are never summed automatically.
  - Any new table referencing `persons` must be registered in `PersonReferenceRegistry` with a merge and undo rule (enforced by `PersonReferenceRegistryTest`).
  - Any new incident child table must be added to `App\Support\Incidents\Consolidation` with a consolidation rule (not enforced by a test; check it in review).
  - The incident lock also refuses moving rows off a closed or invalid incident; the only exception is the transaction-local `buffalo.person_merge` setting used by person merge and undo.

## 10a. Tooling notes (from Phase 0)

- Run all PHP, Composer and Artisan commands inside the containers (`make shell`, `make artisan cmd="..."`). The Node container has no PHP.
- After adding or changing routes or controllers, run `make wayfinder` so the frontend route helpers stay in sync.
- `make test`, `make lint` and `make ci` must pass before a step is finished.
- PostGIS image has no patch-level tags; its digest is recorded in `docs/handover/phase-0.md`.
- `resources/views/vendor/horizon/layout.blade.php` overrides Horizon's layout to remove external fonts; re-sync it when upgrading Horizon.
- The permission source of truth is `RolesAndPermissionsSeeder::matrix()`; drift tests keep `resources/js/types/auth.ts` and the navigation test in step. Update all three together.

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