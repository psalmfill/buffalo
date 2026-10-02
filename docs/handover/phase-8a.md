# Phase 8A handover — Database, secrets and audit integrity

Date: 2026-10-02

## What was built

### 8A.1 Database roles (`8074b1a`)

| Role | Login | Attributes | Rights |
|---|---|---|---|
| `buffalo_owner` | yes | NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS NOREPLICATION | Owns every schema object in `public` (except extension objects); `CREATE` on `public`; runs migrations |
| `buffalo_app` | yes | same | `SELECT, INSERT, UPDATE, DELETE` on tables, `USAGE, SELECT` on sequences, narrowed below; no `CREATE`, no `TEMPORARY`, no `TRUNCATE`, no `TRIGGER` |
| `buffalo_readonly` | yes | same | `SELECT` on tables and sequences |

The cluster superuser (`POSTGRES_USER`, still `buffalo` on existing volumes, configurable as `DB_ADMIN_USERNAME`) only creates extensions, databases and roles.

**`buffalo_app`'s narrowed rights** (`App\Support\Database\DatabaseRoles`):

| List | Tables | Rights |
|---|---|---|
| `APPEND_ONLY` | `activity_log`, `analytics_exports`, `audit_anchors`, `login_histories`, `record_access_logs`, `record_match_events`, `search_logs` | `SELECT, INSERT` |
| `FOLLOW_PLACE` | `flashpoint_assessments`, `incident_status_histories`, `incident_updates` | `SELECT, INSERT, UPDATE (state_id, lga_id)`: their place follows a moved parent (ON UPDATE CASCADE); a trigger rejects any other change |
| `OWNER_ONLY` | `audit_chain_heads`, `migrations` | `SELECT` |

Evidence custody has no table of its own: custody events are in `activity_log` and `record_access_logs`, both append-only. The lookup tables' "no delete" triggers stay as they are; the app keeps DML on them.

**How it is wired:**
- **Connections:** `pgsql` (`buffalo_app`), `pgsql_migrations` (`buffalo_owner`), and `pgsql_admin` (superuser: provisioning, the demo purge and the role tests only).
- **`MigrationConnection`:**
  - `migrate*`, `db:wipe` and `schema:dump` run with `pgsql_migrations` as the default connection when no `--database` is given (restored when the command finishes).
  - After migrations, `applyTablePrivileges()` re-applies the narrowed rights as the owner. Default privileges give new tables the base rights; the lists narrow them.
- **New volumes:** `docker/postgres/init/03-roles.sh` creates the roles and the database, schema and default privileges for `buffalo` and `buffalo_test`. Passwords come from `BUFFALO_DB_*_PASSWORD` or `*_FILE`.
- **Existing databases:** `php artisan buffalo:db:provision-roles [--database=…]` (idempotent, as `pgsql_admin`).
  - It reassigns every non-extension table, view, sequence, routine, enum and domain to `buffalo_owner`, then applies privileges.
  - A failure message has any role password masked.
- **Makefile and CI:** `make setup` runs the provisioning before migrating. `scripts/ci.sh` creates the test database and extensions as `DB_ADMIN_*`, then provisions roles.
- **Tests:**
  - The suite runs connected as `buffalo_app`. `Tests\Support\RefreshDatabaseAsOwner` migrates as the owner (console events are off in tests, so `MigrationConnection` does not apply there).
  - Runtime fixture tables (`scoped_fixture_records`, `access_logged_fixtures`) are created through the owner connection.
  - `DatabaseRolesTest` (6 tests) proves the app role cannot:
    - `ALTER TABLE`, `DISABLE TRIGGER`, `TRUNCATE` or `DROP`;
    - create tables, functions or temporary tables;
    - set `session_replication_role`;
    - `UPDATE` or `DELETE` an append-only table.
  - It also checks:
    - every table's privileges against the design;
    - that every table with an append-only trigger is in the lists (drift test);
    - that the triggers still stop the owner role.

### 8A.2 Secrets (`1b01f54`)

