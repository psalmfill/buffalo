# BUFFALO

Security Management and Intelligence Reporting system for Nigeria. Laravel + Inertia + React + TypeScript, PostgreSQL/PostGIS, Valkey, Horizon, Reverb, and an offline Leaflet map. It needs no internet access at runtime and runs on an air-gapped server.

Project rules for contributors (and Claude Code) are in [`CLAUDE.md`](CLAUDE.md).

## Requirements

- Docker Engine with Docker Compose v2 (`docker compose`)
- GNU Make
- Git

PHP, Composer and Node.js are **not** needed on the host. Everything runs in containers.

> On Apple Silicon, the `postgis/postgis` image only exists for `linux/amd64`, so Docker runs it under emulation. It is slower but works.

## Setup

```sh
git clone <repository-url> buffalo
cd buffalo
make setup
```

`make setup`:

1. Copies `.env.example` to `.env` if `.env` is missing.
2. Builds the PHP image.
3. Installs Composer and npm dependencies.
4. Generates the application key.
5. Generates the Wayfinder route helpers.
6. Starts the stack and waits for it to be healthy.
7. Runs the migrations and the seeders: roles and permissions, zones and states, and the hierarchy (the owner-supplied files if present; otherwise, only when `APP_ENV=local`, synthetic LGAs for Lagos and Kano). The seeders never create accounts and are safe to run again.

Then create the first super admin (interactive; the password is prompted and hidden):

```sh
make artisan cmd="buffalo:create-super-admin"
```

and log in at <http://localhost:8000>. No seeder ever creates accounts: every further user is created by a super admin.

