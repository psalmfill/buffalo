# BUFFALO — Phase 8A: Database, Secrets and Audit Integrity

Read `CLAUDE.md` before starting (updated for Phase 8: stack table and decisions log). Read `docs/handover/phase-7-summary.md`.

If anything here conflicts with what earlier phases actually built, follow the existing code and report the conflict in the handover.

## Context

Phases 0–7 built the complete application. Phase 8 makes it fit to hold real intelligence. It is split into:

- **8A (this step):** database roles, secrets and key management, audit integrity, retention groundwork, and carried-over fixes.
- **8B:** application security: headers, step-up authentication, export controls, malware scanning, schedule safety, and security testing.
- **8C:** backup and recovery, monitoring, and container hardening.

The deployment target is still undecided. Everything here must work on an air-gapped server, an internet-connected government server, or a cloud VM.

---

## Step 8A.1 — Database roles

Phase 0 noted the application connects as the Postgres superuser. That lets a compromised application disable triggers (append-only audit, locks) or alter tables.

1. Three roles, created by the init scripts (and by an idempotent Artisan command for existing databases, `buffalo:db:provision-roles`, run as the superuser):
   - `buffalo_owner`: owns all schema objects; used **only** to run migrations. `NOLOGIN` unless migrating is configured to `SET ROLE`; choose the cleaner approach and report it.
   - `buffalo_app`: the application's login role. `NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS`. Granted `SELECT, INSERT, UPDATE, DELETE` on tables and `USAGE` on sequences as needed, **except**: append-only tables (activity log, login histories, record access logs, match events, status histories, incident updates, flashpoint assessments, search logs, analytics exports, and any others; list them) get `SELECT, INSERT` only. Default privileges so future tables follow the rule.
   - `buffalo_readonly`: `SELECT` only, for backups verification and future reporting tools.
2. Laravel uses separate connections: `pgsql` (as `buffalo_app`) for the application, and a `pgsql_migrations` connection (as `buffalo_owner`) used by `migrate` only. `make setup`, CI and `make fresh` follow this.
3. Extensions remain created by the superuser in the init scripts.
4. Tests (run against the real roles, not as superuser): the app role cannot `ALTER TABLE`, `DISABLE TRIGGER`, `TRUNCATE`, or `UPDATE`/`DELETE` an append-only table, and cannot create objects. The whole test suite passes connected as `buffalo_app`.

**Commit:** `feat(db): separate owner, application and read-only roles`

## Step 8A.2 — Secrets

1. Support Docker secrets alongside environment variables: every secret (`APP_KEY`, `CIPHERSWEET_KEY`, database passwords, Valkey password, Reverb secrets, mail credentials) can be supplied as `NAME_FILE=/run/secrets/...`; read once at boot in a small config helper. Document both methods.
2. **Valkey:** require a password (`requirepass` or an ACL user for the app), disable dangerous commands for the app user (`FLUSHALL`, `FLUSHDB`, `CONFIG`, `KEYS`, `DEBUG`), and update Laravel's connections.
3. **Reverb:** production app key and secret from secrets; allowed origins from config (already restricted in Phase 0).
4. A `buffalo:secrets:check` command (run at container start in production mode and in CI with a production-like env): fails if any secret is missing, still equals a development default from `.env.example`, or is too short; prints only names, never values.

## Step 8A.3 — Key management

BUFFALO depends on two critical keys: `APP_KEY` (sessions, signed URLs, hit tokens, Laravel `Crypt`) and `CIPHERSWEET_KEY` (all encrypted personal data). Losing `CIPHERSWEET_KEY` makes encrypted data unrecoverable.

