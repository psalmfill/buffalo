<?php

use App\Support\Config\Secrets;
use Illuminate\Support\Facades\Artisan;

/*
| Phase 8A.2: secrets from environment variables or NAME_FILE (Docker
| secrets), and buffalo:secrets:check.
*/

/** Sets (or with null, removes) environment variables for one test. */
function withEnv(array $values): void
{
    foreach ($values as $name => $value) {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
    Secrets::flush();
}

/** Writes a secret file (as a Docker secret would be mounted). */
function secretFile(string $value): string
{
    $path = tempnam(sys_get_temp_dir(), 'secret');
    file_put_contents($path, $value."\n");

    return $path;
}

/** @return array<string, string> strong production values */
function strongSecrets(): array
{
    return [
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
        'DB_PASSWORD' => bin2hex(random_bytes(16)),
        'REDIS_PASSWORD' => bin2hex(random_bytes(16)),
        'REVERB_APP_KEY' => bin2hex(random_bytes(10)),
        'REVERB_APP_SECRET' => bin2hex(random_bytes(20)),
    ];
}

afterEach(function () {
    $names = [];
    foreach (Secrets::NAMES as $name) {
        $names[$name.'_FILE'] = null;
    }
    withEnv([...$names, ...($this->restore ?? [])]);
});

test('a secret file wins over the environment variable, without its trailing newline', function () {
    $this->restore = ['REVERB_APP_SECRET' => env('REVERB_APP_SECRET')];
    withEnv(['REVERB_APP_SECRET' => 'from-environment', 'REVERB_APP_SECRET_FILE' => secretFile('from-file')]);

    expect(Secrets::get('REVERB_APP_SECRET'))->toBe('from-file')
        ->and(Secrets::source('REVERB_APP_SECRET'))->toBe('file');

    withEnv(['REVERB_APP_SECRET_FILE' => null]);
    expect(Secrets::get('REVERB_APP_SECRET'))->toBe('from-environment')
        ->and(Secrets::source('REVERB_APP_SECRET'))->toBe('env');
});

test('an unreadable secret file fails with the secret name only', function () {
    withEnv(['DB_PASSWORD_FILE' => '/run/secrets/does-not-exist']);

    expect(fn () => Secrets::get('DB_PASSWORD'))->toThrow(RuntimeException::class, 'The secret file for DB_PASSWORD (DB_PASSWORD_FILE) cannot be read.');
});

test('secrets:check fails on development defaults and prints names, never values', function () {
    // The development .env: role passwords, Valkey and Reverb are .env.example defaults.
    Secrets::flush();
    $exit = Artisan::call('buffalo:secrets:check');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('DB_PASSWORD: still has a development default value')
        ->and($output)->toContain('REDIS_PASSWORD: still has a development default value')
        ->and($output)->toContain('REVERB_APP_SECRET')
        ->and($output)->not->toContain('buffalo-app-dev')
        ->and($output)->not->toContain('buffalo-valkey-dev')
        ->and($output)->not->toContain((string) env('APP_KEY'));
});

test('secrets:check passes with strong secrets supplied as files, and rejects short or malformed ones', function () {
    $files = [];
    foreach (strongSecrets() as $name => $value) {
        $files[$name.'_FILE'] = secretFile($value);
    }
    $this->restore = ['MAIL_USERNAME' => env('MAIL_USERNAME'), 'DB_MIGRATIONS_PASSWORD' => env('DB_MIGRATIONS_PASSWORD'), 'DB_READONLY_PASSWORD' => env('DB_READONLY_PASSWORD'), 'DB_PASSWORD' => env('DB_PASSWORD')];
    // Optional secrets not given to this container.
    withEnv([...$files, 'MAIL_USERNAME' => null, 'DB_MIGRATIONS_PASSWORD' => null, 'DB_READONLY_PASSWORD' => null]);

    expect(Artisan::call('buffalo:secrets:check'))->toBe(0)
        ->and(Artisan::output())->toContain('All secrets are set');

    withEnv(['DB_PASSWORD_FILE' => secretFile('short'), 'CIPHERSWEET_KEY_FILE' => secretFile('not-hex'), 'APP_KEY_FILE' => secretFile('base64:'.base64_encode('16-bytes-only!!!'))]);
    expect(Artisan::call('buffalo:secrets:check'))->toBe(1);
    expect(Artisan::output())->toContain('DB_PASSWORD: is too short')
        ->toContain('CIPHERSWEET_KEY: must be 64 hexadecimal characters')
        ->toContain('APP_KEY: must be "base64:" followed by 32 random bytes')
        ->not->toContain('not-hex');

    withEnv(['DB_PASSWORD_FILE' => null, 'DB_PASSWORD' => null]);
    expect(Artisan::call('buffalo:secrets:check'))->toBe(1)
        ->and(Artisan::output())->toContain('DB_PASSWORD: is missing');
});