- **`App\Support\Config\Secrets::get(NAME)`:** reads `NAME_FILE` (a Docker secret; it wins over the variable, trailing newline removed) or `NAME`, once per process.
  - An unreadable file fails naming the secret only.
  - Config files use it for `APP_KEY`, `APP_PREVIOUS_KEYS`, `CIPHERSWEET_KEY`, every database password, `REDIS_PASSWORD`, the Reverb key and secret, and the mail username and password.
- **Valkey** (`docker/valkey/entrypoint.sh`):
  - generates an ACL file from env or secret files, storing SHA-256 hashes only;
  - `default` user off;
  - application user `buffalo` with `+@all` minus FLUSHALL, FLUSHDB, CONFIG, KEYS, DEBUG, SHUTDOWN, MONITOR, ACL, MODULE, REPLICAOF/SLAVEOF, SAVE/BGSAVE/BGREWRITEAOF, FAILOVER, CLUSTER, MIGRATE, SWAPDB, SYNC/PSYNC;
  - optional `admin` user (`VALKEY_ADMIN_PASSWORD`);
  - the healthcheck authenticates;
  - Laravel uses `REDIS_USERNAME` and `REDIS_PASSWORD`.
- **Reverb:** key and secret come from secrets; allowed origins are unchanged (`REVERB_ALLOWED_ORIGINS`).
- **`buffalo:secrets:check`** fails on missing, development-default (any `.env.example` value or a known dev value), short or malformed secrets, and prints names only:
  - `APP_KEY`: `base64:` followed by 32 bytes;
  - `CIPHERSWEET_KEY`: 64 hex characters;
  - passwords: 16+ characters;
  - Reverb secret: 32+ characters;
  - mail password: required when `MAIL_USERNAME` is set;
  - `APP_PREVIOUS_KEYS` must not contain the current key.

  It warns when `DB_ADMIN_PASSWORD` reaches an application container.
- **Production entrypoint** (`docker/php/entrypoint.sh`, `ENTRYPOINT` of the prod image): runs the checks before php-fpm, Horizon, the scheduler or Reverb start.
- **CI:** a step asserts that development defaults fail and that strong secrets supplied as files pass.
- **Docs:** `docs/security/secrets.md` covers both methods.

### 8A.3 Key management (`5803481`)

- **Tables:**
  - `key_fingerprints`: `key_name` (`app_key` | `ciphersweet_key`), `fingerprint` (hex, never the key), `status` (`active` | `pending` | `retired`; at most one active and one pending per key), activation and retirement dates;
  - `key_rotations`: CipherSweet rotation progress per model.
- **Fingerprint:** `SHA-256("buffalo-key-fingerprint:v1:{name}:{key}")`.
- **`KeyFingerprints::verify()`:**
  - a new installation records the running keys as active;
  - a match is OK;
  - a running key equal to the pending one is promoted only when the rotation is proven:
    - `APP_KEY`: the active key is among `APP_PREVIOUS_KEYS`;
    - `CIPHERSWEET_KEY`: its `key_rotations` row is `completed`;
  - anything else is a mismatch.
- **Where it runs:** `buffalo:keys:verify` runs at production container start (entrypoint). The production-only middleware `VerifyKeyFingerprints` is a backstop if the entrypoint is bypassed (503 on mismatch; a success is cached 10 minutes per set of running fingerprints).
- **Commands:**
  - `buffalo:keys:fingerprint {key}`: reads the key from stdin, prints its fingerprint and whether it is active, pending, retired or unknown; never prints the key.
  - `buffalo:keys:rotate-app-key`:
    - `--prepare --output=FILE`: new key in a 0600 file, pending fingerprint;
    - the operator sets `APP_KEY` and `APP_PREVIOUS_KEYS` and restarts, and the rotation completes;
    - `--reencrypt`: Fortify two-factor secrets and recovery codes re-encrypted under the new key;
    - with no option, it prints the procedure.
  - `buffalo:keys:rotate-ciphersweet --new-key-file=FILE [--batch] [--max-batches]`:
    - wraps **CipherSweet's own `RowRotator`** (the package's supported rotation approach, as used by `spatie/laravel-ciphersweet`'s `ciphersweet:encrypt`);
    - re-encrypts every encrypted field of `Person` and `OrganizationContact`, soft-deleted rows included, and rebuilds blind indexes with BUFFALO's rule (none for empty values; the package's command would index nulls);
    - runs in batches inside maintenance mode, recording progress per model;
    - resumes after interruption: rows already decrypting under the new key are skipped;
    - leaves the application down until the key is switched and the start-up check promotes it.
  - `buffalo:keys:retire {key}`: a lost key's fingerprints are retired after a typed confirmation, so a replacement can be registered.
  - `buffalo:encryption-key --output=FILE | --show`: a new CipherSweet key without touching `.env`.
