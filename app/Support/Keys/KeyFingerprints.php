<?php

namespace App\Support\Keys;

use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fingerprints of the two critical keys (Phase 8A.3): `app_key` (APP_KEY)
 * and `ciphersweet_key` (CIPHERSWEET_KEY). A fingerprint is a SHA-256 of
 * the key with a fixed, per-key prefix; it identifies a key without
 * revealing it (keys are 32 random bytes, so it cannot be reversed).
 *
 * verify() compares the running keys with the active fingerprints:
 * - no active fingerprint yet (new installation): the running key is
 *   recorded as active;
 * - the running key matches the active fingerprint: OK;
 * - the running key matches a pending fingerprint whose rotation is proven
 *   (APP_KEY: the active key is among APP_PREVIOUS_KEYS; CIPHERSWEET_KEY:
 *   its re-encryption has completed): it is promoted, and the old key is
 *   retired;
 * - anything else is a mismatch: production refuses to start, so data is
 *   never written with the wrong key after a misconfigured restore.
 */
final class KeyFingerprints
{
    public const NAMES = ['app_key', 'ciphersweet_key'];

    public static function fingerprint(string $name, string $key): string
    {
        return hash('sha256', "buffalo-key-fingerprint:v1:{$name}:{$key}");
    }

    /** The key the application is running with. */
    public static function runningKey(string $name): string
    {
        $key = match ($name) {
            'app_key' => config('app.key'),
            'ciphersweet_key' => config('ciphersweet.providers.string.key'),
            default => throw new RuntimeException("Unknown key {$name}."),
        };

        if (! is_string($key) || $key === '') {
            throw new RuntimeException("The key {$name} is not configured.");
        }

        return $key;
    }

    /**
     * @return array{active: string|null, pending: string|null}
     */
    public static function stored(string $name): array
    {
        $rows = DB::table('key_fingerprints')->where('key_name', $name)->whereIn('status', ['active', 'pending'])->pluck('fingerprint', 'status');

        return ['active' => $rows['active'] ?? null, 'pending' => $rows['pending'] ?? null];
    }

    /**
     * Verifies every key; records or promotes where allowed.
     *
     * @return array<string, array{ok: bool, state: string}> key name => outcome (no key material)
     */
    public static function verify(): array
    {
        $results = [];

        foreach (self::NAMES as $name) {
            $results[$name] = DB::transaction(fn () => self::verifyOne($name));
        }

        return $results;
    }

    /** Records a pending fingerprint for a key about to replace the active one. */
    public static function addPending(string $name, string $newKey): string
    {
        $fingerprint = self::fingerprint($name, $newKey);
        $stored = self::stored($name);

        if ($stored['active'] === $fingerprint) {
            throw new RuntimeException('The new key is the same as the active key.');
        }

        if ($stored['pending'] !== null && $stored['pending'] !== $fingerprint) {
            throw new RuntimeException("Another {$name} rotation is pending. Finish or cancel it first.");
        }

        if ($stored['pending'] === null) {
            DB::table('key_fingerprints')->insert(['key_name' => $name, 'fingerprint' => $fingerprint, 'status' => 'pending', 'created_at' => now()]);
            AuditLog::event('key_rotation_prepared', "A new {$name} was prepared (fingerprint ".substr($fingerprint, 0, 12).'…).', properties: ['key' => $name, 'fingerprint' => substr($fingerprint, 0, 12)]);
        }

        return $fingerprint;
    }

    /**
     * @return array{ok: bool, state: string}
     */
    private static function verifyOne(string $name): array
    {
        $running = self::fingerprint($name, self::runningKey($name));
        $rows = DB::table('key_fingerprints')->where('key_name', $name)->whereIn('status', ['active', 'pending'])->lockForUpdate()->get()->keyBy('status');
        $active = $rows->get('active');
        $pending = $rows->get('pending');

        if ($active === null) {
            if ($pending !== null) {
                return ['ok' => false, 'state' => 'no active key is recorded but a rotation is pending'];
            }

            DB::table('key_fingerprints')->insert(['key_name' => $name, 'fingerprint' => $running, 'status' => 'active', 'created_at' => now(), 'activated_at' => now()]);
            AuditLog::event('key_registered', "The {$name} fingerprint was recorded (fingerprint ".substr($running, 0, 12).'…).', properties: ['key' => $name, 'fingerprint' => substr($running, 0, 12)]);

            return ['ok' => true, 'state' => 'registered'];
        }

        if ($active->fingerprint === $running) {
            return ['ok' => true, 'state' => 'active'];
        }

        if ($pending !== null && $pending->fingerprint === $running) {
            if (! self::rotationProven($name, (string) $active->fingerprint, $running)) {
                return ['ok' => false, 'state' => $name === 'app_key'
                    ? 'the running key is the pending one, but the active key is not in APP_PREVIOUS_KEYS'
                    : 'the running key is the pending one, but its re-encryption has not completed'];
            }

            $now = CarbonImmutable::now();
            DB::table('key_fingerprints')->where('id', $active->id)->update(['status' => 'retired', 'retired_at' => $now]);
            DB::table('key_fingerprints')->where('id', $pending->id)->update(['status' => 'active', 'activated_at' => $now]);
            AuditLog::event('key_rotated', "The {$name} rotation was completed (fingerprint ".substr($running, 0, 12).'…).', properties: ['key' => $name, 'fingerprint' => substr($running, 0, 12)]);

            return ['ok' => true, 'state' => 'promoted'];
        }

        return ['ok' => false, 'state' => 'the running key does not match the active fingerprint'];
    }

    private static function rotationProven(string $name, string $activeFingerprint, string $runningFingerprint): bool
    {
        if ($name === 'app_key') {
            $previous = array_filter(array_map('trim', (array) config('app.previous_keys')));

            return collect($previous)->contains(fn (string $key) => self::fingerprint('app_key', $key) === $activeFingerprint);
        }

        return DB::table('key_rotations')->where('key_name', $name)->where('from_fingerprint', $activeFingerprint)
            ->where('to_fingerprint', $runningFingerprint)->where('status', 'completed')->exists();
    }
}
