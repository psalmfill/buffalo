<?php

namespace App\Console\Commands;

use App\Support\Keys\KeyFingerprints;
use Illuminate\Console\Command;
use Throwable;

/**
 * Verifies APP_KEY and CIPHERSWEET_KEY against the active fingerprints
 * (Phase 8A.3). Run at container start in production
 * (docker/php/entrypoint.sh): a mismatch stops the container, so data is
 * never written with the wrong key (for example after restoring a database
 * with another installation's keys). Records the fingerprints on a new
 * installation and completes a proven rotation.
 */
class VerifyKeys extends Command
{
    protected $signature = 'buffalo:keys:verify';

    protected $description = 'Check that the running encryption keys match the database (fingerprints only)';

    public function handle(): int
    {
        try {
            $results = KeyFingerprints::verify();
        } catch (Throwable $e) {
            $this->components->error('Key verification failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Key', 'Result'], array_map(
            fn (string $name, array $result) => [$name, ($result['ok'] ? 'OK: ' : 'MISMATCH: ').$result['state']],
            array_keys($results),
            $results,
        ));

        if (collect($results)->contains(fn (array $r) => ! $r['ok'])) {
            $this->components->error('The running keys do not match this database. BUFFALO must not start: check the APP_KEY and CIPHERSWEET_KEY secrets (docs/security/key-management.md).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