- **Docs:** `docs/security/key-management.md`, plain language for the client's IT security officer:
  - where keys live and who may access them;
  - two sealed offline escrow copies held apart from backups, and a paper key log;
  - checking a copy by fingerprint;
  - both rotation procedures;
  - lost keys.

### 8A.4 Audit integrity (`10fff09`)

- **Columns:** `chain_seq`, `prev_hash` and `row_hash` (all NOT NULL; `chain_seq` unique) on the nine tables: `activity_log`, `analytics_exports`, `flashpoint_assessments`, `incident_status_histories`, `incident_updates`, `login_histories`, `record_access_logs`, `record_match_events`, `search_logs`.
- **Hash:** `row_hash = SHA-256(prev_hash || canonical row)`, computed by the BEFORE INSERT trigger `zz_<table>_audit_chain` (named to fire last), using `buffalo_audit_chain()`, which is SECURITY DEFINER and owned by `buffalo_owner`.
- **Canonical row:** `jsonb_strip_nulls(to_jsonb(row) - prev_hash - row_hash - excluded)` rendered with `timezone=UTC`, ISO dates and fixed float output (function-level `SET`).
  - It does not depend on session settings (tested with another time zone and date style).
  - Adding a nullable column later does not break old rows.
  - `state_id`/`lga_id` are left out for the three FOLLOW_PLACE tables, whose place may legitimately move.
- **Ordering approach: a per-table chain-head row** (`audit_chain_heads`), taken `FOR UPDATE` by the trigger, with `chain_seq` assigned under that lock. Verification walks `chain_seq`, so the chain order is the order of commits, not of ids.
- **Effect on insert throughput** (dev, `login_histories`):

  | Load | Without chain | With chain |
  |---|---|---|
  | One session, 5,000 inserts | 16,900/s | 5,700/s |
  | 8 parallel sessions, autocommit | 9,400/s | 2,100/s |

  Inserts into one chained table are serialised until each inserting transaction commits. BUFFALO writes tens of audit rows per second at most, so this is far beyond need; see the deadlock note under known issues.
- **Backfill:** existing rows chained in id order, 5,000 per transaction (`buffalo_audit_backfill()`). The last batch and the trigger are created together under `LOCK TABLE`.
- **`buffalo:audit:verify [--table=] [--since=]`** recomputes hashes in PostgreSQL batch by batch and reports the first broken link (table, row ID, timestamp, reason):
  - altered row;
  - missing rows (sequence gap or broken link);
  - deleted tail (head ahead of the last row).

  It is scheduled nightly at 02:30. Failures log critical and send `AuditChainBroken` (critical, mail allowed) to active super admins and the DG, with the table and row ID only.
- **`buffalo:audit:anchor`** (nightly 02:45) records each head in `audit_anchors` (append-only) and prints them.
- **Tests:** 8, in `tests/Feature/Audit/AuditChainTest.php`:
  - chains build on inserts;
  - hashes are independent of the time zone;
  - the app cannot write heads, and forged hashes are replaced;
  - an edit is detected at the right row and notifications are sent;
  - middle and tail deletions are detected;
  - `--since` works;
  - 4 concurrent processes × 25 inserts keep a valid chain;
  - anchors are recorded.

