<?php

namespace App\Console\Commands;

use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Prints the fingerprint of a key read from standard input (Phase 8A.3),
 * so a backup copy of a key can be checked against the database without
 * revealing it:
 *
 *     php artisan buffalo:keys:fingerprint ciphersweet_key < /media/usb/ciphersweet_key
 *
 * The key is never printed or logged; only its fingerprint and whether it
 * matches the active, pending or a retired key.
 */
class FingerprintKey extends Command
{
    protected $signature = 'buffalo:keys:fingerprint {key : app_key or ciphersweet_key}';

    protected $description = 'Print the fingerprint of a key read from standard input and compare it with the database';

    public function handle(): int
    {
        $name = (string) $this->argument('key');

        if (! in_array($name, KeyFingerprints::NAMES, true)) {
            $this->components->error('The key must be app_key or ciphersweet_key.');

            return self::INVALID;
        }

        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $key = trim((string) stream_get_contents($stream ?? STDIN));

        if ($key === '') {
            $this->components->error('No key on standard input.');

            return self::INVALID;
        }

        $fingerprint = KeyFingerprints::fingerprint($name, $key);
        $this->line($fingerprint);

        $status = DB::table('key_fingerprints')->where('key_name', $name)->where('fingerprint', $fingerprint)->value('status');
        $this->components->info(match ($status) {
            'active' => 'This is the active key.',
            'pending' => 'This is the pending key of a rotation in progress.',
            'retired' => 'This is a retired key (still needed to restore backups made before it was retired).',
            default => 'This key is not known to this database.',
        });

        return $status === null ? self::FAILURE : self::SUCCESS;
    }
}