If a host port is already in use (for example 6379 by another project's Redis), change it in `.env` (`APP_PORT`, `FORWARD_DB_PORT`, `FORWARD_VALKEY_PORT`, `FORWARD_MAILPIT_UI_PORT`, `VITE_PORT`) and run `make up`.

### Map tiles (one-time, needs internet)

The basemap is an offline [Protomaps](https://protomaps.com) extract of Nigeria, stored in the `map_tiles` Docker volume. It is not in the repository. Fetch it once:

```sh
make tiles url=https://build.protomaps.com/YYYYMMDD.pmtiles
```

Find a current build date at <https://maps.protomaps.com/builds/>. The extract (bounding box `2.6,4.2,14.7,13.9`, zoom 0–14) is about 550 MB. `scripts/fetch-map-tiles.sh` is the only part of BUFFALO that uses the internet. For an air-gapped server, run it on a connected machine and copy `nigeria.pmtiles` into the target's `map_tiles` volume (see the script header).

Until the tiles are installed, the dashboard map shows a plain background with the notice "Map tiles not installed — run `make tiles`."

## Services

| Service | URL / port (bound to 127.0.0.1) | Notes |
|---|---|---|
| Application (nginx) | <http://localhost:8000> | Also proxies Reverb WebSockets (`/app/`, `/apps/`) and serves tiles from `/tiles/` |
| Horizon | <http://localhost:8000/horizon> | Super admins only (`system.horizon`); "Queue monitor" in the sidebar |
| Mailpit | <http://localhost:8025> | Catches all outgoing mail in development |
| Vite dev server | <http://localhost:5173> | Hot module replacement; loaded automatically by the app |
| PostgreSQL + PostGIS | `localhost:5432` | Database `buffalo`, user `buffalo`, password `buffalo`; tests use `buffalo_test` |
| Valkey | `localhost:6379` | Cache, sessions and queues |

Containers: `app` (PHP-FPM), `nginx`, `postgres`, `valkey`, `horizon`, `scheduler`, `reverb`, plus `mailpit` and `vite` in development. Named volumes: `postgres_data`, `valkey_data`, `evidence_data` (private evidence files, never served by nginx) and `map_tiles`.

## Make targets

| Target | Description |
|---|---|
| `make setup` | First-time setup (see above) |
| `make up` | Start the stack (regenerates Wayfinder routes first) |
| `make down` | Stop the stack |
| `make shell` | Bash shell in the `app` container |
| `make artisan cmd="..."` | Run an Artisan command, e.g. `make artisan cmd="route:list"` |
| `make test` | Run the Pest test suite against `buffalo_test` |
| `make lint` | Pint (test mode), Larastan, ESLint, Prettier check, `tsc --noEmit` |
| `make fix` | Apply Pint and Prettier fixes |
| `make fresh` | `migrate:fresh --seed` |
| `make logs` | Follow container logs |
| `make tiles url=...` | Fetch the offline map tiles (see above) |
| `make wayfinder` | Regenerate the TypeScript route helpers after changing routes or controllers |
| `make ci` | Run `scripts/ci.sh` in a clean copy of the working tree (Docker) |
| `make help` | List targets |

## Tests and code quality

```sh
make test   # Pest, against PostgreSQL (never SQLite), then Vitest (frontend unit tests)
make lint   # Pint --test, Larastan (level 7), ESLint, Prettier --check, tsc --noEmit
make fix    # Pint and Prettier in write mode
make ci     # the full CI pipeline, locally
```

CI (`.github/workflows/ci.yml`) only runs [`scripts/ci.sh`](scripts/ci.sh), which contains all the CI logic. In order, it runs: Composer install, npm install, frontend build, Pint, Larastan, ESLint, Prettier, typecheck, Vitest, Pest.

Vitest tests live next to the code as `resources/js/**/*.test.ts` and run in Node (`vitest.config.ts`). They import the Wayfinder helpers, so run `make wayfinder` first if routes changed.

## Development notes

- **Wayfinder routes.** The React code imports type-safe route helpers generated from Laravel routes (`resources/js/routes`, `resources/js/actions`, git-ignored). The `vite` container has no PHP, so after changing routes or controllers run `make wayfinder`. Vite picks up the change automatically.
- **Xdebug** is installed in the dev image but off. Set `XDEBUG_MODE=debug` in `.env` and run `make up`. Xdebug connects to `host.docker.internal:9003`, triggered by the `XDEBUG_TRIGGER` cookie or query parameter.
- **No external requests.** Fonts (Inter), Leaflet assets and everything else are bundled by Vite. Do not add CDN links, web fonts or third-party APIs (see `CLAUDE.md`).
- **Evidence files** go to the `evidence` disk (`/var/lib/buffalo/evidence` in the containers). It is never served directly or symlinked into `public/`.

## Accounts and sign-in

- There is no self-registration. A super admin creates each account at **Users → New user** (`/users/create`) and chooses how the **one-time setup link** (valid 72 hours) reaches the user: **Send by email** (only when `BUFFALO_MAIL_ENABLED=true`) or **Show link once**, for handover by another route. The link is never shown again; issue a new one from the user's page if needed. The user chooses a password and must enrol in **two-factor authentication** (TOTP) before using BUFFALO.
- Passwords: at least 12 characters with upper and lower case, a number and a symbol; the last 5 cannot be reused.
- 5 failed attempts (password or 2FA code) lock an account for 15 minutes, doubling for each further lock within 24 hours (maximum 24 hours). Super admins are notified.
- Sessions: 30-minute idle timeout (with a warning 2 minutes before), 12-hour maximum, one active session per account (`BUFFALO_SINGLE_SESSION`).
- Every sign-in event is recorded in the append-only `login_histories` table.
- Deactivating a user (a reason is required) signs them out on their next request. Super admins can also edit profiles, change role/jurisdiction, unlock accounts, reset 2FA and issue new setup links; every action is audited.

## Interface

- **Navigation** is data: `resources/js/config/navigation.ts` lists sections and items, each with the permission that shows it. Adding a page to the sidebar is one entry. Hiding a link is never a security control; every route is authorised on the server.
- **Shared page data:** `auth.user` (ULIDs and names, role, jurisdiction and `jurisdictionLabel`), `auth.permissions` and `app` (`name`, `banner`, `sessionIdleMinutes`). Use `useAuth()` and `can()` in React. No numeric ids are ever shared (tested).
- **Dashboard:** `/dashboard` shows a page per role: system overview (super admin), national (DG/DDG), state (state directors) or LGA (LGA chiefs). Cards for later phases say "Available from Phase N".
- **System banner:** set `BUFFALO_BANNER_TEXT` (and `BUFFALO_BANNER_LEVEL`: `info`, `restricted`, `confidential` or `secret`) to show a banner at the top of every page, including sign-in and error pages.
- **Error pages:** 403, 404, 419, 500 and 503 are BUFFALO pages that never say why access was denied. Records outside a user's jurisdiction are 404.

## Lookup tables (administrator-managed reference data)

- **Tables:** incident types, threat types, security agencies, religions, denominations and property types. They are managed at **Administration → Reference data** (`/reference-data`): everyone can view them, and the super admin and Director General can manage them.
- **Never deleted:** values are deactivated instead. A deactivated value stays on existing records but can't be chosen for new ones. Codes are fixed once created.
- **Seeding:** `ReferenceDataSeeder` (part of `db:seed`) creates missing defaults only. It never changes existing values, so administrators' edits survive.
- **Incident type detail fields:** each incident type has its own fields (`details_schema`, BUFFALO's own format), edited with the field builder on the type's edit page.
  - `App\Support\DynamicFields\SchemaValidator` validates a schema. Once a type is in use, keys and types are fixed, and fields and options can only be deprecated.
  - `DetailsValidator` validates a record's `details`.
  - `DynamicFields` and `DynamicFieldsView` (`resources/js/components/dynamic-fields/`) render them.
- **Form options:** `App\Support\ReferenceData\ReferenceData::options()` returns the active values for forms. It is cached in Valkey and cleared whenever a value changes.

## Local government: LGA profiles and communities

- **`/lgas`** lists the LGAs in your jurisdiction. An LGA chief goes straight to their own. Each LGA page has three tabs:
  - **Overview:** basic facts, the current LGA chief, completeness, and "Mark as reviewed".
  - **Profile:** history, economy, terrain, roads, waterways, borders, notes, plus headquarters, area and population.
  - **Communities:** a list and a map.
- **Profile completeness** counts six items: history, economic activities, terrain, major roads, borders and at least one community.
- **Review reminders:** LGA chiefs are reminded weekly (Mondays 07:00) when their profile hasn't been reviewed for `BUFFALO_PROFILE_REVIEW_DAYS` (180) days.
- **`/communities`** searches communities across your jurisdiction. The search is fuzzy (pg_trgm), and matches other names, accents and misspellings. Each community has a detail page with a map and its change history.
- **Map picker:** communities are added with it (click, drag, or type the coordinates). A location must be in Nigeria and, once LGA boundaries are imported, inside the LGA; the database enforces the boundary rule too.
- **Deleting:** only State Directors and above can delete a community, and must give a reason (audited).
- **CSV import:** at **Communities → Import CSV** (DG, State Directors and Deputies). Download the template, upload a file for one LGA, and review the preview: every row is marked new, possible duplicate or error. New rows are imported, possible duplicates only if you tick them, and errors never. The import is one transaction; files over 200 rows run in the background and notify you when done. The uploaded file is never stored.
- **Scoping:** everything here is scoped to the user's jurisdiction. The super admin has no access. Out-of-jurisdiction LGAs and communities are 404.

## Community information and classification

- **Classification** (`CLAUDE.md` section 4a) is enforced by `App\Support\Classification\ClassificationGate`, both in queries (the `HasClassification` global scope, which applies together with the jurisdiction scope) and in policies. The levels:
  - **Restricted:** all intelligence roles.
  - **Confidential:** Deputy State Directors and above.
  - **Secret:** State Directors and above.

  A record's creator always sees it. Nobody can classify a record above their own level. Level changes are audited.
- **LGA and community pages** have three more tabs:
  - **Religious institutions:** counts by religion and type are computed; institutions can be added with the map picker and shown as a map layer.
  - **Vulnerable population:** aggregate counts only, with no names and no free text; new figures are new rows, so history is kept.
  - **Security notes:** classified, with status changes. Reading a confidential or secret note is recorded in `record_access_logs`.
- **Deleting** any of these needs State Director or above and a reason.

## Persons (Phase 3A)

> **Field encryption key.** NIN, phone number, date of birth and address of
> persons are encrypted with `CIPHERSWEET_KEY` (created once by
> `make setup`). **Losing this key makes all encrypted person data
> unrecoverable: back it up separately from the database, and never commit
> it.** See [docs/security/encryption-keys.md](docs/security/encryption-keys.md).

- **Persons** (Intelligence → Persons) are scoped to the user's jurisdiction and classified, and every view of a person is recorded in `record_access_logs`.
- **Search:** by name or alias (fuzzy, pg_trgm), by NIN, or by phone number in any format (exact match through blind indexes). Searches are POSTed, so names and numbers never appear in URLs.
- **Creating a person** first shows possible duplicates within the jurisdiction.
- **Photos** are re-encoded to remove EXIF (including GPS) and stored on the private `evidence` disk. They are only streamed through an authorised route, never by URL.
- **Aliases, roles and associations:** a person has aliases, roles (political leader, traditional ruler, religious leader, community leader), and associations with other persons, shown from both sides.
- **Links from Phase 2:** communities can name a traditional ruler, and religious institutions a leader.

## Political information, persons of interest, criminal groups (Phase 3B)

- **Political offices** are recorded on a person's page. The office type decides whether a senatorial district, LGA or ward is required. Recording an office gives the person the political leader role. A second current holder of a single seat produces a warning, not an error.
- **LGA chiefs** see offices of their LGA, plus offices with no LGA whose senatorial district contains their LGA (their senator).
- **Senatorial districts** (Local Government menu) and the LGA **Leadership** tab show current and past office holders, traditional rulers, and religious and community leaders.
- **Persons of interest** are designated from a person's page. A POI is visible only when its person is.
  - Status and risk changes always need a comment and form the POI's timeline.
  - Clearing a POI needs State Director or above.
  - Reviews fall due after `BUFFALO_POI_REVIEW_MONTHS` months. `buffalo:poi-review-reminders` runs daily and notifies LGA chiefs and State Directors of overdue reviews (counts only).
- **Criminal groups** are owned by their home LGA and are classified.
  - Areas of operation may be in any state but never make the group visible there.
  - Only persons the user can see can be added as members.
- **Views are access-logged:** POI details (context `poi`) and criminal groups.

## Cross-jurisdiction records (Phase 3C)

- **Hits:** a search or a new-person form that matches a person held by another jurisdiction shows a **hit**: only the owning state, "Person of interest" or "Person record", and "Record found" (same NIN or phone) or "Likely match" (similar name and the same date of birth). National users never get hits. A name gives a "Likely match" only with a date of birth: the persons search has an optional date-of-birth field for this.
- **Classification:** records above the searcher's clearance never produce a hit. For `confidential` records the owning State Director is told, and for `secret` records the DG and covering DDGs.
- **Limits:** each officer has a soft limit of 20 hits a day, which notifies national oversight, and a hard limit of 50, which stops hits for the day.
- **Access requests** (Intelligence → Access requests) are made from a hit:
  - the owning State Director decides, or a Deputy State Director while delegated;
  - urgent requests escalate to national oversight after 2 hours, normal ones after 48 hours; escalated requests expire after 7 days.
- **Grants** are read-only, temporary (7, 14 or 30 days), revocable, and never override classification.
  - `profile` shows the basic profile; `full` adds NIN, phone, address, POI details, offices, group memberships and associations (names only).
  - Every view is logged with context `grant`. When a grant is revoked or expires, a page open under it closes at once (`AccessGrantEnded` broadcast).
- **Scheduled jobs:** `buffalo:escalate-access-requests` (every 5 minutes), `buffalo:expire-access-grants` (every 15 minutes) and `buffalo:match-digest` (daily, owners' summary). Settings: `BUFFALO_MATCH_*` and `BUFFALO_ESCALATE_*` in `config/buffalo.php`.

## Security agencies: formations and coverage reports (Phase 4A)

- **Agency formations** (Security → Agency formations; also the LGA page's **Security presence** tab): commands, divisions, stations, bases, checkpoints and other posts of each security agency.
  - Formations are jurisdiction-scoped and classified; every view is logged.
  - A formation's parent must be of the same agency, and the hierarchy cannot loop.
  - A state agency's formations stay in its state.
  - `buffalo:expire-temporary-formations` (daily) sets temporary formations past their end date to inactive.
- **Coverage reports** (Security → Coverage reports): one per LGA and period (weekly, monthly, quarterly, or ad hoc). Each lists the agency deployments, the communities they cover, and the gaps.
  - The LGA chief creates the report; the State Director or Deputy can create one for any LGA of the state.
  - The report is submitted to the State Director and Deputy, who return it with a reason or mark it reviewed. A reviewed report is **locked**, and the database enforces this too.
  - A `critical` rating alerts the DG and the DDGs covering the state.
- **Overdue reports:** `buffalo:coverage-reminders` (daily) tells LGA chiefs, and each State Director, about LGAs with no report for the last completed period, once the grace days have passed. Settings: `BUFFALO_COVERAGE_PERIOD` (default `monthly`) and `BUFFALO_COVERAGE_GRACE_DAYS` (default 5).
- **Dashboards:** the LGA dashboard shows the current report's status. The state dashboard shows every LGA's status for the period. The national dashboard shows compliance by state and the poorly covered LGAs.

## Organizations (Phase 4B)

- **The directory** (Security → Organizations) is **national**. Every intelligence role sees every organization's name, type, sector, RC number and public description. It holds no location and nothing sensitive.
- **Sites** (head office, terminals, facilities, branches…) are jurisdiction-scoped and classified. They carry the security information:
  - security relevance, up to `critical_infrastructure`;
  - private security, and the provider (another directory entry);
  - security notes;
  - the government formations protecting the site.

  Officers see and count only the sites in their jurisdiction. Views of confidential and secret sites are logged. An organization has at most one head office.
- **Contacts** belong to a site and follow its jurisdiction and classification. Phone and email are encrypted, searchable only by exact match. Type a phone number (any format) or an email into the organizations search to find "who this number belongs to" within your jurisdiction. Every view of a contact is logged. A contact may be linked to a person on record, and only viewers who can see that person see the link.
- **Deletion:** only the DG or a DDG may delete a directory entry, and only one without sites. Sites are deleted by the DG, DDGs or State Directors.
- **Also in this phase:**
  - the LGA page's **Organizations** tab;
  - dashboards: sites by relevance for the LGA; critical and high sites, and sites without government security, for the state; critical and high sites by state and sector nationally;
  - reference data: **organization sectors**; lookup values in use are now marked "In use".

## Incidents (Phase 5A: the incident record)

- **Report incident** (Incidents → Report incident) opens one form in six sections:
  - what happened: the type, the type's own fields, and the severity (pre-filled from the type);
  - when and where;
  - people: casualty counts, persons involved, criminal groups;
  - response: agencies and formations;
  - property affected;
  - classification.

  Incidents are saved as **drafts** for now; submission, reference numbers and the workflow come in 5C. **Incidents** lists your own drafts.
- **Scope:** incidents and every related row (casualties, persons, groups, agencies, property) are jurisdiction-scoped and classified. Related rows move with the incident when its LGA changes. Views of confidential and secret incidents are logged.
- **Persons:** you may link persons of your own jurisdiction or persons you hold an active grant for. Other viewers of the incident see "Person on record (not visible to you)". Checking an unidentified person, or creating a new person from the form, runs the cross-jurisdiction match (context `incident_entry`), with **Request access** or **Create anyway** (justified and audited).
- **Editing:** a draft can be edited by its creator, or by the State Director or Deputy of the state.
- **Access requests** may cite one of your incidents. The approver sees only "reference — type, LGA".
- **Searchable pickers** replace long drop-down lists for persons, communities, formations, sites, criminal groups, organizations and users.
- **Time zone:** the database session uses Africa/Lagos (`DB_TIMEZONE`, defaulting to `APP_TIMEZONE`). Times stored earlier as `timestamptz` were corrected by a migration.

## Incident evidence (Phase 5B)

- **Evidence** (the Evidence section on an incident's page; on the form once the draft is saved): images (JPEG, PNG, WebP), documents (PDF, DOCX, XLSX, text), video (MP4, WebM) and audio (MP3, M4A, OGG, WAV). Drag and drop several files, each with a title, description and classification.
- **Integrity:** originals are stored exactly as uploaded on the private `evidence` volume, with a SHA-256 hash recorded at upload. Every download recomputes the hash. A mismatch refuses the download, is logged as `integrity_failure`, and alerts the DG and super admins. State Directors and above can **Verify integrity** at any time.
- **Viewing:**
  - images through EXIF-stripped thumbnails and previews (the original keeps its EXIF);
  - video and audio streamed from the original, with seeking;
  - documents by download only, always as an attachment.
- **Classification:** each item has its own, never below the incident's. Raising the incident raises its evidence. Items above a viewer's clearance are simply absent.
- **Chain of custody:** each item lists its upload (who, when, hash), edits, classification changes, integrity checks, and every view and download, with IP addresses.
- **Removal:** State Directors and above, with a reason. The file is never deleted.
- **File types** are decided from the file's content, not its name. The limits are in `config/buffalo.php` (`BUFFALO_EVIDENCE_*`). Files over 10 MB are verified on the queue, so Horizon must be running.

## Incident workflow (Phase 5C)

- **Submit fast:** a report needs only the type, title, description (20+ characters), severity, time and LGA. Everything else can be added after submission. Use "Save and submit" on the form, or **Submit** on the incident page.
- **Reference numbers:** `BUF/<state>/<LGA>/<year>/<sequence>`, given at the first submission and never changed. The sequence runs per state and per year, without gaps.
- **Workflow:**

  | Step | From | Who | Needs |
  |---|---|---|---|
  | Submit | draft, returned | creator, reporting officer, SD/DSD | |
  | Return | submitted | SD, DSD | a reason |
  | Verify | submitted | SD, DSD | |
  | Start investigation | verified | SD, DSD, DG, DDG | |
  | Resolve | verified, under investigation | SD, DSD, DG, DDG | a summary |
  | Reopen | resolved | SD, DG, DDG | a reason |
  | Close | resolved | SD, DG, DDG | |
  | Mark invalid | submitted, verified | SD, DG, DDG | a reason (duplicate, false report, not security related, other) |

  **Closed and invalid incidents are locked**, in the policies and in the database.
- **Escalation** to national level is a flag, separate from status. SD/DSD escalate with a reason; a `critical` incident is escalated automatically when submitted. The DG, or a DDG covering the state, acknowledges.
- **Editing after submission:** the reporting side may edit until verification. After that, SD, DSD, DG and DDG may edit, each save with an amendment reason. Only the SD and above may correct the LGA; the reference number stays.
- **Follow-ups** (Timeline tab) are append-only: follow-up, action taken, intelligence, status note. Each can be classified higher than the incident.
- **Notifications and live updates:** notifications reach only people who can see the incident, and carry the reference, type and LGA only. Open incident pages refresh by themselves; broadcasts carry only the incident ULID.
- **Verification deadlines:** `buffalo:incident-verification-reminders` runs every 5 minutes. It reminds the SD/DSD once when a submitted incident passes its deadline, and the DG/DDGs once at twice the deadline. Deadlines by severity: critical 60, high 360, medium 1440, low 2880 minutes (`BUFFALO_VERIFY_*_MINUTES`).
- **Possible duplicates** (same type, within 24 hours, same community or within 2 km) are shown at submission and to reviewers. A reviewer can mark a report invalid as a duplicate in one step.

## Incident lists, map and dashboards (Phase 5D)

- **Incidents list** (Incidents → Incidents), 25 per page.
  - **Search:** a reference number (`BUF/…`) matches exactly; anything else is a ranked full-text search.
  - **Filters:** type, category, severity, status (by default open work: no drafts, closed or invalid), escalation, dates, state, LGA, community, casualties, overdue verification, reporting officer, criminal group, "my reports", no map location.
  - **Sharing:** filters live in the URL, so a view can be shared; each officer still sees only what their own jurisdiction and clearance allow.
  - **Saved views:** save your own named views; built-in views are "Awaiting my verification", "Escalated, unacknowledged", "My reports" and "My drafts".
- **Incident map:** the same filters, the last 30 days by default.
  - Markers are coloured by severity, shaped by category, and clustered.
  - Popups show only the reference, type, time and status.
  - At most 5,000 points (`BUFFALO_INCIDENT_MAP_CAP`); incidents without a location are counted, with a link to them in the list.
- **Incident page tabs:** Overview, People, Response, Evidence, Timeline, Possible duplicates (reviewers) and History.
- **Print view:** classification marked on every page and "Printed by … on …". Each print is recorded in the access log.
- **Incidents tabs** on persons, criminal groups, formations, organization sites, communities and LGAs. The tab is hidden when empty, and a person's tab is never shown under an access grant.
- **Dashboards** (LGA, state, national): incident figures, charts and maps that refresh live as incidents change.
  - Drafts and invalid reports are never counted.
  - Charts use Chart.js (MIT).
- **Map clustering** (`leaflet.markercluster`, MIT) also applies to the community, formation and site maps.
- **Demo data (local only):** `make artisan cmd="buffalo:seed-demo-incidents --count=50000"` adds synthetic incidents (titles start with "[Demo]", references with "DEMO/"); `--purge` removes them. The command refuses to run outside `local`.

## Boundaries, flashpoints and blackspots (Phase 6A)

- **Boundaries:** LGA (and optionally state) boundaries come from an owner-supplied GeoJSON file:
  `make artisan cmd="buffalo:import-boundaries <file> --level=lga --code-property=… --state-property=… --attribution='…' --dry-run"`.
  - Features are matched by code properties or by a mapping CSV.
  - Every geometry is validated before writing: invalid shapes are repaired and reported, and anything outside Nigeria is refused.
  - Centroids are computed inside each shape.
  - State boundaries are derived from their LGAs unless states are imported too.
  - The attribution appears on every map.
  - Records now outside their LGA's boundary are listed in `storage/app/private/reports/`; nothing is changed.
  - Formats and options: [`database/data/README.md`](database/data/README.md).
- **Demo boundaries (local only):** `make artisan cmd="buffalo:generate-demo-boundaries"` gives each LGA a synthetic square, credited on maps as "SYNTHETIC demo boundaries". Many demo incidents fall outside these squares, and the post-import report lists them; that is expected.
- **What they are:**
  - A **flashpoint** is a place where tension could erupt.
  - A **blackspot** is a place where incidents keep happening.
  - Each is a point plus a radius (100 m to 50 km), with a threat type, a concern level, a status and a classification.
- **Assessments:** the concern level, status, recommended action and next review date change only through an assessment. Assessments are append-only, and the first one is recorded when the flashpoint is created. Reviews fall due 30 / 60 / 90 / 180 days after an assessment, for severe / high / moderate / low concern.
- **Links:** incidents (including closed and resolved ones; not drafts or invalid reports) and community security notes can be linked.
  - A link shows only to viewers who can see both sides.
  - The Incidents tab suggests visible incidents inside the area from the last 3–24 months. It can be limited to incidents in the threat type's category.
- **Notifications:**
  - An incident submitted inside an active or monitoring area is reported once to the SD, DSD and LGA chief who can see both the incident and the area. The incident page then shows "Near flashpoint".
  - A blackspot with 3 incidents in 30 days asks the SD and DSD to reassess it, at most once per 30 days.
  - Due reviews are reminded daily (`buffalo:flashpoint-review-reminders`).
  - Settings: `config/buffalo.php` → `flashpoints`.
- **Pages:** Flashpoints & blackspots (list and map coloured by concern), the flashpoint page (overview, assessments, incidents, security notes, history), a Flashpoints section on incidents and a Flashpoints tab on LGAs.

## Threat reports (Phase 6B)

- **What they are:** early warnings of what may be about to happen (a planned attack, a kidnapping, a clash, an expected flood).
  - Each has a credibility (unconfirmed to confirmed), an urgency (routine, priority, immediate), an expected window and an expiry.
  - References are `THR/<STATE>/<YEAR>/<5 digits>`, gap-free per state and year.
  - Never record an informant's identity in a report.
- **Expiry:** the expected end, otherwise 14 days; at most 90 days ahead.
  - Active reports past their expiry become `expired` automatically every 15 minutes (`buffalo:expire-threat-reports`).
  - The creator and the SD/DSD are warned 24 hours before.
  - Extending needs a comment. Reactivating an expired, mitigated or withdrawn report is for the SD and above.
- **Status:** mitigated, materialised or withdrawn, each with a note. Materialised needs a linked incident.
- **Links:** flashpoints, criminal groups (suspected / named in report) and incidents (materialised as / related). A link shows only when both sides are visible.
- **Incidents:** when an incident is submitted, active threat reports of the same category whose area covers it are recorded as "possibly related". The area is the point and radius, else the community, else the LGA. The time frame runs from the expected start or the report, whichever is earlier, until the later of the expected end and the expiry.
  - The incident page shows them to viewers who can see the report.
  - Those who manage the report can mark it materialised in one step.
- **Notifications:**
  - **Immediate:** the SD, DSD, LGA chief and national level, critical.
  - **Priority:** the SD, DSD and LGA chief.
  - **Routine:** only in the SD/DSD's 07:00 digest (`buffalo:threat-digest`).
  - **Raised to immediate or confirmed:** as for immediate.
  - **Materialised:** the creator and SD/DSD, plus national level if the report was immediate.
  - Payloads carry the reference, type, urgency and LGA only.
  - Live updates: `states.{ulid}.threats` and `national.threats`.
- **Pages:** Threat reports (default: active, most urgent first, with a map), the report page (countdown, window, status actions, links, timeline, history), sections on incident pages, a tab on flashpoints, and panels on the dashboards. The sidebar badge counts active immediate threats in reach.
- **Permissions:**
  - Every intelligence role may view, record and manage reports; LGA chiefs manage only their own.
  - Reactivating and deleting are for the DG, DDG and SD.
  - Settings: `config/buffalo.php` → `threats`.

## Audit and notifications

- All changes are recorded in `activity_log` (with IP address and browser). Passwords, 2FA secrets, encrypted fields and setup links are never recorded. `activity_log`, `record_access_logs` and `login_histories` reject `UPDATE` and `DELETE` at the database level, and `activitylog:clean` is disabled.
- `/audit` and `/login-history` are read-only viewers for super admins (and `/audit` for the Director General). Roles below national level are refused even if granted the permission.
- Officer postings (`officer_postings`) record each user's role and jurisdiction over time.
- Notifications are stored in the database and pushed live over Reverb to the private channel `users.{ulid}`. Mail is sent only when `BUFFALO_MAIL_ENABLED=true` and the notification allows it. Notifications are queued, so Horizon must be running.

## Reference data

States and geopolitical zones are seeded from `database/data/zones_states.csv`. Senatorial districts, LGAs and wards come from CSV files supplied by the project owner and are loaded with `make artisan cmd="buffalo:import-hierarchy"`; see [`database/data/README.md`](database/data/README.md) for the formats and options. Until those files exist, `make fresh` in a local environment seeds clearly synthetic districts and LGAs for Lagos and Kano, and prints a warning.

## Handover notes

Each phase or step records what was built, deviations, new packages and known issues in [`docs/handover/`](docs/handover). Phase prompts are in [`docs/prompts/`](docs/prompts).