### 8A.5 Retention groundwork (`9064f25`)

- **BRIN indexes** (`pages_per_range = 32`) on the time column of `activity_log`, `login_histories`, `record_access_logs`, `record_match_events`, `search_logs` and `notifications`.
- **`config/buffalo.php` → `retention`:** ten groups (audit, login history, search logs, analytics exports, notifications, report runs, incidents, persons, cleared persons of interest, threats and flashpoints), each `null` by default with a `BUFFALO_RETENTION_*_DAYS` override. **No deletion command.**
- **`buffalo:retention:report`:** rows and estimated size by year per table, with the configured retention. Read-only.
- **`docs/security/retention.md`:** every data category, its current retention (indefinite), the client's decisions, the automatic deletions that exist, and growth.

**Sizes now (dev):** `activity_log` 381 rows / 0.7 MB; `notifications` 20,644 / 10 MB; `incidents` 50,047 / 115 MB.

**Projected growth at 50,000 incidents a year** (assumptions in `retention.md`):

| Table | Rows per year | Size per year |
|---|---|---|
| `record_access_logs` | 2.0 M | about 1.6 GB |
| `activity_log` | 1.0 M | about 1.3 GB |
| `notifications` | 1.0 M | about 0.5 GB |
| `search_logs` | 0.75 M | about 0.3 GB |
| Incidents | 50,000 | about 0.3 GB |
| `login_histories` | 0.15 M | about 0.1 GB |

Total: roughly 4 GB a year, excluding evidence files.

### 8A.6 Carried-over fixes (`72fb108`)

**Module searches.** The community and criminal group searches use `<%` with the word-similarity threshold (0.4, as before) set per transaction by the new `App\Support\Search\Trigram` helper. `PersonSearch::withThresholds` now delegates to it. The five call sites that run these queries are wrapped:
- the criminal groups list;
- the communities list;
- the communities tab of the LGA page;
- the community and group pickers.

EXPLAIN (dev, `enable_seqscan = off`):

Before:

```
Seq Scan on communities
  Disabled: true
  Filter: ((search_names ~~ '%ajegunle%') OR (word_similarity('ajegunle', search_names) >= 0.4))
Seq Scan on criminal_groups
  Disabled: true
  Filter: ((search_name ~~ '%black axe%') OR (word_similarity('black axe', search_name) >= 0.4))
```

After:

```
Bitmap Heap Scan on communities
  ->  BitmapOr
        ->  Bitmap Index Scan on communities_search_names_trgm   Index Cond: (search_names ~~ '%ajegunle%')
        ->  Bitmap Index Scan on communities_search_names_trgm   Index Cond: (search_names %> 'ajegunle')
Bitmap Heap Scan on criminal_groups
  ->  BitmapOr
        ->  Bitmap Index Scan on criminal_groups_search_name_trgm   Index Cond: (search_name ~~ '%black axe%')
        ->  Bitmap Index Scan on criminal_groups_search_name_trgm   Index Cond: (search_name %> 'black axe')
```

`word_similarity()` remains only in `ORDER BY` (ranking). `ModuleSearchIndexTest` locks this in, including typo tolerance ("Ajegnle", "Blak Axe").

**Sign-out.**
- **Client:** `resources/js/lib/sign-out.ts` `clearClientState()` (Vitest test) is used by all four sign-out paths (user menu, idle timeout, two-factor setup page, 403 page):
  - `router.flushAll()` (prefetch cache);
  - `router.clearHistory()` (discards the history encryption key);
  - recent searches (7B);
  - `sessionStorage`.

  The appearance preference (not per user) is kept.
- **Server:**
  - Inertia history encryption is on for every page (`INERTIA_ENCRYPT_HISTORY`, default true);
  - the sign-in page always sends `clearHistory`, which covers expired sessions;
  - signed-in HTML and Inertia responses send `Cache-Control: no-store, private` (`PreventAuthenticatedCaching`; file downloads keep their own headers), keeping them out of the back/forward cache.

