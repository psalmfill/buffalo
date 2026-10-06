<?php

namespace App\Support\Config;

use Illuminate\Support\Env;
use RuntimeException;

/**
 * Secrets from Docker secrets or environment variables (Phase 8A).
 *
 * Each secret NAME can be supplied either as NAME (an environment variable
 * or `.env` entry) or as NAME_FILE, the path of a file holding the value,
 * for example a Docker secret mounted at /run/secrets/<name>. The file
 * wins when both are set. Trailing newlines are removed. Values are read
 * once per process and are never logged; errors name the secret only.
 *
 * Config files call Secrets::get() instead of env() for every secret, so
 * `config:cache` resolves files too.
 */
final class Secrets
{
    /** Every secret BUFFALO reads, checked by buffalo:secrets:check. */
    public const NAMES = [
        'APP_KEY',
        'APP_PREVIOUS_KEYS',
        'CIPHERSWEET_KEY',
        'DB_PASSWORD',
        'DB_MIGRATIONS_PASSWORD',
        'DB_READONLY_PASSWORD',
        'DB_ADMIN_PASSWORD',
        'REDIS_PASSWORD',
        'REVERB_APP_KEY',
        'REVERB_APP_SECRET',
        'MAIL_USERNAME',
        'MAIL_PASSWORD',
        'BUFFALO_HEALTH_TOKEN',
    ];

    /** @var array<string, string|null> */
    private static array $values = [];

    public static function get(string $name, ?string $default = null): ?string
    {
        if (! array_key_exists($name, self::$values)) {
            self::$values[$name] = self::read($name);
        }

        return self::$values[$name] ?? $default;
    }

    /** Where a secret comes from: `file`, `env` or `missing`. */
    public static function source(string $name): string
    {
        return match (true) {
            self::filePath($name) !== null => 'file',
            self::fromEnv($name) !== null => 'env',
            default => 'missing',
        };
    }

    /** Forgets cached values (tests). */
    public static function flush(): void
    {
        self::$values = [];
    }

    private static function read(string $name): ?string
    {
        $file = self::filePath($name);

        if ($file === null) {
            return self::fromEnv($name);
        }

        if (! is_file($file) || ! is_readable($file)) {
            throw new RuntimeException("The secret file for {$name} ({$name}_FILE) cannot be read.");
        }

        $value = rtrim((string) file_get_contents($file), "\r\n");

        return $value === '' ? null : $value;
    }

    private static function filePath(string $name): ?string
    {
        $file = Env::get($name.'_FILE');

        return is_string($file) && trim($file) !== '' ? trim($file) : null;
    }

    private static function fromEnv(string $name): ?string
    {
        $value = Env::get($name);

        // `.env` placeholders such as MAIL_PASSWORD=null mean "not set".
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (string) $value;
    }
}
