# BUFFALO key management

For the client's IT security officer. This page explains BUFFALO's two critical keys, who may hold them, how to keep copies safe, how to check a copy, and how to change (rotate) them.

## The two keys

| Key | What it protects | If it is lost | If it leaks |
|---|---|---|---|
| **`CIPHERSWEET_KEY`** | Personal data stored encrypted: NIN, phone numbers, date of birth and address of persons; phone and email of organization contacts. Also the "blind indexes" that let officers search by NIN or phone. | **The encrypted data is gone for good.** Nobody, including the developers, can recover it. Backups are useless without the key that was in use when they were made. | Anyone holding the key and a copy of the database can read the protected fields. Rotate the key. |
| **`APP_KEY`** | Signed-in sessions, signed links, cross-jurisdiction hit tokens, and users' two-factor (TOTP) secrets. | Everyone is signed out, and every user must set up two-factor authentication again (an administrator resets it). No intelligence data is lost. | Someone could forge sessions or links. Rotate the key and end all sessions. |

Both are 32 random bytes. `CIPHERSWEET_KEY` is written as 64 hexadecimal characters; `APP_KEY` is written as `base64:` followed by 44 characters.

## Where the keys live

- **On the server:** as Docker secrets (files under `/run/secrets/`, readable only by the containers) or environment variables. See `secrets.md`. They are never in the database, the code, the logs or the backups.
- **In escrow:** two sealed offline copies (below).
- **Nowhere else.** Not in email, chat, tickets or shared drives. Not on the officers' own devices.

## Who may access them

- **Day to day:** nobody needs to see the keys. The system reads them itself.
- **Installing, rotating or restoring:** two named officers, for example the system administrator and the IT security officer. One opens the sealed copy; the other witnesses and signs the key log (below).
- **Developers and suppliers:** they never receive production keys.

Keep a **key log** on paper: for every event, the date, the key name, the first 12 characters of the fingerprint, what was done (created, checked, copy made, rotated, copy destroyed) and two signatures.

## Fingerprints: checking a key without revealing it

BUFFALO stores a **fingerprint** of each key in its database (table `key_fingerprints`), never the key itself. A fingerprint is a 64-character code calculated from the key. The same key always gives the same fingerprint, but the key cannot be worked out from it.

### At every start

Every BUFFALO container compares the running keys with the fingerprints in the database. If they do not match, it **refuses to start** and says so. This prevents, for example, restoring a database and starting it with another installation's keys, which would silently write new data that can never be read together with the old.

- The first start of a new installation records the fingerprints.
- To check by hand:

  ```
  php artisan buffalo:keys:verify
  ```

### Checking an escrow copy

Insert the USB drive holding the sealed copy into the server (or a computer with BUFFALO), and run:

```
php artisan buffalo:keys:fingerprint ciphersweet_key < /media/usb/ciphersweet_key
```

- The key is read from the file and never shown.
- The command prints its fingerprint and says whether it is the **active** key, the **pending** key of a rotation in progress, a **retired** key, or unknown.
- Use `app_key` instead of `ciphersweet_key` for the other key.
- Write the first 12 characters of the fingerprint in the key log.

Check both escrow copies at least once a year and after every rotation.

## Escrow: copies kept separately from data backups

Database backups (Phase 8C) never contain the keys. Keys and backups must be stored apart: someone who steals a backup must not also get the key.

1. **Two copies of each key,** each on its own new USB drive, in a file holding only the key (`ciphersweet_key`, `app_key`).
2. **Seal each drive** in a tamper-evident envelope. Record the envelope number and the fingerprint in the key log.
3. **Store them apart,** held by different officers, in two locked safes in two different buildings. Neither should be where the backups are kept.
4. **After every rotation,** add the new key's copies the same way.
5. **Keep retired `CIPHERSWEET_KEY` copies** for as long as any backup made while that key was active still exists. When the last such backup is destroyed, destroy the retired copies (physically destroy the drive) and record it.

Retired `APP_KEY` copies can be destroyed once the rotation is complete.