## Acceptance checklist

- [x] **The suite and the app run as `buffalo_app`:**
  - 1,411 Pest tests (10,611 assertions) pass connected as `buffalo_app`;
  - dev requests were made as `buffalo_app`;
  - the role tests pass;
  - psql as `buffalo_app`: `UPDATE activity_log` gives "permission denied", `ALTER … DISABLE TRIGGER` gives "must be owner", `TRUNCATE search_logs` gives "permission denied", and `CREATE TABLE` and temporary tables are refused.
- [x] **Secrets check:**
  - the production entrypoint with the dev `.env` refused to start: `DB_PASSWORD`, `REDIS_PASSWORD`, `DB_MIGRATIONS_PASSWORD` and `DB_READONLY_PASSWORD` still have defaults, and `REVERB_APP_SECRET` is too short (names only);
  - with strong secrets as files under `/run/secrets` it passed.
- [x] **Valkey:**
  - an anonymous `GET` gives `NOAUTH`; a wrong password gives `WRONGPASS`;
  - the app user's FLUSHALL, FLUSHDB, CONFIG and KEYS give `NOPERM` (DEBUG is also refused);
  - the admin user can run `CONFIG`;
  - the app, Horizon, Reverb and the scheduler work with authentication.
- [x] **A different `CIPHERSWEET_KEY` refuses to boot:**
  - The real entrypoint ran in production mode, with strong secrets as files (`buffalo_app` was given a temporary strong password, restored afterwards).
  - With a wrong `CIPHERSWEET_KEY`, `buffalo:keys:verify` showed "MISMATCH: the running key does not match the active fingerprint", then "refusing to start", exit 1.
  - With the right key: both keys "OK: active", the process started, exit 0.
- [x] **CipherSweet rotation on a fixture dataset** (Pest):
  - the fixture was 9 persons (with NIN, phone, date of birth and address; one with none; one soft-deleted) and an organization contact;
  - the rotation was interrupted after one batch, then resumed;
  - every value decrypts with the new key, every blind-index search (NIN, phone, date of birth, contact phone) finds exactly the right record, the empty person has no indexes, and the old key no longer reads the data;
  - the start-up check promoted the new key.
- [x] **An altered `activity_log` row is reported and notified.** On dev, row 201's description was changed as the superuser.
  - `buffalo:audit:verify` reported "BROKEN at row 201 (2026-09-27 07:20:11 WAT): the row was altered".
  - Both super admins and the DG received the critical notification "The audit integrity check failed for activity_log at row 201".
  - After the original text was restored, every chain verifies as intact.
- [x] **Community and group searches use their trigram indexes** (EXPLAIN above and the tests).
- [x] **Sign-out and Back** (browser):
  - As sd.lagos: the persons list, then a person page, then sign out from the user menu.
  - Back, twice, showed only the sign-in page: the document title is "Log in", and the person's and the list's names are absent.
  - Recent searches were cleared.
- [x] **Checks:** `make test`, `make lint` and `make ci` pass: 1,411 Pest tests (10,611 assertions) plus 14 Vitest. CI includes the production-like secrets check (defaults refused, file secrets accepted) and runs the suite as `buffalo_app`.

## Files

### Created

- **Database:**
  - `database/migrations/2026_10_17_000001_create_key_management_tables.php`
  - `database/migrations/2026_10_17_000002_add_audit_hash_chains.php`
  - `database/migrations/2026_10_17_000003_add_audit_brin_indexes.php`
- **Infrastructure:** `docker/postgres/init/03-roles.sh`, `docker/valkey/entrypoint.sh`, `docker/php/entrypoint.sh`
- **Support:**
  - `app/Support/Database/{DatabaseRoles, MigrationConnection}.php`
  - `app/Support/Config/Secrets.php`
  - `app/Support/Keys/KeyFingerprints.php`
  - `app/Support/Audit/AuditChain.php`
  - `app/Support/Search/Trigram.php`
