<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Replaces spatie/laravel-ciphersweet's `ciphersweet:generate-key` (bound in
 * AppServiceProvider), which silently overwrites an existing key outside
 * production. Use `buffalo:encryption-key`, which only creates a key for a
 * new installation.
 */
class DisabledCipherSweetGenerateKeyCommand extends Command
{
    protected $signature = 'ciphersweet:generate-key {--show : Ignored} {--base64 : Ignored} {--force : Ignored}';

    protected $description = 'Disabled: use buffalo:encryption-key';

    protected $hidden = true;

    public function handle(): int
    {
        $this->components->error('ciphersweet:generate-key is disabled: it can overwrite the key of an existing database. Use `php artisan buffalo:encryption-key` for a new installation; see docs/security/encryption-keys.md.');

        return self::FAILURE;
    }
}
