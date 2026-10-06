<?php

namespace App\Console\Commands;

use App\Support\Config\Secrets;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refuses unsafe secrets (Phase 8A). Run at container start in production
 * (docker/php/entrypoint.sh) and in CI with a production-like environment.
 *
 * Fails when a required secret is missing, still equals a development
 * default (any value from .env.example, or a known development value), or
 * is too short. Prints secret names and the problem only, never values.
 *
 * | Secret                          | Required                        | Rule                         |
 * |---------------------------------|---------------------------------|------------------------------|
 * | APP_KEY                         | always                          | base64: 32 random bytes      |
 * | APP_PREVIOUS_KEYS               | if set                          | each base64: 32 bytes        |
 * | CIPHERSWEET_KEY                 | always                          | 64 hex characters            |
 * | DB_PASSWORD, REDIS_PASSWORD     | always                          | 16+ characters               |
 * | REVERB_APP_KEY / _SECRET        | always                          | 16+ / 32+ characters         |
 * | DB_MIGRATIONS_PASSWORD          | if set (migration containers)   | 16+ characters               |
 * | DB_READONLY_PASSWORD            | if set                          | 16+ characters               |
 * | MAIL_PASSWORD                   | if MAIL_USERNAME is set         | 12+ characters               |
 * | BUFFALO_HEALTH_TOKEN            | if set (/health/detailed)       | 32+ characters               |
 *
 * DB_ADMIN_PASSWORD (the superuser) should not reach application
 * containers in production; it is reported as a warning.
 */
class CheckSecrets extends Command
{
    protected $signature = 'buffalo:secrets:check';

    protected $description = 'Check that every secret is set, is not a development default and is long enough (prints names only)';

    /** Development values that must never be used in production. */
    private const KNOWN_DEFAULTS = [
        'buffalo', 'buffalo-app-dev', 'buffalo-owner-dev', 'buffalo-readonly-dev', 'buffalo-dev-key',
        'buffalo-dev-secret', 'buffalo-valkey-dev', 'buffalo-valkey-admin-dev', 'password', 'secret', 'changeme', 'null',
    ];

    public function handle(): int
    {
        try {
            $problems = $this->problems();
        } catch (Throwable $e) {
            // An unreadable *_FILE: the message names the secret only.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (Secrets::source('DB_ADMIN_PASSWORD') !== 'missing') {
            $this->components->warn('DB_ADMIN_PASSWORD is available to this container. The superuser password belongs only where roles are provisioned.');
        }

        if ($problems !== []) {
            foreach ($problems as $name => $problem) {
                $this->components->error("{$name}: {$problem}");
            }
            $this->components->error(count($problems).' secret problem(s). Supply secrets as environment variables or as NAME_FILE=/run/secrets/<name>.');

            return self::FAILURE;
        }

        $this->components->info('All secrets are set and none is a development default.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> secret name => problem (never the value)
     */
    private function problems(): array
    {
        $defaults = $this->developmentDefaults();
        $rules = [
            'APP_KEY' => ['required' => true, 'check' => fn (string $v) => self::isAppKey($v) ? null : 'must be "base64:" followed by 32 random bytes (php artisan key:generate --show)'],
            'CIPHERSWEET_KEY' => ['required' => true, 'check' => fn (string $v) => preg_match('/^[0-9a-f]{64}$/i', $v) === 1 ? null : 'must be 64 hexadecimal characters (32 random bytes)'],
            'DB_PASSWORD' => ['required' => true, 'check' => self::minLength(16)],
            'REDIS_PASSWORD' => ['required' => true, 'check' => self::minLength(16)],
            'REVERB_APP_KEY' => ['required' => true, 'check' => self::minLength(16)],
            'REVERB_APP_SECRET' => ['required' => true, 'check' => self::minLength(32)],
            'APP_PREVIOUS_KEYS' => ['required' => false, 'check' => fn (string $v) => collect(explode(',', $v))->every(fn (string $k) => self::isAppKey(trim($k))) ? null : 'each previous key must be "base64:" followed by 32 bytes'],
            'DB_MIGRATIONS_PASSWORD' => ['required' => false, 'check' => self::minLength(16)],
            'DB_READONLY_PASSWORD' => ['required' => false, 'check' => self::minLength(16)],
            'MAIL_PASSWORD' => ['required' => Secrets::get('MAIL_USERNAME') !== null, 'check' => self::minLength(12)],
            'BUFFALO_HEALTH_TOKEN' => ['required' => false, 'check' => self::minLength(32)],
        ];

        $problems = [];

        foreach ($rules as $name => $rule) {
            $value = Secrets::get($name);

            if ($value === null) {
                if ($rule['required']) {
                    $problems[$name] = 'is missing';
                }

                continue;
            }

            if (in_array($value, $defaults, true) || in_array(strtolower($value), self::KNOWN_DEFAULTS, true)) {
                $problems[$name] = 'still has a development default value';

                continue;
            }

            if (($problem = $rule['check']($value)) !== null) {
                $problems[$name] = $problem;
            }
        }

        $app = Secrets::get('APP_KEY');
        foreach (array_filter(explode(',', (string) Secrets::get('APP_PREVIOUS_KEYS'))) as $previous) {
            if ($app !== null && trim($previous) === $app) {
                $problems['APP_PREVIOUS_KEYS'] = 'contains the current APP_KEY';
            }
        }

        return $problems;
    }

    /**
     * Non-empty values in .env.example (development defaults), if the file
     * ships with the image.
     *
     * @return list<string>
     */
    private function developmentDefaults(): array
    {
        $path = base_path('.env.example');

        if (! is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*"?([^"#]*?)"?\s*$/', $line, $m) === 1 && $m[2] !== '' && strtolower($m[2]) !== 'null') {
                $values[] = $m[2];
            }
        }

        return array_values(array_unique($values));
    }

    private static function isAppKey(string $value): bool
    {
        if (! str_starts_with($value, 'base64:')) {
            return false;
        }

        $decoded = base64_decode(substr($value, 7), true);

        return $decoded !== false && strlen($decoded) === 32;
    }

    /**
     * @return callable(string): ?string
     */
    private static function minLength(int $length): callable
    {
        return fn (string $value) => mb_strlen($value) >= $length ? null : "is too short (at least {$length} characters)";
    }
}