- **Commands:** `app/Console/Commands/{ProvisionDatabaseRoles, CheckSecrets, VerifyKeys, FingerprintKey, RotateAppKey, RotateCipherSweetKey, RetireKey, VerifyAuditChains, AnchorAuditChains, RetentionReport}.php`
- **Middleware:** `app/Http/Middleware/{VerifyKeyFingerprints, PreventAuthenticatedCaching}.php`
- **Notifications:** `app/Notifications/AuditChainBroken.php`
- **Frontend:** `resources/js/lib/sign-out.ts`, `resources/js/lib/sign-out.test.ts`
- **Tests:**
  - `tests/Feature/Database/DatabaseRolesTest.php`
  - `tests/Feature/Security/{SecretsTest, KeyManagementTest, RetentionTest}.php`
  - `tests/Feature/Audit/AuditChainTest.php`
  - `tests/Feature/Search/ModuleSearchIndexTest.php`
  - `tests/Feature/Auth/SignOutClearsStateTest.php`
  - `tests/Support/RefreshDatabaseAsOwner.php`
  - `tests/Support/Database/Statistics.php`
- **Docs:**
  - `docs/security/{secrets, key-management, retention}.md`
  - `docs/prompts/phase-8a.md`

### Changed

- **Config:** `config/database.php` (connections, roles), `app.php`, `ciphersweet.php`, `mail.php`, `reverb.php`, `broadcasting.php` (Secrets), `buffalo.php` (retention, reports queue), `inertia.php` (history encryption)
- **Bootstrap and providers:**
  - `bootstrap/app.php` (two middleware)
  - `AppServiceProvider` (migration hook)
  - `FortifyServiceProvider` (sign-in clears history)
- **Models:** `Community`, `CriminalGroup` (index operators)
- **Support:** `PersonSearch` (delegates to Trigram)
- **Controllers:**
  - `CriminalGroupController`, `CommunityController`, `LgaController`, `PickerController` (thresholds)
- **Commands:**
  - `SeedDemoIncidents` (purge via `pgsql_admin`, refused in production; ANALYZE as the owner)
  - `GenerateEncryptionKeyCommand` (`--output`, `--show`)
- **Frontend:**
  - `user-menu-content.tsx`, `session-idle-warning.tsx`, `auth/two-factor-setup.tsx`, `errors/status.tsx` (sign-out cleanup)
- **Infrastructure:**
  - `compose.yml` (superuser and role passwords, Valkey ACL)
  - `docker/php/Dockerfile` (entrypoint)
  - `Makefile` (provisioning)
  - `scripts/ci.sh` (roles, secrets check)
  - `phpunit.xml` (earlier: reports queue)
  - `.env.example` (role, Valkey and admin credentials)
- **Routes:** `routes/console.php` (nightly verify and anchor)
- **Tests:**
  - `tests/Pest.php`
  - fixtures (owner-created tables)
  - the append-only tests (now "permission denied" for the app role)
  - the index tests (`Statistics::analyze`)
- **Docs:** `README.md`

## Deviations and decisions

