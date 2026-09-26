# Field encryption key (CIPHERSWEET_KEY)

> **Losing this key makes all encrypted person data unrecoverable.**
> Back it up **separately from the database**, and **never commit it**.
> Key backup and rotation procedures are built in Phase 8.

## What it protects

Since Phase 3A, these person fields are encrypted in the database with
[CipherSweet](https://github.com/paragonie/ciphersweet) (through
`spatie/laravel-ciphersweet`, backend `nacl`: XChaCha20-Poly1305):

| Field | Blind index (exact-match search) |
|---|---|
| `persons.nin` | `nin_bidx` |
| `persons.phone` (stored as `+234XXXXXXXXXX`) | `phone_bidx` |
| `persons.date_of_birth` | `dob_bidx` |
| `persons.address` | none |

The database holds only ciphertext (`nacl:…`); CHECK constraints reject
anything else. Blind indexes (keyed hashes) are in the `blind_indexes` table.
Both the ciphertext and the blind indexes are derived from `CIPHERSWEET_KEY`.

## Rules

1. **One key per installation, created once.** `make setup` runs
   `php artisan buffalo:encryption-key`, which writes `CIPHERSWEET_KEY` to
   `.env` only if no key exists. It refuses to replace an existing key.
   The package's own `ciphersweet:generate-key` command is disabled: it
   silently overwrites an existing key outside production.
2. **Back it up at once, separately from the database.** A database backup
   without the key is useless for person data; the key stored with the
   database backup protects nothing. Keep at least two copies in separate,
   access-controlled places (for example, sealed offline media held by two
   different officers).
3. **Never commit it**, never paste it into tickets or chat, and never put
   it in a Docker image. `.env` is git-ignored.
4. **Never regenerate it for an existing database.** A new key cannot
   decrypt existing data, and every blind index search stops matching.
5. **Every environment has its own key** (development, UAT, production).
   Tests use a fixed test-only key from `phpunit.xml`.

## If the key is missing

The application fails with a clear error as soon as it needs to encrypt or
decrypt ("CIPHERSWEET_KEY is not set…"). Restore the key from its backup;
do not generate a new one for an existing database.

## Rotation

Not yet supported. Rotating means re-encrypting every row and rebuilding
every blind index with the new key (`ciphersweet:encrypt` exists in the
package for this). Phase 8 will define and test the procedure.