## Creating the keys for a new installation

```
php artisan key:generate --show                                 # APP_KEY: copy into the secret file
php artisan buffalo:encryption-key --output=/secure/ciphersweet_key
```

Put the values in the secret files, start BUFFALO (the first start records the fingerprints), then make the escrow copies **before entering any real data**.

## Rotating `APP_KEY`

When: if the key may have leaked, when an officer who held it leaves, or on a fixed schedule (for example every two years).

Laravel accepts "previous keys", so nobody is signed out mid-rotation.

1. **Prepare** a new key in a new file (it is never printed):

   ```
   php artisan buffalo:keys:rotate-app-key --prepare --output=/secure/app_key.new
   ```

   Its fingerprint is recorded as **pending**.
2. **Switch keys:**
   - Set `APP_KEY` to the new key.
   - Set `APP_PREVIOUS_KEYS` to the old key.
   - Restart every container. The start-up check sees the pending key, with the old active key among the previous keys, and completes the rotation (the new key becomes active, the old one retired).
3. **Re-encrypt** stored values (users' two-factor secrets) under the new key:

   ```
   php artisan buffalo:keys:rotate-app-key --reencrypt
   ```

4. **Remove the old key:** after the session lifetime (30 minutes by default, `SESSION_LIFETIME`), remove `APP_PREVIOUS_KEYS` and restart. Make the escrow copies of the new key; destroy the old copies.

If the old key may have leaked, also end all sessions (restart Valkey, or remove its session keys as the Valkey admin user) and consider asking users to set up two-factor authentication again.

## Rotating `CIPHERSWEET_KEY`

When: if the key may have leaked, or on a fixed schedule. This re-encrypts every protected field, so it takes the system offline for a while. At 50,000 persons it takes roughly a few minutes; test on a copy first.

1. **Take a full backup** (Phase 8C) and check it. It still uses the old key, so keep the old key in escrow.
2. **Create the new key** in a new file:

   ```
   php artisan buffalo:encryption-key --output=/secure/ciphersweet_key.new
   ```

3. **Re-encrypt** everything, still running with the old key:

   ```
   php artisan buffalo:keys:rotate-ciphersweet --new-key-file=/secure/ciphersweet_key.new
   ```

   - BUFFALO goes into **maintenance mode** (users see "service unavailable").
   - Every encrypted field and blind index is re-encrypted in batches, with a progress bar.
   - If it is interrupted (power cut, closed terminal), run the same command again: it resumes where it stopped and skips rows already done.
   - BUFFALO stays in maintenance mode when it finishes: the data now needs the new key.
4. **Switch keys:** set `CIPHERSWEET_KEY` to the new key and restart every container. The start-up check confirms the re-encryption completed and makes the new key active. If you restart with the new key before the re-encryption has finished, BUFFALO refuses to start, as designed.
5. **Bring BUFFALO back:**

   ```
   php artisan up
   ```

   Then check a few persons and a search by NIN.
6. **Escrow:** make the two sealed copies of the new key. **Keep the old key's copies** until every backup made before the rotation has been destroyed.

This uses CipherSweet's own key-rotation feature (`RowRotator`), the method its authors support, wrapped so it is resumable and runs in maintenance mode.

## If a key is lost

- **`APP_KEY` lost:**
  1. Create a new key (as for a new installation). The start-up check would refuse the unknown key, so first retire the lost one's fingerprint (you are asked to type a confirmation):

     ```
     php artisan buffalo:keys:retire app_key
     ```

     Then start BUFFALO with the new key; the start-up check records it.
  2. Users sign in again and set up two-factor authentication again.
  3. Record what happened.
- **`CIPHERSWEET_KEY` lost** with no escrow copy: the protected fields cannot be recovered. To keep using BUFFALO, run `php artisan buffalo:keys:retire ciphersweet_key` and start with a new key; the old protected values remain unreadable and must be entered again. Everything else (names, incidents, threats and so on) is intact. Restore the key from escrow if at all possible. This is why two copies are kept apart.
