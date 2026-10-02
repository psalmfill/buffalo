<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ParagonIE\ConstantTime\Hex;

/**
 * Creates the field encryption key (CIPHERSWEET_KEY) in .env for a new
 * installation. Refuses when a key already exists: replacing the key of an
 * existing database makes every encrypted person field unrecoverable.
 *
 * Phase 8A: `--output=FILE` writes a new key to a new file (mode 0600) for
 * a Docker secret or a rotation (buffalo:keys:rotate-ciphersweet), and
 * `--show` prints one; neither touches .env. Rotation and escrow:
 * docs/security/key-management.md.
 */
class GenerateEncryptionKeyCommand extends Command
{
    protected $signature = 'buffalo:encryption-key
        {--output= : Write a new key to this new file (mode 0600) instead of .env}
        {--show : Print a new key instead of writing .env}';

    protected $description = 'Create the field encryption key (CIPHERSWEET_KEY) for a new installation';

    public function handle(): int
    {
        if ($this->option('show')) {
            $this->line(Hex::encode(random_bytes(32)));

            return self::SUCCESS;
        }

        if (($output = (string) $this->option('output')) !== '') {
            if (file_exists($output)) {
                $this->components->error("{$output} already exists. Choose a new file.");

                return self::FAILURE;
            }

            $previous = umask(0077);
            file_put_contents($output, Hex::encode(random_bytes(32))."\n");
            umask($previous);
            chmod($output, 0600);
            $this->components->info("New field encryption key written to {$output}.");

            return self::SUCCESS;
        }

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

        $this->components->info('Field encryption key created in .env. Back it up separately from the database now (docs/security/key-management.md).');

        return self::SUCCESS;
    }
}