1. **Key fingerprints:** store a SHA-256 fingerprint of each key (never the key) in a `key_fingerprints` table with the date it became active. At boot, verify the running keys match the active fingerprints; on mismatch, refuse to start in production with a clear message (prevents silently writing data with the wrong key after a misconfigured restore).
2. **`APP_KEY` rotation:** document and test Laravel's previous-keys mechanism (`APP_PREVIOUS_KEYS`); a `buffalo:keys:rotate-app-key` procedure (documented, step by step) and a test that data encrypted with `Crypt` under the old key remains readable.
3. **`CIPHERSWEET_KEY` rotation:** a command `buffalo:keys:rotate-ciphersweet` that re-encrypts every encrypted field and rebuilds blind indexes in batches, resumable, with progress, inside maintenance mode; verify the package's supported rotation approach first and report it. Test on a fixture dataset: all values decrypt and all blind index searches still match after rotation.
4. **Key escrow procedure:** write `docs/security/key-management.md`: where keys live, who may access them, how they are backed up **separately from data backups** (for example, two sealed offline copies held by different officers), how to verify a backup copy against the stored fingerprint without exposing it (`buffalo:keys:fingerprint` reads a key from stdin and prints its fingerprint), and the rotation procedures. Plain language, for the client's IT security officer.

## Step 8A.4 — Audit integrity

Triggers stop the application from altering audit records, but a database superuser or someone with disk access could still change them. Make alteration **detectable**:

1. Add `prev_hash` and `row_hash` columns to each append-only audit table (activity log, login histories, record access logs, match events, search logs, analytics exports, incident status histories, incident updates, flashpoint assessments). `row_hash = SHA-256(prev_hash || canonical serialisation of the row's content columns)`, computed in a `BEFORE INSERT` trigger in PostgreSQL (not in PHP), with chains kept per table. Use an advisory lock or a per-table chain-head row so concurrent inserts stay ordered; report the approach and its effect on insert throughput.
2. Backfill existing rows in order, in batches.
3. `buffalo:audit:verify [--table=] [--since=]` walks each chain and reports the first broken link (table, row ID, timestamp). Scheduled nightly; any failure notifies super admins and the DG (`critical`; payload: table name and row ID only).
4. **Anchoring:** nightly, record each chain's head hash in `audit_anchors` (table, head row ID, head hash, created at) and print it in the super admin "System health" view (8C), so heads can be copied to an external record (for example, the client's paper log) if they choose.
5. Tests: inserting builds a valid chain; editing a past row (as superuser in the test) or deleting one is detected at the correct position; concurrent inserts keep a valid chain.

## Step 8A.5 — Retention groundwork

The client has not yet set retention periods. Until they do, nothing is deleted.

1. Add BRIN indexes on `created_at` for the large append-only tables (they are naturally time-ordered), and report table sizes and projected growth at 50,000 incidents a year.
2. A `config/buffalo.php` → `retention` section with `null` (keep forever) defaults per table group, and a documented `buffalo:retention:report` command showing row counts and sizes by year per table. **No deletion command is built in this phase.**
3. Write `docs/security/retention.md` listing every data category, its current retention (indefinite), and the decisions the client must make (for example, cleared persons of interest, expired report files, search logs). The expired report file deletion (7D) is the only existing automatic deletion; document it.

## Step 8A.6 — Carried-over fixes

1. Community and criminal group module searches: replace `word_similarity() >=` filters with index-backed operators (`<%` / `%` with thresholds set per transaction), as CLAUDE.md requires; `EXPLAIN` before and after in the handover.
2. Sign-out clears client-side state: Inertia history (`clearHistory`), the recent-searches list in localStorage (7B), and any other per-user browser storage. Test that after sign-out, pressing Back shows no cached authenticated page content.

## Step 8A.7 — Tests and acceptance

- [ ] The full test suite and the app run connected as `buffalo_app`; the role tests in 8A.1 pass.
- [ ] `buffalo:secrets:check` fails on development defaults and passes with real secrets supplied as files.
- [ ] Valkey rejects unauthenticated clients and blocked commands.
- [ ] Starting with a different `CIPHERSWEET_KEY` than the active fingerprint refuses to boot in production mode.
- [ ] `CIPHERSWEET_KEY` rotation on a fixture dataset leaves all data readable and searchable.
- [ ] Altering one activity log row by hand is reported by `buffalo:audit:verify` at the right row, and super admins are notified.
- [ ] Community and group searches use their trigram indexes.
- [ ] `make test`, `make lint` and `make ci` pass.

## Handover

Write `docs/handover/phase-8a.md` per CLAUDE.md section 9 and print it.
