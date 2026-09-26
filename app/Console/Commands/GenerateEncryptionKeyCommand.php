<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ParagonIE\ConstantTime\Hex;

/**
 * Creates the field encryption key (CIPHERSWEET_KEY) in .env for a new
 * installation. Refuses when a key already exists: replacing the key of an
 * existing database makes every encrypted person field unrecoverable.
 * Key rotation and backup procedures are Phase 8.
 */
class GenerateEncryptionKeyCommand extends Command
{
    protected $signature = 'buffalo:encryption-key';

    protected $description = 'Create the field encryption key (CIPHERSWEET_KEY) for a new installation';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        if (filled(config('ciphersweet.providers.string.key')) || preg_match('/^CIPHERSWEET_KEY=\S+/m', $contents) === 1) {
            $this->components->error('A field encryption key already exists. It was not changed: replacing it would make all encrypted person data unrecoverable.');

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $this->components->error("No environment file at {$path}.");

            return self::FAILURE;
        }

        $line = 'CIPHERSWEET_KEY='.Hex::encode(random_bytes(32));
        $updated = preg_match('/^CIPHERSWEET_KEY=$/m', $contents) === 1
            ? (string) preg_replace('/^CIPHERSWEET_KEY=$/m', $line, $contents, 1)
            : rtrim($contents, "\n")."\n\n{$line}\n";

        file_put_contents($path, $updated);

        $this->components->info('Field encryption key created in .env. Back it up separately from the database now (docs/security/encryption-keys.md).');

        return self::SUCCESS;
    }
}
