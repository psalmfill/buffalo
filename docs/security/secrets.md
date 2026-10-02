# BUFFALO secrets

This page lists every secret BUFFALO needs and the two ways to supply it. It is for whoever installs and runs the system.

## The secrets

| Secret | What it protects | Who needs it |
|---|---|---|
| `APP_KEY` | Sessions, signed links, hit tokens, two-factor secrets (Laravel encryption) | app, Horizon, scheduler, Reverb |
| `APP_PREVIOUS_KEYS` | Older `APP_KEY` values during a rotation (comma-separated) | same, only during a rotation |
| `CIPHERSWEET_KEY` | Encrypted personal data (NIN, phone, date of birth, address, contact details) | app, Horizon, scheduler |
| `DB_PASSWORD` | Database login of `buffalo_app`, the application | app, Horizon, scheduler, Reverb |
| `DB_MIGRATIONS_PASSWORD` | Database login of `buffalo_owner`, which runs migrations | only the container that runs `php artisan migrate` |
| `DB_READONLY_PASSWORD` | Database login of `buffalo_readonly` (backup checks, reporting tools) | only those tools |
| `DB_ADMIN_PASSWORD` | The PostgreSQL superuser | the database container, and `buffalo:db:provision-roles` when it is run |
| `REDIS_PASSWORD` | Valkey login of the application user (`REDIS_USERNAME`, default `buffalo`) | app, Horizon, scheduler, Reverb, Valkey |
| `VALKEY_ADMIN_PASSWORD` | Valkey operator account (`admin`): `cache:clear`, maintenance | Valkey; operators |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Real-time connections | app, Reverb |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | The mail relay, if one is used | app, Horizon |

`CIPHERSWEET_KEY` and `APP_KEY` are covered in more detail in `key-management.md`. Losing `CIPHERSWEET_KEY` makes encrypted personal data unrecoverable.

## Two ways to supply a secret

1. **Environment variable** (or the `.env` file), for example:

   ```
   DB_PASSWORD=…
   ```

2. **A file, for example a Docker secret.** Set `NAME_FILE` to the file's path:

   ```
   DB_PASSWORD_FILE=/run/secrets/db_password
   ```

   - The file holds only the value; a trailing newline is ignored.
   - If both `NAME` and `NAME_FILE` are set, the file wins.
   - The application reads each file once when it starts (`App\Support\Config\Secrets`).
   - `php artisan config:cache` copies the values into the cached configuration, so protect `bootstrap/cache` like the secrets themselves, or do not cache configuration.

The PostgreSQL container accepts `POSTGRES_PASSWORD_FILE` for the superuser, and `BUFFALO_DB_OWNER_PASSWORD_FILE`, `BUFFALO_DB_APP_PASSWORD_FILE` and `BUFFALO_DB_READONLY_PASSWORD_FILE` for the three roles; these are read on its first start only. The Valkey container accepts `VALKEY_APP_PASSWORD_FILE` and `VALKEY_ADMIN_PASSWORD_FILE`.

Docker Compose example:

```yaml
secrets:
  db_password:
    file: /etc/buffalo/secrets/db_password   # mode 0400, owned by root
services:
  app:
    secrets: [db_password]
    environment:
      DB_PASSWORD_FILE: /run/secrets/db_password
```

## The start-up check

In production (`APP_ENV=production`), every application container runs `php artisan buffalo:secrets:check` before starting. It stops the container if any secret:

- is missing (`APP_KEY`, `CIPHERSWEET_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` are always required; `MAIL_PASSWORD` is required when `MAIL_USERNAME` is set);
- still has a development value from `.env.example` (for example `buffalo-app-dev`);
- is too short or malformed:
  - `APP_KEY`: `base64:` followed by 32 random bytes;
  - `CIPHERSWEET_KEY`: 64 hexadecimal characters;
  - passwords: at least 16 characters (mail: 12);
  - `REVERB_APP_SECRET`: at least 32 characters.

The check prints secret names and the problem, never the values. It also warns when the superuser password (`DB_ADMIN_PASSWORD`) is visible to an application container: it should not be.

You can run it by hand at any time:

```
php artisan buffalo:secrets:check
```

## Generating values

```
php artisan key:generate --show                        # APP_KEY
php artisan buffalo:encryption-key --show              # CIPHERSWEET_KEY (new installations only)
head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n'    # passwords and Reverb values
```

Never reuse a value between installations, and never reuse the development values.