1. **`buffalo_owner` is a LOGIN role** with its own password, used only by `pgsql_migrations`. That is the cleaner option: the alternative (NOLOGIN plus `SET ROLE`) needs a login role that is a member of the owner (then the application role would have to be, or a second migration login would exist), or the superuser in the application environment. In production, give `DB_MIGRATIONS_PASSWORD` only to the container or job that runs migrations.
2. **Follow-place tables** (`incident_status_histories`, `incident_updates`, `flashpoint_assessments`) are listed by the prompt as append-only, but the existing design lets their place follow a moved parent. The app role gets `UPDATE (state_id, lga_id)` only. The cascade runs with the owner's rights anyway, so this is extra safety, not a requirement. The hash chain excludes those two columns for the same reason.
3. **Schema commands use the owner connection automatically** via console events. Setting the `--database` option in the event does not work: Symfony re-binds the input in `run()`, so the default connection is switched instead and restored afterwards. Tests use a wrapper trait, because console events are off in tests.
4. **ANALYZE in tests:** `buffalo_app` has no `MAINTAIN` privilege, so ANALYZE was silently skipped and three index tests saw sequential scans. `Statistics::analyze()` grants `MAINTAIN` on the named tables in the test database only, analyzes in the test's own session, and revokes it at once. Production `buffalo_app` has no `MAINTAIN`.
5. **The demo purge** (`buffalo:seed-demo-incidents --purge`, development only) needs `session_replication_role`, so it uses `pgsql_admin` and is refused in production.
6. **Extra chain column `chain_seq`.** Concurrent transactions get ids in a different order from the order they acquire the chain lock, so the chain is ordered by a sequence assigned under the lock.
7. **The canonical form drops null values**, so adding a nullable column to an audit table later does not break the chains. A future column with a non-null default would change old rows' content: such a migration must re-hash the chain (run the backfill function again from the start) and record new anchors.
8. **No "System health" view yet** (8C). Anchors are stored in `audit_anchors` and printed by `buffalo:audit:anchor`; the 8C view should list the latest per table.
9. **Automatic deletions other than report files exist,** contrary to the prompt's "only existing automatic deletion". All of them are documented in `retention.md`:
   - password history beyond the last 5 hashes (Phase 1);
   - open blackspot suggestions replaced nightly (6C);
   - boundary display copies rebuilt on import (6A);
   - Horizon trimming job metadata in Valkey;
   - expiring sessions and tokens.
10. **`PreventAuthenticatedCaching` and global history encryption** go slightly beyond the sign-out item, but are needed for "Back shows nothing": `no-cache` alone lets browsers keep pages in the back/forward cache. Security headers in general remain 8B.
11. **The superuser keeps its existing name** (`buffalo`) on existing volumes; `DB_ADMIN_USERNAME` names it. `DB_USERNAME` and `DB_PASSWORD` now mean the application role.

## New packages

None. The Valkey ACL, Docker secrets and CipherSweet rotation use what is already installed.

## Known issues and notes

- **Development credentials in `.env`:** the app container sees `DB_ADMIN_PASSWORD` and `DB_MIGRATIONS_PASSWORD` in development (one `.env`). `buffalo:secrets:check` warns about the admin password. Production must give each container only its own secrets (8C).
- **Valkey entrypoint as a bind mount:** `docker/valkey/entrypoint.sh` is bind-mounted from the repository in `compose.yml`. 8C (container hardening) should bake it into an image for servers without the source tree.
- **`cache:clear` and `optimize:clear`** use FLUSHDB and now fail for the application user. Use the Valkey admin user, or rely on the version-keyed caches (`LayerCache`), which do not need flushing.
- **Chain locks and deadlocks:** a transaction that inserts into two chained tables holds both head rows until commit. Two transactions inserting in opposite orders could deadlock (PostgreSQL aborts one after 1 s). Audit writes mostly happen in short autocommit statements, and none appeared in the suite or in acceptance. 8B/8C load tests should watch for `deadlock detected`.
- **`config:cache`** stores secret values (read from files) in `bootstrap/cache/config.php`. Protect that directory like the secrets, or skip config caching (`secrets.md`).
- **History encryption needs HTTPS in production** (Web Crypto requires a secure context). On plain HTTP (other than localhost) Inertia cannot encrypt history. This should be enforced with the 8B security headers.
- **Dev database changes:**
  - roles provisioned;
  - the three migrations applied;
  - `key_fingerprints` registered;
  - acceptance notifications to the super admins and the DG;
  - `activity_log` row 201 was altered and restored (the chain verifies);
  - musa.bello's report schedule remains from 7D.
- **For 8B:** step-up authentication for key and backup operations should cover `buffalo:keys:*` run through any future UI, and large exports (CLAUDE.md decisions log).
- **For 8C:**
  - back up `key_fingerprints` and `audit_anchors` with the database;
  - **never** back up the keys with the data (see `key-management.md`);
  - pin the Gotenberg and Valkey images by digest;
  - the System health view (anchors, last verify result).
